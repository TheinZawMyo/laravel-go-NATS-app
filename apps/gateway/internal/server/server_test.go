package server

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/coder/websocket"
	"github.com/pion/webrtc/v4"

	"chatapp/gateway/internal/config"
	"chatapp/gateway/internal/protocol"
)

const testSecret = "test-gateway-secret-123"

type fakeAuthorizer struct {
	active    bool
	access    map[string]bool
	lookupErr error
}

func (a *fakeAuthorizer) UserIsActive(context.Context, string) (bool, error) {
	return a.active, nil
}

func (a *fakeAuthorizer) CanAccessConversation(_ context.Context, _, conversationID string) (bool, error) {
	if a.lookupErr != nil {
		return false, a.lookupErr
	}

	return a.access[conversationID], nil
}

func testConfig() config.Config {
	return config.Config{
		InstanceID:            "gateway-test",
		Addr:                  ":0",
		AllowedOrigins:        []string{"http://localhost:8000"},
		TokenSecret:           testSecret,
		HeartbeatInterval:     5 * time.Second,
		AuthTimeout:           500 * time.Millisecond,
		WriteTimeout:          time.Second,
		ShutdownTimeout:       time.Second,
		OutboundBuffer:        16,
		MaxSubscriptions:      10,
		MaxFrameBytes:         64 * 1024,
		MaxConnections:        100,
		MaxConnectionsPerUser: 10,
		InboundRatePerSecond:  1000,
		InboundBurst:          1000,
		EventSubjectPrefix:    "chat.events.",
		TypingSubjectPrefix:   "chat.typing.",
		CallSubjectPrefix:     "chat.calls.",
		CallICEServers:        "[]",
		PresenceSubjectPrefix: "chat.presence.",
		ReceiptSubjectPrefix:  "chat.receipts.",
		PresenceSubjectTTL:    90 * time.Second,
		TypingTTL:             6 * time.Second,
		EventDedupeTTL:        time.Minute,
		EventDedupeLimit:      1000,
	}
}

func testServer(t *testing.T, authorizer *fakeAuthorizer) (*Server, *httptest.Server) {
	t.Helper()

	return testServerWith(t, testConfig(), authorizer)
}

func testServerWith(t *testing.T, cfg config.Config, authorizer *fakeAuthorizer) (*Server, *httptest.Server) {
	t.Helper()

	server := newServer(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)), dependencies{Authorizer: authorizer})
	httpServer := httptest.NewServer(server.http.Handler)

	t.Cleanup(func() {
		httpServer.Close()
		server.Close()
	})

	return server, httpServer
}

func signToken(t *testing.T, secret, userID string, expiresAt time.Time) string {
	t.Helper()

	payload, err := json.Marshal(map[string]any{
		"sub": userID,
		"aud": "gateway",
		"iat": time.Now().Add(-time.Minute).Unix(),
		"exp": expiresAt.Unix(),
		"jti": "test-token",
	})
	if err != nil {
		t.Fatalf("marshal token: %v", err)
	}

	encoded := base64.RawURLEncoding.EncodeToString(payload)
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(encoded))

	return encoded + "." + base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}

func websocketURL(httpServer *httptest.Server) string {
	return "ws" + strings.TrimPrefix(httpServer.URL, "http") + "/ws"
}

func dial(t *testing.T, httpServer *httptest.Server, origin string) *websocket.Conn {
	t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	conn, _, err := websocket.Dial(ctx, websocketURL(httpServer), &websocket.DialOptions{
		HTTPHeader: map[string][]string{"Origin": {origin}},
	})
	if err != nil {
		t.Fatalf("dial websocket: %v", err)
	}

	t.Cleanup(func() { _ = conn.CloseNow() })

	return conn
}

