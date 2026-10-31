package server

import (
	"context"
	"encoding/json"
	"errors"
	"sync"
	"sync/atomic"
	"time"

	"github.com/coder/websocket"
	"golang.org/x/time/rate"

	"chatapp/gateway/internal/auth"
	"chatapp/gateway/internal/protocol"
	"chatapp/gateway/internal/registry"
)

type Connection struct {
	id     string
	userID string
	remote string

	socket *websocket.Conn
	server *Server

	send      chan []byte
	done      chan struct{}
	closeOnce sync.Once
	writeOnce sync.Once

	inbound     *rate.Limiter
	authTimer   *time.Timer
	expiryTimer *time.Timer

	reasonMu sync.Mutex
	reason   string

	authenticated atomic.Bool
}

func newConnection(server *Server, socket *websocket.Conn, remote string) *Connection {
	socket.SetReadLimit(server.cfg.MaxFrameBytes)

	return &Connection{
		id:      protocol.NewID(),
		remote:  remote,
		socket:  socket,
		server:  server,
		send:    make(chan []byte, server.cfg.OutboundBuffer),
		done:    make(chan struct{}),
		inbound: rate.NewLimiter(rate.Limit(server.cfg.InboundRatePerSecond), server.cfg.InboundBurst),
	}
}

func (c *Connection) ID() string {
	return c.id
}

func (c *Connection) UserID() string {
	return c.userID
}

func (c *Connection) run() {
	startedAt := time.Now()

	defer func() {
		c.server.clients.Remove(c)
		c.stopTimers()
		c.forceClose()

		if c.authenticated.Load() {
			// A vanished client must not leave a stale typing indicator or an
			// online dot behind.
			c.server.clearTyping(c.userID, c.id)
			c.server.releasePresence(c.userID, c.id)

			reason := c.closeReason()
			c.server.metrics.disconnects.WithLabelValues(c.server.cfg.InstanceID, reason).Inc()
			c.server.logger.Info("websocket disconnected",
				"connection_id", c.id,
				"user_id", c.userID,
				"remote", c.remote,
				"reason", reason,
				"duration_ms", time.Since(startedAt).Milliseconds(),
			)
		}
	}()

	c.authTimer = time.AfterFunc(c.server.cfg.AuthTimeout, func() {
		if c.authenticated.Load() {
			return
		}

		c.server.metrics.authFailures.WithLabelValues("timeout").Inc()
		c.reject(websocket.StatusPolicyViolation, "authentication timeout")
	})

	c.startWriter()

	for {
		_, data, err := c.socket.Read(c.server.ctx)
		if err != nil {
			c.setCloseReasonIfEmpty(closeReasonFor(err))

			return
		}

		if len(data) == 0 {
			continue
		}

		if !c.inbound.Allow() {
			c.server.metrics.inboundThrottled.Inc()
			continue
		}

		var message protocol.ClientMessage
		if err := json.Unmarshal(data, &message); err != nil {
			c.sendError("", "invalid_message", "The message is not valid JSON.")
			continue
		}

		if !c.authenticated.Load() {
			if message.Type != protocol.ClientAuthenticate {
				c.reject(websocket.StatusPolicyViolation, "authentication required")

				return
			}

			if !c.authenticate(message, false) {
				return
			}

			continue
		}

		switch message.Type {
		case protocol.ClientAuthenticate:
			if !c.authenticate(message, true) {
				return
			}
		case protocol.ClientSubscribe:
			c.subscribe(message)
		case protocol.ClientUnsubscribe:
			c.unsubscribe(message)
		case protocol.ClientTypingStart:
			c.typing(message, true)
		case protocol.ClientTypingStop:
			c.typing(message, false)
		case protocol.ClientReceipt:
			c.receipt(message, false)
		case protocol.ClientReadReceipt:
			c.receipt(message, true)
		case protocol.ClientPing:
			envelope, err := protocol.NewEnvelope(protocol.ServerPong, nil, nil, protocol.PongData{RequestID: message.RequestID})
			if err == nil {
				c.sendEnvelope(envelope)
			}
		default:
			c.sendError(message.RequestID, "unsupported_type", "This message type is not supported.")
		}
	}
}

