package outbox

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
)

// JetStream keeps a durable copy of every outbox event. The stream is the
// single durable broker of the platform: the worker publishes into it, gateways
// consume from it, and an event stays readable for DuplicateWindow so a retried
// publish cannot fan out twice.
const (
	DuplicateWindow = 2 * time.Minute
	publishTimeout  = 10 * time.Second
)

// JetStreamConfig describes the stream the worker owns.
type JetStreamConfig struct {
	Stream   string
	Subjects []string
	MaxAge   time.Duration
	Replicas int
}

func (c JetStreamConfig) normalize() JetStreamConfig {
	if c.Stream == "" {
		c.Stream = "CHAT_EVENTS"
	}

	if len(c.Subjects) == 0 {
		c.Subjects = []string{"chat.events.>"}
	}

	if c.MaxAge <= 0 {
		c.MaxAge = 24 * time.Hour
	}

	if c.Replicas < 1 {
		c.Replicas = 1
	}

	return c
}

// JetStreamPublisher publishes each outbox event synchronously. A publish only
// succeeds once the broker stored and acknowledged the message, which is what
// lets the dispatcher mark the row published without a separate flush.
type JetStreamPublisher struct {
	js  jetstream.JetStream
	cfg JetStreamConfig
}

func NewJetStreamPublisher(ctx context.Context, conn *nats.Conn, cfg JetStreamConfig) (*JetStreamPublisher, error) {
	if conn == nil {
		return nil, errors.New("jetstream publisher requires a nats connection")
	}

	js, err := jetstream.New(conn)
	if err != nil {
		return nil, fmt.Errorf("create jetstream context: %w", err)
	}

	cfg = cfg.normalize()

	if _, err := js.CreateOrUpdateStream(ctx, jetstream.StreamConfig{
		Name:        cfg.Stream,
		Description: "Durable realtime events written by the transactional outbox.",
		Subjects:    cfg.Subjects,
		Retention:   jetstream.LimitsPolicy,
		MaxAge:      cfg.MaxAge,
		MaxMsgSize:  -1,
		Storage:     jetstream.FileStorage,
		Discard:     jetstream.DiscardOld,
		Replicas:    cfg.Replicas,
		Duplicates:  DuplicateWindow,
	}); err != nil {
		return nil, fmt.Errorf("ensure stream %s: %w", cfg.Stream, err)
	}

	return &JetStreamPublisher{js: js, cfg: cfg}, nil
}

// Stream returns the stream name the publisher guarantees.
func (p *JetStreamPublisher) Stream() string {
	return p.cfg.Stream
}

func (p *JetStreamPublisher) Publish(subject string, payload []byte) error {
	ctx, cancel := context.WithTimeout(context.Background(), publishTimeout)
	defer cancel()

	options := make([]jetstream.PublishOpt, 0, 1)

	// The envelope event id doubles as the broker message id, so re-publishing a
	// row after an ambiguous failure is de-duplicated inside DuplicateWindow.
	if id := EventID(payload); id != "" {
		options = append(options, jetstream.WithMsgID(id))
	}

	if _, err := p.js.Publish(ctx, subject, payload, options...); err != nil {
		return fmt.Errorf("publish %s to jetstream: %w", subject, err)
	}

	return nil
}

// Flush is a no-op because Publish waits for the broker acknowledgement.
func (p *JetStreamPublisher) Flush() error {
	return nil
}

// EventID reads the envelope event id used for broker side deduplication.
func EventID(payload []byte) string {
	var envelope struct {
		EventID string `json:"event_id"`
	}

	if err := json.Unmarshal(payload, &envelope); err != nil {
		return ""
	}

	return strings.TrimSpace(envelope.EventID)
}
