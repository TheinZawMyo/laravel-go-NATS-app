package server

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/coder/websocket"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/nats-io/nats.go"
	"github.com/prometheus/client_golang/prometheus/promhttp"
	"github.com/redis/go-redis/v9"
	"golang.org/x/time/rate"

	"chatapp/gateway/internal/auth"
	"chatapp/gateway/internal/broker"
	"chatapp/gateway/internal/config"
	"chatapp/gateway/internal/dedupe"
	"chatapp/gateway/internal/presence"
	"chatapp/gateway/internal/protocol"
	"chatapp/gateway/internal/registry"
	"chatapp/gateway/internal/store"
	"chatapp/gateway/internal/typing"
)

type Authorizer interface {
	UserIsActive(ctx context.Context, userID string) (bool, error)
	CanAccessConversation(ctx context.Context, userID, conversationID string) (bool, error)
}

// Directory is the optional, richer view of conversations the gateway needs for
// client resync and presence fan-out. Deployments without it simply lose those
// features; the core realtime path keeps working.
type Directory interface {
	ConversationIDsFor(ctx context.Context, userID string) ([]string, error)
	MemberIDs(ctx context.Context, conversationID string) ([]string, error)
	LatestSeq(ctx context.Context, conversationID string) (int64, error)
}

// maxPresenceSnapshotMembers caps the member presence snapshot sent on
// subscribe so joining a very large conversation cannot flood a socket.
const maxPresenceSnapshotMembers = 100

type dependencies struct {
	DB         *pgxpool.Pool
	Redis      *redis.Client
	NATS       *nats.Conn
	Authorizer Authorizer
	Directory  Directory
}

type limiterEntry struct {
	limiter  *rate.Limiter
	lastSeen time.Time
}

type Server struct {
	cfg       config.Config
	logger    *slog.Logger
	db        *pgxpool.Pool
	redis     *redis.Client
	nats      *nats.Conn
	store     Authorizer
	directory Directory
	presence  *presence.Tracker
	typing    *typing.Store
	dedupe    *dedupe.Cache
	consumer  *broker.Consumer
	verify    *auth.Verifier
	clients   *registry.Registry
	metrics   *metrics
	http      *http.Server
	conns     sync.WaitGroup
	ctx       context.Context
	cancel    context.CancelFunc

	limitersMu sync.Mutex
	limiters   map[string]*limiterEntry

	brokerMu sync.Mutex
	broker   []*nats.Subscription
}

func New(cfg config.Config, logger *slog.Logger) (*Server, error) {
	db, err := pgxpool.New(context.Background(), cfg.DatabaseURL)
	if err != nil {
		return nil, fmt.Errorf("connect to postgres: %w", err)
	}

	redisOpts, err := redis.ParseURL(cfg.RedisURL)
	if err != nil {
		db.Close()

		return nil, fmt.Errorf("parse redis url: %w", err)
	}

	redisClient := redis.NewClient(redisOpts)

	nc, err := nats.Connect(
		cfg.NATSURL,
		nats.Name(cfg.InstanceID),
		nats.MaxReconnects(-1),
		nats.ReconnectWait(time.Second),
	)
	if err != nil {
		db.Close()
		_ = redisClient.Close()

		return nil, fmt.Errorf("connect to nats: %w", err)
	}

	dataStore := store.New(db)

	s := newServer(cfg, logger, dependencies{
		DB:         db,
		Redis:      redisClient,
		NATS:       nc,
		Authorizer: dataStore,
		Directory:  dataStore,
	})

	if err := s.subscribeBroker(); err != nil {
		s.Close()

		return nil, err
	}

	return s, nil
}