func send(t *testing.T, conn *websocket.Conn, message any) {
	t.Helper()

	payload, err := json.Marshal(message)
	if err != nil {
		t.Fatalf("marshal client message: %v", err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()

	if err := conn.Write(ctx, websocket.MessageText, payload); err != nil {
		t.Fatalf("write client message: %v", err)
	}
}

func readEnvelope(t *testing.T, conn *websocket.Conn) protocol.Envelope {
	t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	_, payload, err := conn.Read(ctx)
	if err != nil {
		t.Fatalf("read server message: %v", err)
	}

	var envelope protocol.Envelope
	if err := json.Unmarshal(payload, &envelope); err != nil {
		t.Fatalf("unmarshal server envelope: %v", err)
	}

	return envelope
}

func authenticate(t *testing.T, conn *websocket.Conn, token string) protocol.Envelope {
	t.Helper()

	send(t, conn, map[string]any{
		"type": protocol.ClientAuthenticate,
		"data": map[string]string{"token": token},
	})

	envelope := readEnvelope(t, conn)
	if envelope.Type != protocol.ServerReady {
		t.Fatalf("expected connection.ready, got %s: %s", envelope.Type, envelope.Data)
	}

	return envelope
}

func waitForSubscription(t *testing.T, server *Server, conversationID string) {
	t.Helper()

	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		clients := server.clients.All()
		for _, client := range clients {
			if server.clients.IsSubscribed(client, conversationID) {
				return
			}
		}

		time.Sleep(5 * time.Millisecond)
	}

	t.Fatal("subscription was not registered")
}

// subscribeAndAck sends conversation.subscribe and consumes the
// conversation.subscribed acknowledgement the gateway always replies with.
func subscribeAndAck(t *testing.T, conn *websocket.Conn, server *Server, conversationID string, lastSeq *int64) protocol.Envelope {
	t.Helper()

	data := map[string]any{"conversation_id": conversationID}
	if lastSeq != nil {
		data["last_seq"] = *lastSeq
	}

	send(t, conn, map[string]any{
		"type": protocol.ClientSubscribe,
		"data": data,
	})

	waitForSubscription(t, server, conversationID)

	envelope := readEnvelope(t, conn)
	if envelope.Type != protocol.ServerSubscribed {
		t.Fatalf("expected conversation.subscribed, got %s: %s", envelope.Type, envelope.Data)
	}

	return envelope
}

// consumeEnvelopeOfType reads envelopes until one matches the wanted type. It
// keeps assertions readable when a subscription also emits presence snapshots.
func consumeEnvelopeOfType(t *testing.T, conn *websocket.Conn, wanted string) protocol.Envelope {
	t.Helper()

	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		envelope := readEnvelope(t, conn)
		if envelope.Type == wanted {
			return envelope
		}
	}

	t.Fatalf("no %s event received", wanted)

	return protocol.Envelope{}
}

func TestConnectionAuthenticatesSubscribesAndReceivesEvents(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"

	server, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{conversationID: true},
	})

	conn := dial(t, httpServer, "http://localhost:8000")
	ready := authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Minute)))

	var readyData protocol.ReadyData
	if err := json.Unmarshal(ready.Data, &readyData); err != nil {
		t.Fatalf("decode ready data: %v", err)
	}

	if readyData.UserID != "user-1" || readyData.Version != protocol.Version {
		t.Fatalf("unexpected ready data: %+v", readyData)
	}

	acked := subscribeAndAck(t, conn, server, conversationID, nil)

	var subscribedData protocol.SubscribedData
	if err := json.Unmarshal(acked.Data, &subscribedData); err != nil {
		t.Fatalf("decode subscribed data: %v", err)
	}

	if subscribedData.ConversationID != conversationID {
		t.Fatalf("unexpected subscribed data: %+v", subscribedData)
	}

	envelope, err := protocol.NewEnvelope(protocol.ServerMessageCreated, &conversationID, nil, map[string]string{"message_id": "message-1"})
	if err != nil {
		t.Fatalf("build event: %v", err)
	}
	raw, err := json.Marshal(envelope)
	if err != nil {
		t.Fatalf("marshal event: %v", err)
	}

	server.deliver(server.clients.Subscribers(conversationID), raw, "test")

	received := readEnvelope(t, conn)
	if received.Type != protocol.ServerMessageCreated || received.EventID != envelope.EventID {
		t.Fatalf("unexpected event: %+v", received)
	}

	send(t, conn, map[string]any{"type": protocol.ClientPing, "id": "ping-1"})
	pong := readEnvelope(t, conn)

	if pong.Type != protocol.ServerPong {
		t.Fatalf("expected pong, got %s", pong.Type)
	}
}

