package server

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"chatapp/gateway/internal/protocol"
)

func TestDurableEventsAreDeduplicatedByEventID(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"

	server, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{conversationID: true},
	})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))
	subscribeAndAck(t, conn, server, conversationID, nil)

	raw := mustEnvelope(t, protocol.ServerMessageCreated, &conversationID, map[string]string{"message_id": "message-1"})
	subject := server.cfg.EventSubjectPrefix + "conversation." + conversationID

	if err := server.handleDurableEvent(subject, raw); err != nil {
		t.Fatalf("handleDurableEvent() error: %v", err)
	}

	// A redelivered event must reach the client only once.
	if err := server.handleDurableEvent(subject, raw); err != nil {
		t.Fatalf("handleDurableEvent() error: %v", err)
	}

	envelope := readEnvelope(t, conn)
	if envelope.Type != protocol.ServerMessageCreated {
		t.Fatalf("expected message.created, got %s", envelope.Type)
	}

	readCtx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()

	if _, _, err := conn.Read(readCtx); err == nil {
		t.Fatal("the duplicate event was delivered a second time")
	}
}

func TestMalformedDurableEventsAreAcknowledged(t *testing.T) {
	server, _ := testServer(t, &fakeAuthorizer{active: true})

	if err := server.handleDurableEvent("chat.events.conversation.x", []byte("{not json")); err != nil {
		t.Fatalf("malformed events must be acknowledged, got %v", err)
	}

	if err := server.handleDurableEvent("chat.events.conversation.x", nil); err != nil {
		t.Fatalf("empty events must be acknowledged, got %v", err)
	}
}

func TestPresenceEventsReachConversationSubscribers(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"
	userID := "22222222-2222-4222-8222-222222222222"

	server, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{conversationID: true},
	})

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))
	subscribeAndAck(t, conn, server, conversationID, nil)

	server.handleBrokerMessage(
		server.cfg.PresenceSubjectPrefix+conversationID,
		mustEnvelope(t, protocol.ServerPresenceUpdated, &conversationID, protocol.PresenceData{
			ConversationID: conversationID,
			UserID:         userID,
			Online:         true,
		}),
	)

	envelope := readEnvelope(t, conn)
	if envelope.Type != protocol.ServerPresenceUpdated {
		t.Fatalf("expected presence.updated, got %s", envelope.Type)
	}

	var data protocol.PresenceData
	if err := json.Unmarshal(envelope.Data, &data); err != nil {
		t.Fatalf("decode presence data: %v", err)
	}

	if !data.Online || data.UserID != userID {
		t.Fatalf("unexpected presence payload: %+v", data)
	}
}
