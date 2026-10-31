package config

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/joho/godotenv"
)

type Config struct {
	InstanceID     string
	Addr           string
	DatabaseURL    string
	RedisURL       string
	NATSURL        string
	AllowedOrigins []string

	TokenSecret string

	HeartbeatInterval time.Duration
	AuthTimeout       time.Duration
	WriteTimeout      time.Duration
	ShutdownTimeout   time.Duration

	OutboundBuffer        int
	MaxSubscriptions      int
	MaxFrameBytes         int64
	MaxConnections        int
	MaxConnectionsPerUser int
	InboundRatePerSecond  float64
	InboundBurst          int

	EventSubjectPrefix  string
	TypingSubjectPrefix string

	// Durable broker. When JetStream is enabled the gateway consumes outbox
	// events from a durable consumer so events survive gateway restarts.
	JetStreamEnabled  bool
	JetStreamStream   string
	JetStreamDurable  string
	JetStreamMaxRetry int

	PresenceSubjectPrefix string
	ReceiptSubjectPrefix  string
	PresenceSubjectTTL    time.Duration
	TypingTTL             time.Duration
	EventDedupeTTL        time.Duration
	EventDedupeLimit      int
}

func Load() (Config, error) {
	_ = godotenv.Load()

	heartbeat, err := duration("GATEWAY_HEARTBEAT_INTERVAL", 30*time.Second)
	if err != nil {
		return Config{}, err
	}
	authTimeout, err := duration("GATEWAY_AUTH_TIMEOUT", 5*time.Second)
	if err != nil {
		return Config{}, err
	}
	writeTimeout, err := duration("GATEWAY_WRITE_TIMEOUT", 10*time.Second)
	if err != nil {
		return Config{}, err
	}
	shutdownTimeout, err := duration("GATEWAY_SHUTDOWN_TIMEOUT", 20*time.Second)
	if err != nil {
		return Config{}, err
	}
	outboundBuffer, err := integer("GATEWAY_OUTBOUND_BUFFER", 128)
	if err != nil {
		return Config{}, err
	}
	maxSubscriptions, err := integer("GATEWAY_MAX_SUBSCRIPTIONS", 250)
	if err != nil {
		return Config{}, err
	}
	maxFrameBytes, err := integer("GATEWAY_MAX_FRAME_BYTES", 64*1024)
	if err != nil {
		return Config{}, err
	}
	maxConnections, err := integer("GATEWAY_MAX_CONNECTIONS", 10000)
	if err != nil {
		return Config{}, err
	}
	maxConnectionsPerUser, err := integer("GATEWAY_MAX_CONNECTIONS_PER_USER", 10)
	if err != nil {
		return Config{}, err
	}
	inboundRate, err := number("GATEWAY_INBOUND_RATE_PER_SECOND", 30)
	if err != nil {
		return Config{}, err
	}
	inboundBurst, err := integer("GATEWAY_INBOUND_BURST", 60)
	if err != nil {
		return Config{}, err
	}

	jetStreamEnabled, err := boolean("GATEWAY_JETSTREAM_ENABLED", true)
	if err != nil {
		return Config{}, err
	}

	jetStreamMaxRetry, err := integer("GATEWAY_JETSTREAM_MAX_DELIVER", 10)
	if err != nil {
		return Config{}, err
	}

	presenceTTL, err := duration("GATEWAY_PRESENCE_TTL", 90*time.Second)
	if err != nil {
		return Config{}, err
	}

	typingTTL, err := duration("GATEWAY_TYPING_TTL", 6*time.Second)
	if err != nil {
		return Config{}, err
	}

	dedupeTTL, err := duration("GATEWAY_EVENT_DEDUPE_TTL", 10*time.Minute)
	if err != nil {
		return Config{}, err
	}

	dedupeLimit, err := integer("GATEWAY_EVENT_DEDUPE_LIMIT", 20000)
	if err != nil {
		return Config{}, err
	}

	instanceID := env("GATEWAY_INSTANCE_ID", "gateway-local")

	cfg := Config{
		InstanceID:     instanceID,
		Addr:           env("GATEWAY_ADDR", ":8081"),
		DatabaseURL:    env("DATABASE_URL", "postgres://postgres:postgres@127.0.0.1:5432/chat_app?sslmode=disable"),
		RedisURL:       env("REDIS_URL", "redis://127.0.0.1:6379/0"),
		NATSURL:        env("NATS_URL", "nats://127.0.0.1:4222"),
		AllowedOrigins: split(env("ALLOWED_ORIGINS", "http://localhost:8000")),
		TokenSecret:    env("GATEWAY_TOKEN_SECRET", "gateway-dev-secret-change-me"),

		HeartbeatInterval: heartbeat,
		AuthTimeout:       authTimeout,
		WriteTimeout:      writeTimeout,
		ShutdownTimeout:   shutdownTimeout,

		OutboundBuffer:        outboundBuffer,
		MaxSubscriptions:      maxSubscriptions,
		MaxFrameBytes:         int64(maxFrameBytes),
		MaxConnections:        maxConnections,
		MaxConnectionsPerUser: maxConnectionsPerUser,
		InboundRatePerSecond:  inboundRate,
		InboundBurst:          inboundBurst,

		EventSubjectPrefix:  env("GATEWAY_EVENT_SUBJECT_PREFIX", "chat.events."),
		TypingSubjectPrefix: env("GATEWAY_TYPING_SUBJECT_PREFIX", "chat.typing."),

		JetStreamEnabled:  jetStreamEnabled,
		JetStreamStream:   env("GATEWAY_JETSTREAM_STREAM", "CHAT_EVENTS"),
		JetStreamDurable:  durableName(env("GATEWAY_JETSTREAM_DURABLE", ""), instanceID),
		JetStreamMaxRetry: jetStreamMaxRetry,

		PresenceSubjectPrefix: env("GATEWAY_PRESENCE_SUBJECT_PREFIX", "chat.presence."),
		ReceiptSubjectPrefix:  env("GATEWAY_RECEIPT_SUBJECT_PREFIX", "chat.receipts."),
		PresenceSubjectTTL:    presenceTTL,
		TypingTTL:             typingTTL,
		EventDedupeTTL:        dedupeTTL,
		EventDedupeLimit:      dedupeLimit,
	}

	if err := cfg.validate(); err != nil {
		return Config{}, err
	}

	return cfg, nil
}