func TestCallSignalRingsTargetUserWithoutConversationSubscription(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"
	callerID := "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
	receiverID := "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"
	callID := "cccccccc-cccc-4ccc-8ccc-cccccccccccc"

	_, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{conversationID: true},
	})

	caller := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, caller, signToken(t, testSecret, callerID, time.Now().Add(time.Minute)))
	receiver := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, receiver, signToken(t, testSecret, receiverID, time.Now().Add(time.Minute)))

	callerPeer, offer := pionOffer(t, false)
	defer callerPeer.Close()

	send(t, caller, map[string]any{
		"type": protocol.ClientCallSignal,
		"id":   "call-request-1",
		"data": map[string]any{
			"conversation_id": conversationID,
			"to_user_id":      receiverID,
			"call_id":         callID,
			"signal":          map[string]any{"kind": "offer", "media": map[string]bool{"video": false}, "description": offer},
		},
	})

	callerEnvelope := readCallSignalKind(t, caller, "answer")
	if callerEnvelope.Type != protocol.ServerCallSignal || callerEnvelope.ConversationID == nil || *callerEnvelope.ConversationID != conversationID {
		t.Fatalf("expected Pion answer for the caller, got %+v", callerEnvelope)
	}
	var data protocol.CallSignalData
	if err := json.Unmarshal(callerEnvelope.Data, &data); err != nil {
		t.Fatalf("decode caller answer: %v", err)
	}
	if data.FromUserID != receiverID || data.ToUserID != callerID || data.CallID != callID {
		t.Fatalf("unexpected Pion answer routing data: %+v", data)
	}
	var answerSignal struct {
		Kind        string                    `json:"kind"`
		Description webrtc.SessionDescription `json:"description"`
	}
	if err := json.Unmarshal(data.Signal, &answerSignal); err != nil || answerSignal.Kind != "answer" || answerSignal.Description.Type != webrtc.SDPTypeAnswer {
		t.Fatalf("unexpected Pion answer payload: %s (%v)", data.Signal, err)
	}

	calleeEnvelope := readCallSignalKind(t, receiver, "offer")
	if calleeEnvelope.Type != protocol.ServerCallSignal {
		t.Fatalf("expected Pion offer to the callee, got %s", calleeEnvelope.Type)
	}
	if err := json.Unmarshal(calleeEnvelope.Data, &data); err != nil {
		t.Fatalf("decode callee offer: %v", err)
	}
	var calleeSignal struct {
		Kind        string                    `json:"kind"`
		Description webrtc.SessionDescription `json:"description"`
	}
	if err := json.Unmarshal(data.Signal, &calleeSignal); err != nil || calleeSignal.Kind != "offer" || calleeSignal.Description.Type != webrtc.SDPTypeOffer {
		t.Fatalf("unexpected Pion callee offer payload: %s (%v)", data.Signal, err)
	}

	// The receiver can answer without subscribing to message delivery.
	calleePeer, err := webrtc.NewPeerConnection(webrtc.Configuration{})
	if err != nil {
		t.Fatalf("create callee peer: %v", err)
	}
	defer calleePeer.Close()
	if _, err := calleePeer.AddTransceiverFromKind(webrtc.RTPCodecTypeAudio, webrtc.RTPTransceiverInit{Direction: webrtc.RTPTransceiverDirectionSendrecv}); err != nil {
		t.Fatalf("add callee audio transceiver: %v", err)
	}
	if err := calleePeer.SetRemoteDescription(calleeSignal.Description); err != nil {
		t.Fatalf("set callee remote offer: %v", err)
	}
	answer, err := calleePeer.CreateAnswer(nil)
	if err != nil {
		t.Fatalf("create callee answer: %v", err)
	}
	gathered := webrtc.GatheringCompletePromise(calleePeer)
	if err := calleePeer.SetLocalDescription(answer); err != nil {
		t.Fatalf("set callee local answer: %v", err)
	}
	select {
	case <-gathered:
	case <-time.After(3 * time.Second):
		t.Fatal("callee ICE gathering timed out")
	}
	send(t, receiver, map[string]any{
		"type": protocol.ClientCallSignal,
		"data": map[string]any{
			"conversation_id": conversationID,
			"to_user_id":      callerID,
			"call_id":         callID,
			"signal":          map[string]any{"kind": "answer", "description": calleePeer.LocalDescription()},
		},
	})
	acceptedEnvelope := readCallSignalKind(t, caller, "accepted")
	if err := json.Unmarshal(acceptedEnvelope.Data, &data); err != nil {
		t.Fatalf("decode call accepted notification: %v", err)
	}
	if data.FromUserID != receiverID || data.ToUserID != callerID || data.CallID != callID {
		t.Fatalf("unexpected call accepted routing: %+v", data)
	}

	// Ending from the receiving browser must notify the original caller and
	// close the gateway relay for both users.
	send(t, receiver, map[string]any{
		"type": protocol.ClientCallSignal,
		"data": map[string]any{
			"conversation_id": conversationID,
			"to_user_id":      callerID,
			"call_id":         callID,
			"signal":          map[string]any{"kind": "hangup"},
		},
	})
	hangupEnvelope := readCallSignalKind(t, caller, "hangup")
	if err := json.Unmarshal(hangupEnvelope.Data, &data); err != nil {
		t.Fatalf("decode call hangup notification: %v", err)
	}
	if data.FromUserID != receiverID || data.ToUserID != callerID || data.CallID != callID {
		t.Fatalf("unexpected call hangup routing: %+v", data)
	}
	send(t, receiver, map[string]any{"type": protocol.ClientPing})
	if got := readEnvelopeType(t, receiver, protocol.ServerPong); got.Type != protocol.ServerPong {
		t.Fatalf("expected successful Pion answer and pong, got %s: %s", got.Type, got.Data)
	}
}

