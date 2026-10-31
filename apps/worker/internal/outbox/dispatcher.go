// Package outbox publishes transactional outbox rows to the broker.
//
// Laravel writes one row per realtime event inside the same transaction as the
// domain change. This package claims unpublished rows, publishes their payload
// to NATS, and records the outcome, so a committed event is never lost when the
// broker or the worker is briefly unavailable.
package outbox

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"sync"
	"time"

	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/promauto"
)

// Event is one claimed outbox row.
type Event struct {
	ID            string
	AggregateType string
	AggregateID   string
	EventType     string
	Payload       []byte
	Attempts      int
}

// Batch is a locked set of events that must be finalized by Commit or Rollback.
type Batch interface {
	Events() []Event
	Published(ctx context.Context, event Event) error
	Failed(ctx context.Context, event Event, cause error, permanent bool) error
	Commit(ctx context.Context) error
	Rollback(ctx context.Context)
}

// Claimer locks a batch of due events.
type Claimer interface {
	Claim(ctx context.Context, limit int, maxAttempts int) (Batch, error)
}

// Publisher is the broker side of the pipeline.
type Publisher interface {
	Publish(subject string, payload []byte) error
}

// Options configures the dispatch loop.
type Options struct {
	SubjectPrefix string
	BatchSize     int
	MaxAttempts   int
	Interval      time.Duration
}

func (o Options) normalize() Options {
	if o.SubjectPrefix == "" {
		o.SubjectPrefix = "chat.events."
	}

	if o.BatchSize < 1 {
		o.BatchSize = 100
	}

	if o.MaxAttempts < 1 {
		o.MaxAttempts = 10
	}

	if o.Interval <= 0 {
		o.Interval = 250 * time.Millisecond
	}

	return o
}

// Dispatcher drains the outbox on a fixed interval.
type Dispatcher struct {
	claimer   Claimer
	publisher Publisher
	logger    *slog.Logger
	opts      Options

	published *prometheus.CounterVec
	failed    *prometheus.CounterVec
}

var (
	metricsOnce sync.Once
	published   *prometheus.CounterVec
	failed      *prometheus.CounterVec
)

func outboxMetrics() (*prometheus.CounterVec, *prometheus.CounterVec) {
	metricsOnce.Do(func() {
		published = promauto.NewCounterVec(prometheus.CounterOpts{
			Name: "worker_outbox_published_total",
			Help: "Outbox events successfully published to the broker.",
		}, []string{"event_type"})

		failed = promauto.NewCounterVec(prometheus.CounterOpts{
			Name: "worker_outbox_failed_total",
			Help: "Outbox publish attempts that failed.",
		}, []string{"event_type", "reason"})
	})

	return published, failed
}

func New(claimer Claimer, publisher Publisher, logger *slog.Logger, opts Options) *Dispatcher {
	if logger == nil {
		logger = slog.Default()
	}

	publishedCounter, failedCounter := outboxMetrics()

	return &Dispatcher{
		claimer:   claimer,
		publisher: publisher,
		logger:    logger,
		opts:      opts.normalize(),
		published: publishedCounter,
		failed:    failedCounter,
	}
}

// Run dispatches until the context is cancelled.
func (d *Dispatcher) Run(ctx context.Context) {
	ticker := time.NewTicker(d.opts.Interval)
	defer ticker.Stop()

	for {
		if _, err := d.DispatchOnce(ctx); err != nil && !errors.Is(err, context.Canceled) {
			d.logger.Error("outbox dispatch failed", "error", err)
		}

		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}

// DispatchOnce publishes at most one batch and returns how many events were
// published.
func (d *Dispatcher) DispatchOnce(ctx context.Context) (int, error) {
	batch, err := d.claimer.Claim(ctx, d.opts.BatchSize, d.opts.MaxAttempts)
	if err != nil {
		return 0, fmt.Errorf("claim outbox batch: %w", err)
	}

	if batch == nil {
		return 0, nil
	}

	events := batch.Events()
	if len(events) == 0 {
		batch.Rollback(ctx)

		return 0, nil
	}

	published := 0
	sent := make([]Event, 0, len(events))

	for _, event := range events {
		subject, err := Subject(d.opts.SubjectPrefix, event.AggregateType, event.AggregateID)
		if err != nil {
			// No amount of retrying fixes an unroutable event.
			d.failed.WithLabelValues(event.EventType, "invalid_routing").Inc()

			if err := batch.Failed(ctx, event, err, true); err != nil {
				d.logger.Warn("failed to record unroutable outbox event", "event_id", event.ID, "error", err)
			}

			continue
		}

		if err := d.publisher.Publish(subject, event.Payload); err != nil {
			d.failed.WithLabelValues(event.EventType, "publish").Inc()
			d.logger.Warn(
				"publishing outbox event failed",
				"event_id", event.ID,
				"event_type", event.EventType,
				"subject", subject,
				"attempt", event.Attempts+1,
				"error", err,
			)

			if err := batch.Failed(ctx, event, err, false); err != nil {
				d.logger.Warn("failed to record outbox failure", "event_id", event.ID, "error", err)
			}

			continue
		}

		sent = append(sent, event)
	}

	// Only acknowledge after the broker confirmed receipt, so a buffered publish
	// that never left the worker is retried instead of being marked delivered.
	if err := flush(d.publisher); err != nil {
		for _, event := range sent {
			d.failed.WithLabelValues(event.EventType, "publish").Inc()
			d.logger.Warn(
				"outbox flush failed",
				"event_id", event.ID,
				"event_type", event.EventType,
				"attempt", event.Attempts+1,
				"error", err,
			)

			if err := batch.Failed(ctx, event, err, false); err != nil {
				d.logger.Warn("failed to record outbox failure", "event_id", event.ID, "error", err)
			}
		}

		sent = nil
	}

	for _, event := range sent {
		if err := batch.Published(ctx, event); err != nil {
			return published, fmt.Errorf("mark outbox event published: %w", err)
		}

		published++
		d.published.WithLabelValues(event.EventType).Inc()
	}

	if err := batch.Commit(ctx); err != nil {
		return 0, fmt.Errorf("commit outbox batch: %w", err)
	}

	return published, nil
}

// Flusher is implemented by publishers that buffer writes and can confirm the
// broker accepted them.
type Flusher interface {
	Flush() error
}

func flush(publisher Publisher) error {
	flusher, ok := publisher.(Flusher)
	if !ok {
		return nil
	}

	return flusher.Flush()
}

// Subject maps an outbox aggregate onto the broker subject the gateway
// subscribes to. Message events use the conversation aggregate so they fan out
// to every subscriber of that conversation.
func Subject(prefix, aggregateType, aggregateID string) (string, error) {
	aggregateID = strings.TrimSpace(aggregateID)

	if aggregateID == "" {
		return "", errors.New("outbox event has no aggregate id")
	}

	switch aggregateType {
	case "conversation", "user":
		return prefix + aggregateType + "." + aggregateID, nil
	case "":
		return "", errors.New("outbox event has no aggregate type")
	default:
		return "", fmt.Errorf("unsupported outbox aggregate type %q", aggregateType)
	}
}

// Backoff returns the delay before retrying an event that has already failed
// attempts times, capped at max.
func Backoff(attempts int, base, max time.Duration) time.Duration {
	if attempts < 1 {
		attempts = 1
	}

	delay := base

	for i := 1; i < attempts && delay < max; i++ {
		delay *= 2
	}

	if delay > max {
		return max
	}

	return delay
}