func (c *Connection) authenticate(message protocol.ClientMessage, renewing bool) bool {
	var data protocol.AuthData
	if err := json.Unmarshal(message.Data, &data); err != nil || data.Token == "" {
		c.server.metrics.authFailures.WithLabelValues("invalid_payload").Inc()
		c.reject(websocket.StatusPolicyViolation, "invalid authentication payload")

		return false
	}

	claims, err := c.server.verify.Verify(data.Token)
	if err != nil {
		reason := "invalid_token"
		if errors.Is(err, auth.ErrExpiredToken) {
			reason = "expired_token"
		}

		c.server.metrics.authFailures.WithLabelValues(reason).Inc()
		c.reject(websocket.StatusPolicyViolation, "invalid credentials")

		return false
	}

	if renewing && claims.Subject != c.userID {
		c.server.metrics.authFailures.WithLabelValues("subject_mismatch").Inc()
		c.reject(websocket.StatusPolicyViolation, "invalid credentials")

		return false
	}

	ctx, cancel := context.WithTimeout(c.server.ctx, 3*time.Second)
	active, err := c.server.store.UserIsActive(ctx, claims.Subject)
	cancel()

	if err != nil {
		c.server.logger.Error("failed to verify account status", "user_id", claims.Subject, "error", err)
		c.server.metrics.authFailures.WithLabelValues("account_lookup_failed").Inc()
		c.reject(websocket.StatusInternalError, "temporary failure")

		return false
	}

	if !active {
		c.server.metrics.authFailures.WithLabelValues("inactive_account").Inc()
		c.reject(websocket.StatusPolicyViolation, "account inactive")

		return false
	}

	if renewing {
		c.server.metrics.tokenRenewals.Inc()
	} else {
		if c.server.clients.UserConnectionCount(claims.Subject) >= c.server.cfg.MaxConnectionsPerUser {
			c.server.metrics.authFailures.WithLabelValues("connection_limit").Inc()
			c.server.logger.Warn("rejecting websocket because the per user connection limit was reached",
				"connection_id", c.id,
				"user_id", claims.Subject,
				"limit", c.server.cfg.MaxConnectionsPerUser,
			)
			c.reject(websocket.StatusPolicyViolation, "connection limit reached")

			return false
		}

		c.userID = claims.Subject
		c.authenticated.Store(true)
		c.server.clients.Add(c)
		c.server.metrics.connects.WithLabelValues(c.server.cfg.InstanceID).Inc()
		c.server.trackPresence(c.userID, c.id)

		c.server.logger.Info("websocket connected",
			"connection_id", c.id,
			"user_id", c.userID,
			"remote", c.remote,
			"instance", c.server.cfg.InstanceID,
		)
	}

	if c.authTimer != nil {
		c.authTimer.Stop()
		c.authTimer = nil
	}

	c.resetExpiry(claims.ExpiresAt)

	envelope, err := protocol.NewEnvelope(protocol.ServerReady, nil, nil, protocol.ReadyData{
		UserID:                   c.userID,
		InstanceID:               c.server.cfg.InstanceID,
		Version:                  protocol.Version,
		HeartbeatSeconds:         int(c.server.cfg.HeartbeatInterval.Seconds()),
		MaxSubscriptions:         c.server.cfg.MaxSubscriptions,
		MaxFrameBytes:            c.server.cfg.MaxFrameBytes,
		ConnectionTokenExpiresAt: claims.ExpiresAt.Format(time.RFC3339),
	})
	if err != nil {
		c.setCloseReason("encode_failed")
		c.forceClose()

		return false
	}

	c.sendEnvelope(envelope)

	return true
}