func readEnvelopeType(t *testing.T, conn *websocket.Conn, expected string) protocol.Envelope {
	t.Helper()
	for {
		envelope := readEnvelope(t, conn)
		if envelope.Type == expected {
			return envelope
		}
	}
}

func readCallSignalKind(t *testing.T, conn *websocket.Conn, kind string) protocol.Envelope {
	t.Helper()
	for {
		envelope := readEnvelope(t, conn)
		if envelope.Type != protocol.ServerCallSignal {
			continue
		}
		var data protocol.CallSignalData
		if err := json.Unmarshal(envelope.Data, &data); err != nil {
			t.Fatalf("decode call signal while waiting for %q: %v", kind, err)
		}
		var signal struct {
			Kind string `json:"kind"`
		}
		if err := json.Unmarshal(data.Signal, &signal); err != nil {
			t.Fatalf("decode call signal payload: %v", err)
		}
		if signal.Kind == kind {
			return envelope
		}
	}
}

func pionOffer(t *testing.T, video bool) (*webrtc.PeerConnection, webrtc.SessionDescription) {
	t.Helper()
	pc, err := webrtc.NewPeerConnection(webrtc.Configuration{})
	if err != nil {
		t.Fatalf("create Pion browser peer: %v", err)
	}
	if _, err := pc.AddTransceiverFromKind(webrtc.RTPCodecTypeAudio, webrtc.RTPTransceiverInit{Direction: webrtc.RTPTransceiverDirectionSendrecv}); err != nil {
		t.Fatalf("add audio transceiver: %v", err)
	}
	if video {
		if _, err := pc.AddTransceiverFromKind(webrtc.RTPCodecTypeVideo, webrtc.RTPTransceiverInit{Direction: webrtc.RTPTransceiverDirectionSendrecv}); err != nil {
			t.Fatalf("add video transceiver: %v", err)
		}
	}
	offer, err := pc.CreateOffer(nil)
	if err != nil {
		t.Fatalf("create Pion offer: %v", err)
	}
	gathered := webrtc.GatheringCompletePromise(pc)
	if err := pc.SetLocalDescription(offer); err != nil {
		t.Fatalf("set Pion local offer: %v", err)
	}
	select {
	case <-gathered:
	case <-time.After(3 * time.Second):
		t.Fatal("caller ICE gathering timed out")
	}
	return pc, *pc.LocalDescription()
}

