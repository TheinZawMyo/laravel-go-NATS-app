package outbox

import (
	"context"
	"testing"
	"time"
)

func TestEventIDReadsTheEnvelopeEventID(t *testing.T) {
	payload := []byte(`{"version":1,"event_id":" 9f6c1fdb-0000-4000-8000-000000000000 ","type":"message.created"}`)

	if id := EventID(payload); id != "9f6c1fdb-0000-4000-8000-000000000000" {
		t.Fatalf("EventID() = %q", id)
	}
}

func TestEventIDIgnoresPayloadsWithoutAnEnvelope(t *testing.T) {
	cases := [][]byte{
		nil,
		[]byte("not json"),
		[]byte(`{"type":"message.created"}`),
		[]byte(`{"event_id":""}`),
	}

	for _, payload := range cases {
		if id := EventID(payload); id != "" {
			t.Errorf("EventID(%s) = %q, want empty", payload, id)
		}
	}
}

func TestJetStreamConfigNormalizesDefaults(t *testing.T) {
	cfg := JetStreamConfig{}.normalize()

	if cfg.Stream != "CHAT_EVENTS" {
		t.Errorf("Stream = %q, want CHAT_EVENTS", cfg.Stream)
	}

	if len(cfg.Subjects) != 1 || cfg.Subjects[0] != "chat.events.>" {
		t.Errorf("Subjects = %v, want [chat.events.>]", cfg.Subjects)
	}

	if cfg.MaxAge != 24*time.Hour {
		t.Errorf("MaxAge = %s, want 24h", cfg.MaxAge)
	}

	if cfg.Replicas != 1 {
		t.Errorf("Replicas = %d, want 1", cfg.Replicas)
	}
}

func TestNewJetStreamPublisherRequiresAConnection(t *testing.T) {
	if _, err := NewJetStreamPublisher(context.Background(), nil, JetStreamConfig{}); err == nil {
		t.Fatal("expected an error for a nil nats connection")
	}
}
