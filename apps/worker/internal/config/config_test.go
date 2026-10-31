package config

import (
	"os"
	"testing"
)

// clearEnv removes the variables that affect Config so a developer's local
// .env file cannot leak into the assertions, restoring them afterwards.
func clearEnv(t *testing.T, keys ...string) {
	t.Helper()

	for _, key := range keys {
		key := key

		value, ok := os.LookupEnv(key)
		if err := os.Unsetenv(key); err != nil {
			t.Fatalf("failed to unset %s: %v", key, err)
		}

		t.Cleanup(func() {
			if ok {
				_ = os.Setenv(key, value)

				return
			}

			_ = os.Unsetenv(key)
		})
	}
}

func TestLoadAppliesOutboxDefaults(t *testing.T) {
	clearEnv(t,
		"EVENT_SUBJECT_PREFIX",
		"WORKER_OUTBOX_POLL_MS",
		"WORKER_OUTBOX_BATCH_SIZE",
		"WORKER_OUTBOX_MAX_ATTEMPTS",
	)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned an error: %v", err)
	}

	if cfg.EventSubjectPrefix != "chat.events." {
		t.Errorf("EventSubjectPrefix = %q, want chat.events.", cfg.EventSubjectPrefix)
	}

	if cfg.OutboxPollEvery.Milliseconds() != 250 {
		t.Errorf("OutboxPollEvery = %s, want 250ms", cfg.OutboxPollEvery)
	}

	if cfg.OutboxBatchSize != 100 {
		t.Errorf("OutboxBatchSize = %d, want 100", cfg.OutboxBatchSize)
	}

	if cfg.OutboxMaxAttempts != 10 {
		t.Errorf("OutboxMaxAttempts = %d, want 10", cfg.OutboxMaxAttempts)
	}
}

func TestLoadParsesOutboxOverrides(t *testing.T) {
	clearEnv(t,
		"EVENT_SUBJECT_PREFIX",
		"WORKER_OUTBOX_POLL_MS",
		"WORKER_OUTBOX_BATCH_SIZE",
		"WORKER_OUTBOX_MAX_ATTEMPTS",
	)

	t.Setenv("EVENT_SUBJECT_PREFIX", "custom.events.")
	t.Setenv("WORKER_OUTBOX_POLL_MS", "100")
	t.Setenv("WORKER_OUTBOX_BATCH_SIZE", "10")
	t.Setenv("WORKER_OUTBOX_MAX_ATTEMPTS", "3")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned an error: %v", err)
	}

	if cfg.EventSubjectPrefix != "custom.events." {
		t.Errorf("EventSubjectPrefix = %q, want custom.events.", cfg.EventSubjectPrefix)
	}

	if cfg.OutboxPollEvery.Milliseconds() != 100 {
		t.Errorf("OutboxPollEvery = %s, want 100ms", cfg.OutboxPollEvery)
	}

	if cfg.OutboxBatchSize != 10 {
		t.Errorf("OutboxBatchSize = %d, want 10", cfg.OutboxBatchSize)
	}

	if cfg.OutboxMaxAttempts != 3 {
		t.Errorf("OutboxMaxAttempts = %d, want 3", cfg.OutboxMaxAttempts)
	}
}

func TestLoadRejectsInvalidOutboxSettings(t *testing.T) {
	cases := map[string]string{
		"WORKER_OUTBOX_POLL_MS":      "10",
		"WORKER_OUTBOX_BATCH_SIZE":   "0",
		"WORKER_OUTBOX_MAX_ATTEMPTS": "-1",
	}

	for key, value := range cases {
		clearEnv(t, key)
		t.Setenv(key, value)

		if _, err := Load(); err == nil {
			t.Errorf("expected Load() to reject %s=%q", key, value)
		}
	}
}