func newServer(cfg config.Config, logger *slog.Logger, deps dependencies) *Server {
	ctx, cancel := context.WithCancel(context.Background())

	s := &Server{
		cfg:       cfg,
		logger:    logger,
		db:        deps.DB,
		redis:     deps.Redis,
		nats:      deps.NATS,
		store:     deps.Authorizer,
		directory: deps.Directory,
		presence:  presence.New(deps.Redis, cfg.PresenceSubjectTTL),
		typing:    typing.New(deps.Redis, cfg.TypingTTL),
		dedupe:    dedupe.New(cfg.EventDedupeLimit, cfg.EventDedupeTTL),
		verify:    auth.NewVerifier(cfg.TokenSecret),
		clients:   registry.New(),
		ctx:       ctx,
		cancel:    cancel,
		limiters:  make(map[string]*limiterEntry),
	}

	s.metrics = newMetrics(s.clients)

	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", s.healthz)
	mux.HandleFunc("GET /readyz", s.readyz)
	mux.Handle("GET /metrics", promhttp.HandlerFor(s.metrics.registry, promhttp.HandlerOpts{}))
	mux.HandleFunc("GET /ws", s.websocket)

	s.http = &http.Server{
		Addr:              cfg.Addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}

	return s
}

func (s *Server) Run(ctx context.Context) error {
	errCh := make(chan error, 1)

	go func() {
		s.logger.Info("gateway listening", "addr", s.cfg.Addr, "instance", s.cfg.InstanceID)
		errCh <- s.http.ListenAndServe()
	}()

	select {
	case err := <-errCh:
		return err
	case <-ctx.Done():
	}

	shutdownCtx, cancel := context.WithTimeout(context.Background(), s.cfg.ShutdownTimeout)
	defer cancel()

	s.logger.Info("gateway shutting down", "connections", s.clients.Count())

	s.drainConnections()

	if err := s.http.Shutdown(shutdownCtx); err != nil {
		s.logger.Warn("gateway http shutdown failed", "error", err)
	}

	s.cancel()

	done := make(chan struct{})
	go func() {
		s.conns.Wait()
		close(done)
	}()

	select {
	case <-done:
	case <-shutdownCtx.Done():
		s.logger.Warn("timed out draining connections")
	}

	s.unsubscribeBroker()
	s.logger.Info("gateway stopped")

	return nil
}

// drainConnections tells every live client that the gateway is going away so
// they reconnect instead of treating the shutdown as a transport failure.
func (s *Server) drainConnections() {
	for _, client := range s.clients.All() {
		if connection, ok := client.(*Connection); ok {
			connection.drain()
		}
	}
}

func (s *Server) Close() {
	s.cancel()

	for _, client := range s.clients.All() {
		if connection, ok := client.(*Connection); ok {
			connection.forceClose()
		}
	}

	s.unsubscribeBroker()

	if s.nats != nil {
		s.nats.Close()
	}

	if s.redis != nil {
		_ = s.redis.Close()
	}

	if s.db != nil {
		s.db.Close()
	}
}

func (s *Server) healthz(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *Server) readyz(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
	defer cancel()

	if s.db != nil {
		if err := s.db.Ping(ctx); err != nil {
			s.logger.Warn("postgres not ready", "error", err)
			writeJSON(w, http.StatusServiceUnavailable, map[string]string{"status": "degraded", "dependency": "postgres"})
			return
		}
	}

	if s.redis != nil {
		if err := s.redis.Ping(ctx).Err(); err != nil {
			s.logger.Warn("redis not ready", "error", err)
			writeJSON(w, http.StatusServiceUnavailable, map[string]string{"status": "degraded", "dependency": "redis"})
			return
		}
	}

	if s.nats != nil && !s.nats.IsConnected() {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"status": "degraded", "dependency": "nats"})
		return
	}

	writeJSON(w, http.StatusOK, map[string]string{"status": "ready"})
}