func TestCallSignalNATSSubjectRoutesToTargetUserWithoutConversationSubscription(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"
	callerID := "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
	receiverID := "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"
	callID := "cccccccc-cccc-4ccc-8ccc-cccccccccccc"

	server, httpServer := testServer(t, &fakeAuthorizer{active: true})
	receiver := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, receiver, signToken(t, testSecret, receiverID, time.Now().Add(time.Minute)))

	envelope, err := protocol.NewEnvelope(protocol.ServerCallSignal, &conversationID, nil, protocol.CallSignalData{
		ConversationID: conversationID,
		FromUserID:     callerID,
		ToUserID:       receiverID,
		CallID:         callID,
		Signal:         json.RawMessage(`{"kind":"offer"}`),
	})
	if err != nil {
		t.Fatalf("build call signal: %v", err)
	}
	raw, err := json.Marshal(envelope)
	if err != nil {
		t.Fatalf("marshal call signal: %v", err)
	}

	server.handleBrokerMessage(server.cfg.CallSubjectPrefix+receiverID, raw)
	got := readEnvelope(t, receiver)
	if got.Type != protocol.ServerCallSignal {
		t.Fatalf("expected routed call.signal, got %s", got.Type)
	}
}

func TestSubscriptionsToUnauthorizedConversationsAreRejected(t *testing.T) {
	conversationID := "22222222-2222-4222-8222-222222222222"

	_, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{},
	})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Minute)))

	send(t, conn, map[string]any{
		"type": protocol.ClientSubscribe,
		"data": map[string]string{"conversation_id": conversationID},
	})

	envelope := readEnvelope(t, conn)
	if envelope.Type != protocol.ServerError {
		t.Fatalf("expected connection.error, got %s", envelope.Type)
	}
}

func TestInvalidTokensAreRejected(t *testing.T) {
	_, httpServer := testServer(t, &fakeAuthorizer{active: true, access: map[string]bool{}})

	conn := dial(t, httpServer, "http://localhost:8000")
	send(t, conn, map[string]any{
		"type": protocol.ClientAuthenticate,
		"data": map[string]string{"token": signToken(t, "wrong-secret-value", "user-1", time.Now().Add(time.Minute))},
	})

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	_, _, err := conn.Read(ctx)
	if err == nil {
		t.Fatal("expected the connection to be closed")
	}

	var closeError websocket.CloseError
	if !errors.As(err, &closeError) || closeError.Code != websocket.StatusPolicyViolation {
		t.Fatalf("expected policy violation close, got %v", err)
	}
}

func TestConnectionsMustAuthenticateBeforeTheTimeout(t *testing.T) {
	cfg := testConfig()
	cfg.AuthTimeout = 100 * time.Millisecond

	server := newServer(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)), dependencies{
		Authorizer: &fakeAuthorizer{active: true},
	})
	httpServer := httptest.NewServer(server.http.Handler)
	defer httpServer.Close()
	defer server.Close()

	conn := dial(t, httpServer, "http://localhost:8000")

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	_, _, err := conn.Read(ctx)
	if err == nil {
		t.Fatal("expected the connection to be closed")
	}

	var closeError websocket.CloseError
	if !errors.As(err, &closeError) || closeError.Code != websocket.StatusPolicyViolation {
		t.Fatalf("expected policy violation close, got %v", err)
	}
}