// resetExpiry closes the connection once the gateway token that authenticated it
// expires, so a short lived credential can never keep a socket alive forever.
func (c *Connection) resetExpiry(expiresAt time.Time) {
	c.stopExpiryTimer()

	ttl := time.Until(expiresAt)
	if ttl <= 0 {
		c.setCloseReason("token_expired")
		c.reject(websocket.StatusPolicyViolation, "token expired")

		return
	}

	c.expiryTimer = time.AfterFunc(ttl, func() {
		c.setCloseReason("token_expired")
		c.server.logger.Info("closing websocket with an expired gateway token",
			"connection_id", c.id,
			"user_id", c.userID,
		)
		c.reject(websocket.StatusPolicyViolation, "token expired")
	})
}

func (c *Connection) stopExpiryTimer() {
	if c.expiryTimer != nil {
		c.expiryTimer.Stop()
		c.expiryTimer = nil
	}
}

func (c *Connection) stopTimers() {
	if c.authTimer != nil {
		c.authTimer.Stop()
		c.authTimer = nil
	}

	c.stopExpiryTimer()
}

// drain closes the connection with a going-away frame so clients reconnect
// right away instead of reporting an abnormal closure.
func (c *Connection) drain() {
	c.setCloseReason("shutdown")
	c.reject(websocket.StatusGoingAway, "gateway restarting")
}

func (c *Connection) setCloseReason(reason string) {
	c.reasonMu.Lock()
	defer c.reasonMu.Unlock()

	c.reason = reason
}

func (c *Connection) setCloseReasonIfEmpty(reason string) {
	c.reasonMu.Lock()
	defer c.reasonMu.Unlock()

	if c.reason == "" {
		c.reason = reason
	}
}

func (c *Connection) closeReason() string {
	c.reasonMu.Lock()
	defer c.reasonMu.Unlock()

	if c.reason == "" {
		return "connection_closed"
	}

	return c.reason
}

func (c *Connection) subscribe(message protocol.ClientMessage) {
	var data protocol.ConversationData
	if err := json.Unmarshal(message.Data, &data); err != nil || !validID(data.ConversationID) {
		c.sendError(message.RequestID, "invalid_payload", "A valid conversation_id is required.")

		return
	}

	conversationID := data.ConversationID

	if data.LastSeq != nil && *data.LastSeq < 0 {
		c.sendError(message.RequestID, "invalid_payload", "last_seq must not be negative.")

		return
	}

	if c.server.clients.IsSubscribed(c, conversationID) {
		return
	}

	if c.server.clients.SubscriptionCount(c) >= c.server.cfg.MaxSubscriptions {
		c.server.metrics.subscriptionErrors.WithLabelValues("limit").Inc()
		c.sendError(message.RequestID, "subscription_limit", "The subscription limit was reached.")

		return
	}

	ctx, cancel := context.WithTimeout(c.server.ctx, 3*time.Second)
	allowed, err := c.server.store.CanAccessConversation(ctx, c.userID, conversationID)
	cancel()

	if err != nil {
		c.server.metrics.subscriptionErrors.WithLabelValues("lookup_failed").Inc()
		c.server.logger.Error("failed to authorize conversation subscription",
			"connection_id", c.id,
			"conversation_id", conversationID,
			"error", err,
		)
		c.sendError(message.RequestID, "subscription_failed", "The conversation could not be authorized.")

		return
	}

	if !allowed {
		c.server.metrics.subscriptionErrors.WithLabelValues("forbidden").Inc()
		c.sendError(message.RequestID, "forbidden", "You are not a member of this conversation.")

		return
	}

	c.server.clients.Subscribe(c, conversationID)

	snapshotCtx, snapshotCancel := context.WithTimeout(c.server.ctx, 3*time.Second)
	defer snapshotCancel()

	latestSeq := c.server.latestSeq(snapshotCtx, conversationID)

	// Acknowledge first so the client knows the subscription is live and which
	// cursor the server is at.
	if envelope, err := protocol.NewEnvelope(protocol.ServerSubscribed, &conversationID, nil, protocol.SubscribedData{
		ConversationID: conversationID,
		LatestSeq:      latestSeq,
		LastSeq:        data.LastSeq,
	}); err == nil {
		c.sendEnvelope(envelope)
	}

	// A client that reconnects with its cursor and is behind must refetch over
	// HTTP: JetStream history is not retained long enough to replay it here.
	if data.LastSeq != nil && latestSeq > *data.LastSeq {
		c.server.metrics.resyncs.WithLabelValues("missed_events").Inc()

		if envelope, err := protocol.NewEnvelope(protocol.ServerSyncRequired, &conversationID, nil, protocol.SyncRequiredData{
			ConversationID: conversationID,
			LastSeq:        *data.LastSeq,
			LatestSeq:      latestSeq,
			Reason:         "missed_events",
		}); err == nil {
			c.sendEnvelope(envelope)
		}
	}

	c.server.sendPresenceSnapshot(snapshotCtx, c, conversationID)
}

