package config

import (
	"fmt"
	"os"

	"github.com/joho/godotenv"
	"strconv"
	"time"
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
	}

	if cfg.EventSubjectPrefix == "" {
		return Config{}, fmt.Errorf("EVENT_SUBJECT_PREFIX must not be empty")
	}

	return cfg, nil
}

func env(key, fallback string) string {
	if value, ok := os.LookupEnv(key); ok {
		return value
	}

	return fallback
}
