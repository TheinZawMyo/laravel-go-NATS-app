package config

import (
	"fmt"
	"os"
	"strconv"
	"time"

	"github.com/joho/godotenv"
)

type Config struct {
	InstanceID  string
	Addr        string
	DatabaseURL string
	RedisURL    string
	NATSURL     string
	PollEvery   time.Duration

	// Outbox dispatcher.
	EventSubjectPrefix string
	OutboxPollEvery    time.Duration
	OutboxBatchSize    int
	OutboxMaxAttempts  int

	// Durable broker. JetStream keeps published events on disk so a gateway
	// that restarts, or one that joins late, can still consume them.
	JetStreamEnabled  bool
	JetStreamStream   string
	JetStreamMaxAge   time.Duration
	JetStreamReplicas int

	// Outbox backlog monitoring.
	BacklogInterval time.Duration
}

// JetStreamSubjects returns the broker subjects captured by the durable stream.
// Only permanent outbox events are retained; typing and presence stay
// ephemeral and never enter the stream.
func (c Config) JetStreamSubjects() []string {
	return []string{c.EventSubjectPrefix + ">"}
}

func Load() (Config, error) {
	_ = godotenv.Load()

	pollSeconds, err := strconv.Atoi(env("WORKER_POLL_SECONDS", "2"))
	if err != nil || pollSeconds < 1 {
		return Config{}, fmt.Errorf("WORKER_POLL_SECONDS must be a positive integer")
	}

	outboxPollMillis, err := strconv.Atoi(env("WORKER_OUTBOX_POLL_MS", "250"))
	if err != nil || outboxPollMillis < 50 {
		return Config{}, fmt.Errorf("WORKER_OUTBOX_POLL_MS must be an integer of at least 50")
	}

	outboxBatchSize, err := strconv.Atoi(env("WORKER_OUTBOX_BATCH_SIZE", "100"))
	if err != nil || outboxBatchSize < 1 {
		return Config{}, fmt.Errorf("WORKER_OUTBOX_BATCH_SIZE must be a positive integer")
	}

	outboxMaxAttempts, err := strconv.Atoi(env("WORKER_OUTBOX_MAX_ATTEMPTS", "10"))
	if err != nil || outboxMaxAttempts < 1 {
		return Config{}, fmt.Errorf("WORKER_OUTBOX_MAX_ATTEMPTS must be a positive integer")
	}

	jetStreamEnabled, err := boolean("WORKER_JETSTREAM_ENABLED", true)
	if err != nil {
		return Config{}, err
	}

	jetStreamMaxAge, err := duration("WORKER_JETSTREAM_MAX_AGE", 24*time.Hour)
	if err != nil {
		return Config{}, err
	}

	jetStreamReplicas, err := integer("WORKER_JETSTREAM_REPLICAS", 1)
	if err != nil {
		return Config{}, err
	}

	backlogInterval, err := duration("WORKER_BACKLOG_INTERVAL", 15*time.Second)
	if err != nil {
		return Config{}, err
	}

	cfg := Config{
		InstanceID:         env("WORKER_INSTANCE_ID", "worker-local"),
		Addr:               env("WORKER_ADDR", ":8082"),
		DatabaseURL:        env("DATABASE_URL", "postgres://postgres:postgres@127.0.0.1:5432/chat_app?sslmode=disable"),
		RedisURL:           env("REDIS_URL", "redis://127.0.0.1:6379/0"),
		NATSURL:            env("NATS_URL", "nats://127.0.0.1:4222"),
		PollEvery:          time.Duration(pollSeconds) * time.Second,
		EventSubjectPrefix: env("EVENT_SUBJECT_PREFIX", "chat.events."),
		OutboxPollEvery:    time.Duration(outboxPollMillis) * time.Millisecond,
		OutboxBatchSize:    outboxBatchSize,
		OutboxMaxAttempts:  outboxMaxAttempts,
		JetStreamEnabled:   jetStreamEnabled,
		JetStreamStream:    env("WORKER_JETSTREAM_STREAM", "CHAT_EVENTS"),
		JetStreamMaxAge:    jetStreamMaxAge,
		JetStreamReplicas:  jetStreamReplicas,
		BacklogInterval:    backlogInterval,
	}

	if err := cfg.validate(); err != nil {
		return Config{}, err
	}

	return cfg, nil
}

func (c Config) validate() error {
	switch {
	case c.EventSubjectPrefix == "":
		return fmt.Errorf("EVENT_SUBJECT_PREFIX must not be empty")
	case c.JetStreamEnabled && c.JetStreamStream == "":
		return fmt.Errorf("WORKER_JETSTREAM_STREAM must not be empty when JetStream is enabled")
	case c.JetStreamMaxAge <= 0:
		return fmt.Errorf("WORKER_JETSTREAM_MAX_AGE must be positive")
	case c.JetStreamReplicas < 1:
		return fmt.Errorf("WORKER_JETSTREAM_REPLICAS must be at least 1")
	case c.BacklogInterval <= 0:
		return fmt.Errorf("WORKER_BACKLOG_INTERVAL must be positive")
	}

	return nil
}

func env(key, fallback string) string {
	if value, ok := os.LookupEnv(key); ok {
		return value
	}

	return fallback
}

func boolean(key string, fallback bool) (bool, error) {
	value, ok := os.LookupEnv(key)
	if !ok || value == "" {
		return fallback, nil
	}

	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return false, fmt.Errorf("%s must be a boolean: %w", key, err)
	}

	return parsed, nil
}

func duration(key string, fallback time.Duration) (time.Duration, error) {
	value, ok := os.LookupEnv(key)
	if !ok || value == "" {
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
	if !ok || value == "" {
		return fallback, nil
	}

	parsed, err := strconv.Atoi(value)
	if err != nil {
		return 0, fmt.Errorf("%s must be an integer: %w", key, err)
	}

	return parsed, nil
}