func (c *Connection) unsubscribe(message protocol.ClientMessage) {
	conversationID, ok := c.conversationID(message)
	if !ok {
		return
	}

	c.server.clients.Unsubscribe(c, conversationID)
}

func (c *Connection) typing(message protocol.ClientMessage, isTyping bool) {
	conversationID, ok := c.conversationID(message)
	if !ok {
		return
	}

	if !c.server.clients.IsSubscribed(c, conversationID) {
		c.sendError(message.RequestID, "not_subscribed", "Subscribe to the conversation before sending typing events.")

		return
	}

	ctx, cancel := context.WithTimeout(c.server.ctx, 3*time.Second)

	if isTyping {
		if err := c.server.typing.Start(ctx, conversationID, c.userID, c.id); err != nil {
			c.server.metrics.typingErrors.Inc()
			c.server.logger.Debug("failed to record typing state", "connection_id", c.id, "error", err)
		}

		c.server.metrics.typingEvents.WithLabelValues("start").Inc()
	} else {
		if err := c.server.typing.Stop(ctx, conversationID, c.userID, c.id); err != nil {
			c.server.metrics.typingErrors.Inc()
			c.server.logger.Debug("failed to clear typing state", "connection_id", c.id, "error", err)
		}

		c.server.metrics.typingEvents.WithLabelValues("stop").Inc()
	}

	cancel()

	expiresAt := time.Now().UTC()
	if isTyping {
		expiresAt = expiresAt.Add(c.server.cfg.TypingTTL)
	}

	c.server.publishTyping(conversationID, protocol.TypingData{
		ConversationID: conversationID,
		UserID:         c.userID,
		IsTyping:       isTyping,
		ExpiresAt:      expiresAt.Format(time.RFC3339),
	})
}

func (c *Connection) receipt(message protocol.ClientMessage, read bool) {
	var data protocol.ReceiptData
	if err := json.Unmarshal(message.Data, &data); err != nil {
		c.sendError(message.RequestID, "invalid_payload", "The receipt payload is not valid JSON.")

		return
	}

	if !validID(data.ConversationID) {
		c.sendError(message.RequestID, "invalid_payload", "A valid conversation_id is required.")

		return
	}

	if data.Seq <= 0 || (data.MessageID != "" && !validID(data.MessageID)) {
		c.sendError(message.RequestID, "invalid_payload", "A positive seq and a valid message_id are required.")

		return
	}

	if !c.server.clients.IsSubscribed(c, data.ConversationID) {
		c.sendError(message.RequestID, "not_subscribed", "Subscribe to the conversation before sending receipts.")

		return
	}

	// The WebSocket path only exists for latency: the read cursor itself is
	// persisted by POST /api/v1/conversations/{id}/read, which publishes the
	// authoritative receipt.updated event. Clients must therefore treat
	// receipts as monotonic and idempotent.
	c.server.publishReceipt(data.ConversationID, data.Seq, protocol.ReceiptUpdatedData{
		ConversationID: data.ConversationID,
		UserID:         c.userID,
		MessageID:      data.MessageID,
		Seq:            data.Seq,
		Read:           read,
	})
}