func (s *Server) websocket(w http.ResponseWriter, r *http.Request) {
	remote := remoteIP(r)

	if !s.allow(remote) {
		s.metrics.upgradeRejections.WithLabelValues("rate_limited").Inc()
		http.Error(w, http.StatusText(http.StatusTooManyRequests), http.StatusTooManyRequests)
		return
	}

	if s.clients.Count() >= s.cfg.MaxConnections {
		s.metrics.upgradeRejections.WithLabelValues("connection_limit").Inc()
		http.Error(w, http.StatusText(http.StatusServiceUnavailable), http.StatusServiceUnavailable)
		return
	}

	conn, err := websocket.Accept(w, r, &websocket.AcceptOptions{
		OriginPatterns:  s.cfg.AllowedOrigins,
		CompressionMode: websocket.CompressionDisabled,
	})
	if err != nil {
		s.metrics.upgradeRejections.WithLabelValues("handshake").Inc()
		s.logger.Warn("websocket upgrade rejected", "remote", remote, "error", err)
		return
	}

	connection := newConnection(s, conn, remote)

	s.conns.Add(1)
	go func() {
		defer s.conns.Done()
		connection.run()
	}()
}

func (s *Server) allow(remote string) bool {
	now := time.Now()

	s.limitersMu.Lock()
	defer s.limitersMu.Unlock()

	entry, ok := s.limiters[remote]
	if !ok || now.Sub(entry.lastSeen) > 10*time.Minute {
		entry = &limiterEntry{limiter: rate.NewLimiter(rate.Every(250*time.Millisecond), 10)}
		s.limiters[remote] = entry
	}
	entry.lastSeen = now

	if len(s.limiters) > 10000 {
		for key, candidate := range s.limiters {
			if now.Sub(candidate.lastSeen) > 10*time.Minute {
				delete(s.limiters, key)
			}
		}
	}

	return entry.limiter.Allow()
}

func (s *Server) subscribeBroker() error {
	if s.nats == nil {
		return nil
	}

	// Durable conversation events come from JetStream so nothing is lost while
	// the gateway restarts. Typing and presence stay on core NATS: they are
	// worthless a second later and must never be replayed.
	s.consumer = broker.New(s.nats, s.logger, broker.Options{
		Enabled:    s.cfg.JetStreamEnabled,
		Stream:     s.cfg.JetStreamStream,
		Durable:    s.cfg.JetStreamDurable,
		Subject:    s.cfg.EventSubjectPrefix + ">",
		MaxDeliver: s.cfg.JetStreamMaxRetry,
	})

	if err := s.consumer.Start(s.handleDurableEvent); err != nil {
		return err
	}

	s.brokerMu.Lock()
	defer s.brokerMu.Unlock()

	for _, subject := range []string{
		s.cfg.TypingSubjectPrefix + ">",
		s.cfg.PresenceSubjectPrefix + ">",
		s.cfg.ReceiptSubjectPrefix + ">",
	} {
		sub, err := s.nats.Subscribe(subject, func(message *nats.Msg) {
			s.handleBrokerMessage(message.Subject, message.Data)
		})
		if err != nil {
			return fmt.Errorf("subscribe to %s: %w", subject, err)
		}

		s.broker = append(s.broker, sub)
	}

	return nil
}

func (s *Server) unsubscribeBroker() {
	if s.consumer != nil {
		s.consumer.Stop()
		s.consumer = nil
	}

	s.brokerMu.Lock()
	defer s.brokerMu.Unlock()

	for _, sub := range s.broker {
		_ = sub.Unsubscribe()
	}

	s.broker = nil
}

// handleDurableEvent is the JetStream handler. It acknowledges only after the
// event was fanned out, and returns an error to trigger redelivery otherwise.
// Deduplication keeps redelivery from reaching clients twice.
func (s *Server) handleDurableEvent(subject string, raw []byte) error {
	if len(raw) == 0 {
		s.metrics.eventsDropped.WithLabelValues("invalid_size").Inc()

		return nil
	}

	var envelope protocol.Envelope
	if err := json.Unmarshal(raw, &envelope); err != nil || envelope.EventID == "" {
		// Malformed events can never succeed, so acknowledge them instead of
		// letting the broker retry forever.
		s.metrics.eventsDropped.WithLabelValues("invalid_envelope").Inc()
		s.logger.Warn("invalid durable event dropped", "subject", subject, "error", err)

		return nil
	}

	if s.dedupe.Seen(envelope.EventID) {
		s.metrics.duplicateEvents.Inc()

		return nil
	}

	s.handleBrokerMessage(subject, raw)

	return nil
}

