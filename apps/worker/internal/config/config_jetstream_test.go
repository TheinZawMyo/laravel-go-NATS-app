package config

import (
	"testing"
	"time"
)

func TestLoadAppliesJetStreamDefaults(t *testing.T) {
	clearEnv(t,
		"WORKER_JETSTREAM_ENABLED",
		"WORKER_JETSTREAM_STREAM",
		"WORKER_JETSTREAM_MAX_AGE",
		"WORKER_JETSTREAM_REPLICAS",
		"WORKER_BACKLOG_INTERVAL",
		"EVENT_SUBJECT_PREFIX",
	)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned an error: %v", err)
	}

	if !cfg.JetStreamEnabled {
		t.Error("JetStreamEnabled = false, want true")
	}

	if cfg.JetStreamStream != "CHAT_EVENTS" {
		t.Errorf("JetStreamStream = %q, want CHAT_EVENTS", cfg.JetStreamStream)
	}

	if cfg.JetStreamMaxAge != 24*time.Hour {
		t.Errorf("JetStreamMaxAge = %s, want 24h", cfg.JetStreamMaxAge)
	}

	if cfg.JetStreamReplicas != 1 {
		t.Errorf("JetStreamReplicas = %d, want 1", cfg.JetStreamReplicas)
	}

	if cfg.BacklogInterval != 15*time.Second {
		t.Errorf("BacklogInterval = %s, want 15s", cfg.BacklogInterval)
	}

	subjects := cfg.JetStreamSubjects()
	if len(subjects) != 1 || subjects[0] != "chat.events.>" {
		t.Errorf("JetStreamSubjects() = %v, want [chat.events.>]", subjects)
	}
}

func TestLoadParsesJetStreamOverrides(t *testing.T) {
	clearEnv(t,
		"WORKER_JETSTREAM_ENABLED",
		"WORKER_JETSTREAM_STREAM",
		"WORKER_JETSTREAM_MAX_AGE",
		"WORKER_JETSTREAM_REPLICAS",
		"WORKER_BACKLOG_INTERVAL",
	)

	t.Setenv("WORKER_JETSTREAM_ENABLED", "false")
	t.Setenv("WORKER_JETSTREAM_STREAM", "CUSTOM_EVENTS")
	t.Setenv("WORKER_JETSTREAM_MAX_AGE", "2h")
	t.Setenv("WORKER_JETSTREAM_REPLICAS", "3")
	t.Setenv("WORKER_BACKLOG_INTERVAL", "5s")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned an error: %v", err)
	}

	if cfg.JetStreamEnabled {
		t.Error("JetStreamEnabled = true, want false")
	}

	if cfg.JetStreamStream != "CUSTOM_EVENTS" {
		t.Errorf("JetStreamStream = %q, want CUSTOM_EVENTS", cfg.JetStreamStream)
	}

	if cfg.JetStreamMaxAge != 2*time.Hour {
		t.Errorf("JetStreamMaxAge = %s, want 2h", cfg.JetStreamMaxAge)
	}

	if cfg.JetStreamReplicas != 3 {
		t.Errorf("JetStreamReplicas = %d, want 3", cfg.JetStreamReplicas)
	}

	if cfg.BacklogInterval != 5*time.Second {
		t.Errorf("BacklogInterval = %s, want 5s", cfg.BacklogInterval)
	}
}

func TestLoadRejectsInvalidJetStreamSettings(t *testing.T) {
	cases := map[string]string{
		"WORKER_JETSTREAM_ENABLED":  "sometimes",
		"WORKER_JETSTREAM_MAX_AGE":  "forever",
		"WORKER_JETSTREAM_REPLICAS": "0",
		"WORKER_BACKLOG_INTERVAL":   "0s",
	}

	for key, value := range cases {
		clearEnv(t, key)
		t.Setenv(key, value)

		if _, err := Load(); err == nil {
			t.Errorf("expected Load() to reject %s=%q", key, value)
		}
	}
}

func TestLoadRejectsAnEmptyStreamNameWhenJetStreamIsEnabled(t *testing.T) {
	clearEnv(t, "WORKER_JETSTREAM_STREAM", "WORKER_JETSTREAM_ENABLED")

	t.Setenv("WORKER_JETSTREAM_ENABLED", "true")
	t.Setenv("WORKER_JETSTREAM_STREAM", "")

	if _, err := Load(); err == nil {
		t.Error("expected Load() to reject an empty stream name")
	}
}