func (c Config) validate() error {
	switch {
	case strings.TrimSpace(c.Addr) == "":
		return fmt.Errorf("GATEWAY_ADDR must not be empty")
	case len(c.TokenSecret) < 16:
		return fmt.Errorf("GATEWAY_TOKEN_SECRET must contain at least 16 characters")
	case len(c.AllowedOrigins) == 0:
		return fmt.Errorf("ALLOWED_ORIGINS must contain at least one origin")
	case c.HeartbeatInterval <= 0:
		return fmt.Errorf("GATEWAY_HEARTBEAT_INTERVAL must be positive")
	case c.AuthTimeout <= 0:
		return fmt.Errorf("GATEWAY_AUTH_TIMEOUT must be positive")
	case c.WriteTimeout <= 0:
		return fmt.Errorf("GATEWAY_WRITE_TIMEOUT must be positive")
	case c.ShutdownTimeout <= 0:
		return fmt.Errorf("GATEWAY_SHUTDOWN_TIMEOUT must be positive")
	case c.OutboundBuffer < 1:
		return fmt.Errorf("GATEWAY_OUTBOUND_BUFFER must be at least 1")
	case c.MaxSubscriptions < 1:
		return fmt.Errorf("GATEWAY_MAX_SUBSCRIPTIONS must be at least 1")
	case c.MaxFrameBytes < 1024:
		return fmt.Errorf("GATEWAY_MAX_FRAME_BYTES must be at least 1024")
	case c.MaxConnections < 1:
		return fmt.Errorf("GATEWAY_MAX_CONNECTIONS must be at least 1")
	case c.MaxConnectionsPerUser < 1:
		return fmt.Errorf("GATEWAY_MAX_CONNECTIONS_PER_USER must be at least 1")
	case c.MaxConnectionsPerUser > c.MaxConnections:
		return fmt.Errorf("GATEWAY_MAX_CONNECTIONS_PER_USER must not exceed GATEWAY_MAX_CONNECTIONS")
	case c.InboundRatePerSecond <= 0:
		return fmt.Errorf("GATEWAY_INBOUND_RATE_PER_SECOND must be positive")
	case c.InboundBurst < 1:
		return fmt.Errorf("GATEWAY_INBOUND_BURST must be at least 1")
	case strings.TrimSpace(c.EventSubjectPrefix) == "":
		return fmt.Errorf("GATEWAY_EVENT_SUBJECT_PREFIX must not be empty")
	case strings.TrimSpace(c.TypingSubjectPrefix) == "":
		return fmt.Errorf("GATEWAY_TYPING_SUBJECT_PREFIX must not be empty")
	case strings.TrimSpace(c.PresenceSubjectPrefix) == "":
		return fmt.Errorf("GATEWAY_PRESENCE_SUBJECT_PREFIX must not be empty")
	case strings.TrimSpace(c.ReceiptSubjectPrefix) == "":
		return fmt.Errorf("GATEWAY_RECEIPT_SUBJECT_PREFIX must not be empty")
	case c.JetStreamEnabled && strings.TrimSpace(c.JetStreamStream) == "":
		return fmt.Errorf("GATEWAY_JETSTREAM_STREAM must not be empty when JetStream is enabled")
	case c.JetStreamEnabled && strings.TrimSpace(c.JetStreamDurable) == "":
		return fmt.Errorf("GATEWAY_JETSTREAM_DURABLE must not be empty when JetStream is enabled")
	case c.JetStreamMaxRetry < 1:
		return fmt.Errorf("GATEWAY_JETSTREAM_MAX_DELIVER must be at least 1")
	case c.PresenceSubjectTTL <= 0:
		return fmt.Errorf("GATEWAY_PRESENCE_TTL must be positive")
	case c.TypingTTL <= 0:
		return fmt.Errorf("GATEWAY_TYPING_TTL must be positive")
	case c.EventDedupeTTL <= 0:
		return fmt.Errorf("GATEWAY_EVENT_DEDUPE_TTL must be positive")
	case c.EventDedupeLimit < 1:
		return fmt.Errorf("GATEWAY_EVENT_DEDUPE_LIMIT must be at least 1")
	}

	return nil
}

