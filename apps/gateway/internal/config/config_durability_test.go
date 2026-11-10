package config

import (
	"testing"
	"time"
)

func TestLoadAppliesDurabilityDefaults(t *testing.T) {
	clearEnv(t,
		"GATEWAY_INSTANCE_ID",
		"GATEWAY_JETSTREAM_ENABLED",
		"GATEWAY_JETSTREAM_STREAM",
		"GATEWAY_JETSTREAM_DURABLE",
		"GATEWAY_JETSTREAM_MAX_DELIVER",
		"GATEWAY_PRESENCE_SUBJECT_PREFIX",
		"GATEWAY_RECEIPT_SUBJECT_PREFIX",
		"GATEWAY_CALL_SUBJECT_PREFIX",
		"GATEWAY_ICE_SERVERS",
		"GATEWAY_PRESENCE_TTL",
		"GATEWAY_TYPING_TTL",
		"GATEWAY_EVENT_DEDUPE_TTL",
		"GATEWAY_EVENT_DEDUPE_LIMIT",
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

	// Every gateway instance needs its own durable consumer, otherwise events
	// are load balanced across instances instead of fanned out.
	if cfg.JetStreamDurable != "gateway-"+cfg.InstanceID {
		t.Errorf("JetStreamDurable = %q, want gateway-%s", cfg.JetStreamDurable, cfg.InstanceID)
	}

	if cfg.PresenceSubjectPrefix != "chat.presence." {
		t.Errorf("PresenceSubjectPrefix = %q, want chat.presence.", cfg.PresenceSubjectPrefix)
	}

	if cfg.ReceiptSubjectPrefix != "chat.receipts." {
		t.Errorf("ReceiptSubjectPrefix = %q, want chat.receipts.", cfg.ReceiptSubjectPrefix)
	}
	if cfg.CallSubjectPrefix != "chat.calls." {
		t.Errorf("CallSubjectPrefix = %q, want chat.calls.", cfg.CallSubjectPrefix)
	}
	if cfg.CallICEServers != `[{"urls":["stun:stun.l.google.com:19302"]}]` {
		t.Errorf("CallICEServers = %q", cfg.CallICEServers)
	}

	if cfg.PresenceSubjectTTL != 90*time.Second {
		t.Errorf("PresenceSubjectTTL = %v, want 90s", cfg.PresenceSubjectTTL)
	}

	if cfg.TypingTTL != 6*time.Second {
		t.Errorf("TypingTTL = %v, want 6s", cfg.TypingTTL)
	}

	if cfg.EventDedupeTTL != 10*time.Minute {
		t.Errorf("EventDedupeTTL = %v, want 10m", cfg.EventDedupeTTL)
	}

	if cfg.EventDedupeLimit != 20000 {
		t.Errorf("EventDedupeLimit = %d, want 20000", cfg.EventDedupeLimit)
	}
}

func TestLoadParsesDurabilityOverrides(t *testing.T) {
	clearEnv(t, "GATEWAY_INSTANCE_ID")
	t.Setenv("GATEWAY_INSTANCE_ID", "gateway-a")
	t.Setenv("GATEWAY_JETSTREAM_ENABLED", "false")
	t.Setenv("GATEWAY_JETSTREAM_STREAM", "CHAT_EVENTS_ALT")
	t.Setenv("GATEWAY_JETSTREAM_DURABLE", "custom-durable")
	t.Setenv("GATEWAY_JETSTREAM_MAX_DELIVER", "3")
	t.Setenv("GATEWAY_PRESENCE_SUBJECT_PREFIX", "presence.")
	t.Setenv("GATEWAY_RECEIPT_SUBJECT_PREFIX", "receipts.")
	t.Setenv("GATEWAY_CALL_SUBJECT_PREFIX", "calls.")
	t.Setenv("GATEWAY_PRESENCE_TTL", "45s")
	t.Setenv("GATEWAY_TYPING_TTL", "3s")
	t.Setenv("GATEWAY_EVENT_DEDUPE_TTL", "1m")
	t.Setenv("GATEWAY_EVENT_DEDUPE_LIMIT", "500")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned an error: %v", err)
	}

	if cfg.JetStreamEnabled {
		t.Error("JetStreamEnabled = true, want false")
	}

	if cfg.JetStreamStream != "CHAT_EVENTS_ALT" || cfg.JetStreamDurable != "custom-durable" {
		t.Errorf("unexpected jetstream config: %q / %q", cfg.JetStreamStream, cfg.JetStreamDurable)
	}

	if cfg.JetStreamMaxRetry != 3 {
		t.Errorf("JetStreamMaxRetry = %d, want 3", cfg.JetStreamMaxRetry)
	}

	if cfg.PresenceSubjectTTL != 45*time.Second || cfg.TypingTTL != 3*time.Second {
		t.Errorf("unexpected ephemeral ttls: %v / %v", cfg.PresenceSubjectTTL, cfg.TypingTTL)
	}

	if cfg.EventDedupeTTL != time.Minute || cfg.EventDedupeLimit != 500 {
		t.Errorf("unexpected dedupe config: %v / %d", cfg.EventDedupeTTL, cfg.EventDedupeLimit)
	}
}

func TestLoadRejectsInvalidDurabilitySettings(t *testing.T) {
	tests := map[string]string{
		"GATEWAY_EVENT_DEDUPE_LIMIT":    "0",
		"GATEWAY_CALL_SUBJECT_PREFIX":   " ",
		"GATEWAY_ICE_SERVERS":           "not-json",
		"GATEWAY_EVENT_DEDUPE_TTL":      "0s",
		"GATEWAY_TYPING_TTL":            "-1s",
		"GATEWAY_PRESENCE_TTL":          "0s",
		"GATEWAY_JETSTREAM_MAX_DELIVER": "0",
		"GATEWAY_JETSTREAM_ENABLED":     "sometimes",
	}

	for key, value := range tests {
		t.Run(key, func(t *testing.T) {
			t.Setenv(key, value)

			if _, err := Load(); err == nil {
				t.Fatalf("Load() accepted %s=%q", key, value)
			}
		})
	}
}