func (s *Server) handleBrokerMessage(subject string, raw []byte) {
	if len(raw) == 0 || len(raw) > 1024*1024 {
		s.metrics.eventsDropped.WithLabelValues("invalid_size").Inc()
		return
	}

	var envelope protocol.Envelope
	if err := json.Unmarshal(raw, &envelope); err != nil || envelope.Version != protocol.Version || envelope.EventID == "" || envelope.Type == "" {
		s.metrics.eventsDropped.WithLabelValues("invalid_envelope").Inc()
		s.logger.Warn("invalid broker event dropped", "subject", subject, "error", err)
		return
	}

	var clients []registry.Client

	switch {
	case strings.HasPrefix(subject, s.cfg.EventSubjectPrefix):
		target := strings.TrimPrefix(subject, s.cfg.EventSubjectPrefix)
		switch {
		case strings.HasPrefix(target, "user."):
			userID := strings.TrimPrefix(target, "user.")
			if strings.TrimSpace(userID) == "" {
				s.metrics.eventsDropped.WithLabelValues("invalid_subject").Inc()

				return
			}

			clients = s.clients.ByUser(userID)
		default:
			conversationID := strings.TrimPrefix(target, "conversation.")
			if !s.subjectMatchesEnvelope(subject, conversationID, envelope) {
				return
			}

			clients = s.clients.Subscribers(conversationID)
		}
	case strings.HasPrefix(subject, s.cfg.TypingSubjectPrefix):
		conversationID := strings.TrimPrefix(subject, s.cfg.TypingSubjectPrefix)
		if !s.subjectMatchesEnvelope(subject, conversationID, envelope) {
			return
		}

		clients = s.clients.Subscribers(conversationID)
	case strings.HasPrefix(subject, s.cfg.PresenceSubjectPrefix):
		conversationID := strings.TrimPrefix(subject, s.cfg.PresenceSubjectPrefix)
		if !s.subjectMatchesEnvelope(subject, conversationID, envelope) {
			return
		}

		clients = s.clients.Subscribers(conversationID)
	case strings.HasPrefix(subject, s.cfg.ReceiptSubjectPrefix):
		conversationID := strings.TrimPrefix(subject, s.cfg.ReceiptSubjectPrefix)
		if !s.subjectMatchesEnvelope(subject, conversationID, envelope) {
			return
		}

		clients = s.clients.Subscribers(conversationID)
	default:
		s.metrics.eventsDropped.WithLabelValues("unknown_subject").Inc()

		return
	}

	s.deliver(clients, raw, "broker")
}

// subjectMatchesEnvelope rejects events whose envelope targets a conversation
// other than the one encoded in the NATS subject. Without this check a single
// mis-published event would leak messages into every other conversation.
func (s *Server) subjectMatchesEnvelope(subject, conversationID string, envelope protocol.Envelope) bool {
	if !validID(conversationID) || envelope.ConversationID == nil || *envelope.ConversationID != conversationID {
		s.metrics.eventsDropped.WithLabelValues("conversation_mismatch").Inc()
		s.logger.Warn("broker event dropped because the subject does not match the envelope",
			"subject", subject,
			"conversation_id", conversationID,
		)

		return false
	}

	if envelope.Type == protocol.ServerTypingUpdated {
		var data protocol.TypingData
		if err := json.Unmarshal(envelope.Data, &data); err != nil || data.ConversationID != conversationID {
			s.metrics.eventsDropped.WithLabelValues("conversation_mismatch").Inc()

			return false
		}
	}

	if envelope.Type == protocol.ServerPresenceUpdated {
		var data protocol.PresenceData
		if err := json.Unmarshal(envelope.Data, &data); err != nil || data.ConversationID != conversationID || !validID(data.UserID) {
			s.metrics.eventsDropped.WithLabelValues("conversation_mismatch").Inc()

			return false
		}
	}

	return true
}