func mustEnvelope(t *testing.T, eventType string, conversationID *string, data any) []byte {
	t.Helper()

	envelope, err := protocol.NewEnvelope(eventType, conversationID, nil, data)
	if err != nil {
		t.Fatalf("build event: %v", err)
	}

	raw, err := json.Marshal(envelope)
	if err != nil {
		t.Fatalf("marshal event: %v", err)
	}

	return raw
}

func expectClose(t *testing.T, conn *websocket.Conn, status websocket.StatusCode) websocket.CloseError {
	t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	_, _, err := conn.Read(ctx)
	if err == nil {
		t.Fatal("expected the connection to be closed")
	}

	var closeError websocket.CloseError
	if !errors.As(err, &closeError) {
		t.Fatalf("expected a close error, got %v", err)
	}

	if closeError.Code != status {
		t.Fatalf("expected close %d, got %d (%s)", status, closeError.Code, closeError.Reason)
	}

	return closeError
}

func TestConnectionsCloseWhenTheirGatewayTokenExpires(t *testing.T) {
	_, httpServer := testServer(t, &fakeAuthorizer{active: true})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(2*time.Second)))

	closeError := expectClose(t, conn, websocket.StatusPolicyViolation)
	if closeError.Reason != "token expired" {
		t.Fatalf("unexpected close reason: %q", closeError.Reason)
	}
}

func TestConnectionsCanRenewTheirGatewayToken(t *testing.T) {
	_, httpServer := testServer(t, &fakeAuthorizer{active: true})

	conn := dial(t, httpServer, "http://localhost:8000")
	first := authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(2*time.Second)))

	renewed := authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	var firstReady, renewedReady protocol.ReadyData
	if err := json.Unmarshal(first.Data, &firstReady); err != nil {
		t.Fatalf("decode ready data: %v", err)
	}

	if err := json.Unmarshal(renewed.Data, &renewedReady); err != nil {
		t.Fatalf("decode renewed ready data: %v", err)
	}

	if renewedReady.UserID != "user-1" || renewedReady.ConnectionTokenExpiresAt == firstReady.ConnectionTokenExpiresAt {
		t.Fatalf("expected a renewed expiry, got %+v", renewedReady)
	}

	// The original credential has expired by now, but the renewed one must keep
	// the connection alive.
	time.Sleep(2100 * time.Millisecond)

	send(t, conn, map[string]any{"type": protocol.ClientPing, "id": "ping-1"})
	if pong := readEnvelope(t, conn); pong.Type != protocol.ServerPong {
		t.Fatalf("expected pong, got %s", pong.Type)
	}
}

func TestTokenRenewalForAnotherSubjectIsRejected(t *testing.T) {
	_, httpServer := testServer(t, &fakeAuthorizer{active: true})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	send(t, conn, map[string]any{
		"type": protocol.ClientAuthenticate,
		"data": map[string]string{"token": signToken(t, testSecret, "user-2", time.Now().Add(time.Hour))},
	})

	expectClose(t, conn, websocket.StatusPolicyViolation)
}

func TestOriginsOutsideTheAllowlistAreRejected(t *testing.T) {
	_, httpServer := testServer(t, &fakeAuthorizer{active: true})

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	_, response, err := websocket.Dial(ctx, websocketURL(httpServer), &websocket.DialOptions{
		HTTPHeader: map[string][]string{"Origin": {"http://evil.example"}},
	})
	if err == nil {
		t.Fatal("expected the origin to be rejected")
	}

	if response != nil && response.StatusCode != 403 {
		t.Fatalf("expected 403, got %d", response.StatusCode)
	}
}

func TestPerUserConnectionLimitIsEnforced(t *testing.T) {
	cfg := testConfig()
	cfg.MaxConnectionsPerUser = 1

	_, httpServer := testServerWith(t, cfg, &fakeAuthorizer{active: true})

	first := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, first, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	second := dial(t, httpServer, "http://localhost:8000")
	send(t, second, map[string]any{
		"type": protocol.ClientAuthenticate,
		"data": map[string]string{"token": signToken(t, testSecret, "user-1", time.Now().Add(time.Hour))},
	})

	closeError := expectClose(t, second, websocket.StatusPolicyViolation)
	if closeError.Reason != "connection limit reached" {
		t.Fatalf("unexpected close reason: %q", closeError.Reason)
	}

	// Another account is still allowed in.
	third := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, third, signToken(t, testSecret, "user-2", time.Now().Add(time.Hour)))
}