func (c *Connection) conversationID(message protocol.ClientMessage) (string, bool) {
	var data protocol.ConversationData
	if err := json.Unmarshal(message.Data, &data); err != nil || !validID(data.ConversationID) {
		c.sendError(message.RequestID, "invalid_payload", "A valid conversation_id is required.")

		return "", false
	}

	return data.ConversationID, true
}

func (c *Connection) startWriter() {
	c.writeOnce.Do(func() {
		go c.writeLoop()
	})
}

func (c *Connection) writeLoop() {
	ticker := time.NewTicker(c.server.cfg.HeartbeatInterval)
	defer ticker.Stop()

	for {
		select {
		case payload := <-c.send:
			if err := c.write(payload); err != nil {
				c.server.logger.Debug("websocket write failed", "connection_id", c.id, "error", err)
				c.forceClose()

				return
			}
		case <-ticker.C:
			ctx, cancel := context.WithTimeout(context.Background(), c.server.cfg.WriteTimeout)
			err := c.socket.Ping(ctx)
			cancel()

			if err != nil {
				c.server.logger.Debug("websocket heartbeat failed", "connection_id", c.id, "error", err)
				c.forceClose()

				return
			}

			if c.authenticated.Load() {
				c.server.refreshPresence(c.userID, c.id)
			}
		case <-c.done:
			return
		}
	}
}

func (c *Connection) write(payload []byte) error {
	ctx, cancel := context.WithTimeout(context.Background(), c.server.cfg.WriteTimeout)
	defer cancel()

	return c.socket.Write(ctx, websocket.MessageText, payload)
}

func (c *Connection) sendEnvelope(envelope protocol.Envelope) {
	payload, err := json.Marshal(envelope)
	if err != nil {
		c.server.logger.Error("failed to encode websocket event", "connection_id", c.id, "error", err)
		return
	}

	c.enqueue(payload)
}

func (c *Connection) sendError(requestID, code, message string) {
	c.sendEnvelope(protocol.ErrorEnvelope(requestID, code, message))
}

func (c *Connection) enqueue(payload []byte) bool {
	select {
	case <-c.done:
		return false
	default:
	}

	select {
	case c.send <- payload:
		return true
	case <-c.done:
		return false
	default:
		c.server.metrics.slowConsumers.Inc()
		c.server.logger.Warn("closing slow websocket consumer",
			"connection_id", c.id,
			"user_id", c.userID,
			"buffer", c.server.cfg.OutboundBuffer,
		)
		c.setCloseReason("slow_consumer")
		c.reject(websocket.StatusTryAgainLater, "slow consumer")

		return false
	}
}

func (c *Connection) reject(status websocket.StatusCode, reason string) {
	c.closeOnce.Do(func() {
		close(c.done)

		go func() {
			_ = c.socket.Close(status, reason)
			_ = c.socket.CloseNow()
		}()
	})
}

func (c *Connection) forceClose() {
	c.closeOnce.Do(func() {
		close(c.done)
		_ = c.socket.CloseNow()
	})
}

func closeReasonFor(err error) string {
	if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		return "shutdown"
	}

	switch websocket.CloseStatus(err) {
	case websocket.StatusNormalClosure:
		return "client_closed"
	case websocket.StatusGoingAway:
		return "client_closed"
	case websocket.StatusPolicyViolation:
		return "policy_violation"
	case websocket.StatusTryAgainLater:
		return "slow_consumer"
	case websocket.StatusMessageTooBig:
		return "message_too_big"
	default:
		return "network_error"
	}
}

func validID(value string) bool {
	if len(value) != 36 {
		return false
	}

	for index, char := range value {
		if index == 8 || index == 13 || index == 18 || index == 23 {
			if char != '-' {
				return false
			}

			continue
		}

		if !((char >= '0' && char <= '9') || (char >= 'a' && char <= 'f') || (char >= 'A' && char <= 'F')) {
			return false
		}
	}

	return true
}

var _ registry.Client = (*Connection)(nil)