func (s *Server) deliver(clients []registry.Client, raw []byte, source string) {
	for _, client := range clients {
		connection, ok := client.(*Connection)
		if !ok || !connection.enqueue(raw) {
			continue
		}

		s.metrics.eventsDelivered.WithLabelValues(source).Inc()
	}
}

func (s *Server) publishEphemeral(envelope protocol.Envelope, subject string, conversationID string) {
	raw, err := json.Marshal(envelope)
	if err != nil {
		s.logger.Error("failed to encode ephemeral event", "error", err)
		return
	}

	if s.nats != nil {
		if err := s.nats.Publish(subject, raw); err != nil {
			s.metrics.eventsDropped.WithLabelValues("publish_failed").Inc()
			s.logger.Warn("failed to publish ephemeral event", "subject", subject, "error", err)
		}

		return
	}

	s.deliver(s.clients.Subscribers(conversationID), raw, "local")
}

func (s *Server) publishTyping(conversationID string, data protocol.TypingData) {
	envelope, err := protocol.NewEnvelope(protocol.ServerTypingUpdated, &conversationID, nil, data)
	if err != nil {
		return
	}

	s.publishEphemeral(envelope, s.cfg.TypingSubjectPrefix+conversationID, conversationID)
}

func (s *Server) publishPresence(conversationID string, data protocol.PresenceData) {
	envelope, err := protocol.NewEnvelope(protocol.ServerPresenceUpdated, &conversationID, nil, data)
	if err != nil {
		return
	}

	s.publishEphemeral(envelope, s.cfg.PresenceSubjectPrefix+conversationID, conversationID)
}

func (s *Server) publishReceipt(conversationID string, seq int64, data protocol.ReceiptUpdatedData) {
	envelope, err := protocol.NewEnvelope(protocol.ServerReceiptUpdated, &conversationID, &seq, data)
	if err != nil {
		return
	}

	s.publishEphemeral(envelope, s.cfg.ReceiptSubjectPrefix+conversationID, conversationID)
}

// latestSeq reports the conversation's newest sequence, or zero when the
// directory or the query is unavailable. Resync is an optimization: when it
// cannot be computed, the client simply keeps its live subscription.
func (s *Server) latestSeq(ctx context.Context, conversationID string) int64 {
	if s.directory == nil {
		return 0
	}

	seq, err := s.directory.LatestSeq(ctx, conversationID)
	if err != nil {
		s.logger.Debug("failed to read latest conversation sequence",
			"conversation_id", conversationID,
			"error", err,
		)

		return 0
	}

	return seq
}