func env(key, fallback string) string {
	if value, ok := os.LookupEnv(key); ok {
		return value
	}

	return fallback
}

func duration(key string, fallback time.Duration) (time.Duration, error) {
	value, ok := os.LookupEnv(key)
	if !ok {
		return fallback, nil
	}

	parsed, err := time.ParseDuration(value)
	if err != nil {
		return 0, fmt.Errorf("%s must be a duration: %w", key, err)
	}

	return parsed, nil
}

func integer(key string, fallback int) (int, error) {
	value, ok := os.LookupEnv(key)
	if !ok {
		return fallback, nil
	}

	parsed, err := strconv.Atoi(value)
	if err != nil {
		return 0, fmt.Errorf("%s must be an integer: %w", key, err)
	}

	return parsed, nil
}

func number(key string, fallback float64) (float64, error) {
	value, ok := os.LookupEnv(key)
	if !ok {
		return fallback, nil
	}

	parsed, err := strconv.ParseFloat(value, 64)
	if err != nil {
		return 0, fmt.Errorf("%s must be a number: %w", key, err)
	}

	return parsed, nil
}

func boolean(key string, fallback bool) (bool, error) {
	value, ok := os.LookupEnv(key)
	if !ok {
		return fallback, nil
	}

	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return false, fmt.Errorf("%s must be a boolean: %w", key, err)
	}

	return parsed, nil
}

// durableName derives the JetStream consumer name. Every gateway instance needs
// its own durable consumer: a shared one would load balance events across
// instances instead of fanning them out to every instance's clients.
func durableName(configured, instanceID string) string {
	if trimmed := strings.TrimSpace(configured); trimmed != "" {
		return trimmed
	}

	return "gateway-" + instanceID
}

func split(value string) []string {
	parts := strings.Split(value, ",")
	values := make([]string, 0, len(parts))

	for _, part := range parts {
		if trimmed := strings.TrimSpace(part); trimmed != "" {
			values = append(values, trimmed)
		}
	}

	return values
}