func TestInboundFloodsAreThrottledWithoutClosingTheConnection(t *testing.T) {
	cfg := testConfig()
	cfg.InboundRatePerSecond = 0.01
	cfg.InboundBurst = 1

	server, httpServer := testServerWith(t, cfg, &fakeAuthorizer{active: true})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	// Authentication consumed the burst, so these frames are dropped instead of
	// closing the socket.
	for index := 0; index < 5; index++ {
		send(t, conn, map[string]any{"type": protocol.ClientPing, "id": "flood"})
	}

	server.deliver(
		server.clients.All(),
		mustEnvelope(t, protocol.ServerMessageCreated, nil, map[string]string{"message_id": "message-1"}),
		"test",
	)

	if envelope := readEnvelope(t, conn); envelope.Type != protocol.ServerMessageCreated {
		t.Fatalf("expected the throttled frames to be dropped, got %s", envelope.Type)
	}
}

func TestBrokerEventsForAnotherConversationAreDropped(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"
	otherConversationID := "22222222-2222-4222-8222-222222222222"

	server, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{conversationID: true},
	})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	subscribeAndAck(t, conn, server, conversationID, nil)

	// Payload targeting another conversation on this conversation's subject.
	server.handleBrokerMessage(
		server.cfg.EventSubjectPrefix+"conversation."+conversationID,
		mustEnvelope(t, protocol.ServerMessageCreated, &otherConversationID, map[string]string{"message_id": "leaked"}),
	)

	// Typing payload that disagrees with the subject.
	server.handleBrokerMessage(
		server.cfg.TypingSubjectPrefix+conversationID,
		mustEnvelope(t, protocol.ServerTypingUpdated, &conversationID, protocol.TypingData{
			ConversationID: otherConversationID,
			UserID:         "user-2",
			IsTyping:       true,
		}),
	)

	server.handleBrokerMessage(
		server.cfg.EventSubjectPrefix+"conversation."+conversationID,
		mustEnvelope(t, protocol.ServerMessageCreated, &conversationID, map[string]string{"message_id": "message-1"}),
	)

	envelope := readEnvelope(t, conn)
	if envelope.Type != protocol.ServerMessageCreated {
		t.Fatalf("expected message.created, got %s", envelope.Type)
	}

	if envelope.ConversationID == nil || *envelope.ConversationID != conversationID {
		t.Fatalf("received an event for another conversation: %+v", envelope)
	}
}

func TestReceiptsRequireValidIdentifiers(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"

	server, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{conversationID: true},
	})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	subscribeAndAck(t, conn, server, conversationID, nil)

	send(t, conn, map[string]any{
		"type": protocol.ClientReadReceipt,
		"id":   "receipt-1",
		"data": map[string]any{
			"conversation_id": conversationID,
			"message_id":      "not-a-uuid",
			"seq":             0,
		},
	})

	envelope := readEnvelope(t, conn)
	if envelope.Type != protocol.ServerError {
		t.Fatalf("expected connection.error, got %s", envelope.Type)
	}

	var errorData protocol.ErrorData
	if err := json.Unmarshal(envelope.Data, &errorData); err != nil {
		t.Fatalf("decode error data: %v", err)
	}

	if errorData.Code != "invalid_payload" || errorData.RequestID != "receipt-1" {
		t.Fatalf("unexpected error payload: %+v", errorData)
	}
}

func TestShutdownDrainsConnectionsWithGoingAway(t *testing.T) {
	server, httpServer := testServer(t, &fakeAuthorizer{active: true})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	server.drainConnections()

	closeError := expectClose(t, conn, websocket.StatusGoingAway)
	if closeError.Reason != "gateway restarting" {
		t.Fatalf("unexpected close reason: %q", closeError.Reason)
	}
}