// trackPresence registers a connection lease and fans out a presence.updated
// event for every conversation of the user when they transition to online.
func (s *Server) trackPresence(userID, connectionID string) {
	if !s.presence.Enabled() {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	transitioned, err := s.presence.Connect(ctx, userID, connectionID)
	if err != nil {
		s.metrics.presenceErrors.Inc()
		s.logger.Warn("failed to register presence", "user_id", userID, "error", err)

		return
	}

	if transitioned {
		s.metrics.presenceTransitions.WithLabelValues("online").Inc()
		s.broadcastPresence(ctx, userID, true)
	}
}

// refreshPresence extends the lease; the heartbeat calls it so a live socket
// never expires while an abandoned one does.
func (s *Server) refreshPresence(userID, connectionID string) {
	if !s.presence.Enabled() {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	if err := s.presence.Refresh(ctx, userID, connectionID); err != nil {
		s.metrics.presenceErrors.Inc()
		s.logger.Debug("failed to refresh presence", "user_id", userID, "error", err)
	}
}

// releasePresence drops the lease and broadcasts an offline transition once the
// user has no other live connection.
func (s *Server) releasePresence(userID, connectionID string) {
	if !s.presence.Enabled() || userID == "" {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	offline, err := s.presence.Disconnect(ctx, userID, connectionID)
	if err != nil {
		s.metrics.presenceErrors.Inc()
		s.logger.Warn("failed to release presence", "user_id", userID, "error", err)

		return
	}

	if offline {
		s.metrics.presenceTransitions.WithLabelValues("offline").Inc()
		s.broadcastPresence(ctx, userID, false)
	}
}

func (s *Server) broadcastPresence(ctx context.Context, userID string, online bool) {
	if s.directory == nil {
		return
	}

	conversationIDs, err := s.directory.ConversationIDsFor(ctx, userID)
	if err != nil {
		s.metrics.presenceErrors.Inc()
		s.logger.Warn("failed to list conversations for presence", "user_id", userID, "error", err)

		return
	}

	lastSeenAt := ""
	if !online {
		if seen, err := s.presence.LastSeen(ctx, userID); err == nil && !seen.IsZero() {
			lastSeenAt = seen.UTC().Format(time.RFC3339)
		}
	}

	for _, conversationID := range conversationIDs {
		if !validID(conversationID) {
			continue
		}

		s.publishPresence(conversationID, protocol.PresenceData{
			ConversationID: conversationID,
			UserID:         userID,
			Online:         online,
			LastSeenAt:     lastSeenAt,
		})
	}
}

// sendPresenceSnapshot tells a freshly subscribed client who else is online.
func (s *Server) sendPresenceSnapshot(ctx context.Context, connection *Connection, conversationID string) {
	if !s.presence.Enabled() || s.directory == nil {
		return
	}

	members, err := s.directory.MemberIDs(ctx, conversationID)
	if err != nil {
		s.metrics.presenceErrors.Inc()
		s.logger.Debug("failed to list conversation members for presence", "conversation_id", conversationID, "error", err)

		return
	}

	for index, memberID := range members {
		if index >= maxPresenceSnapshotMembers {
			break
		}

		if memberID == connection.userID {
			continue
		}

		online, err := s.presence.IsOnline(ctx, memberID)
		if err != nil {
			s.metrics.presenceErrors.Inc()

			continue
		}

		lastSeenAt := ""
		if !online {
			if seen, err := s.presence.LastSeen(ctx, memberID); err == nil && !seen.IsZero() {
				lastSeenAt = seen.UTC().Format(time.RFC3339)
			}
		}

		envelope, err := protocol.NewEnvelope(protocol.ServerPresenceUpdated, &conversationID, nil, protocol.PresenceData{
			ConversationID: conversationID,
			UserID:         memberID,
			Online:         online,
			LastSeenAt:     lastSeenAt,
		})
		if err != nil {
			continue
		}

		connection.sendEnvelope(envelope)
	}
}

// clearTyping removes the typing state of a disconnecting connection and tells
// the conversation that the user stopped typing.
func (s *Server) clearTyping(userID, connectionID string) {
	if !s.typing.Enabled() || userID == "" {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	conversationIDs, err := s.typing.ClearConnection(ctx, userID, connectionID)
	if err != nil {
		s.metrics.typingErrors.Inc()
		s.logger.Debug("failed to clear typing state", "user_id", userID, "error", err)

		return
	}

	for _, conversationID := range conversationIDs {
		if !validID(conversationID) {
			continue
		}

		s.publishTyping(conversationID, protocol.TypingData{
			ConversationID: conversationID,
			UserID:         userID,
			IsTyping:       false,
			ExpiresAt:      time.Now().UTC().Format(time.RFC3339),
		})
	}
}

func remoteIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}

	return host
}

func writeJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)

	_ = json.NewEncoder(w).Encode(payload)
}
