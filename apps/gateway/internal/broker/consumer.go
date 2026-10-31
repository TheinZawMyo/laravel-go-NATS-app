// Package broker consumes durable realtime events from JetStream.
//
// The worker publishes every outbox event into a JetStream stream. The gateway
// consumes that stream through a durable consumer named per instance, so an
// event committed while a gateway was restarting is still delivered once the
// gateway comes back. Core NATS remains the fallback for deployments where
// JetStream is unavailable.
package broker

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"sync"
	"time"

	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
)

// Handler processes one broker payload. Returning an error asks the durable
// consumer to redeliver the message, so handlers must be idempotent.
type Handler func(subject string, payload []byte) error

type Options struct {
	// Enabled switches between the durable consumer and a core subscription.
	Enabled bool
	Stream  string
	Durable string
	Subject string
	// MaxDeliver caps redelivery attempts before the broker gives up.
	MaxDeliver int
}

type Consumer struct {
	options Options
	logger  *slog.Logger
	conn    *nats.Conn

	mu      sync.Mutex
	consume jetstream.ConsumeContext
	sub     *nats.Subscription
}

func New(conn *nats.Conn, logger *slog.Logger, options Options) *Consumer {
	if logger == nil {
		logger = slog.Default()
	}

	if options.MaxDeliver < 1 {
		options.MaxDeliver = 10
	}

	return &Consumer{conn: conn, logger: logger, options: options}
}

// Start subscribes and returns immediately once the consumer is running.
func (c *Consumer) Start(handler Handler) error {
	if c.conn == nil {
		return nil
	}

	if c.options.Enabled {
		err := c.startDurable(handler)
		if err == nil {
			return nil
		}

		// Falling back keeps live delivery working; the events missed while the
		// consumer was unavailable are recovered by the client resync path
		// instead of being silently dropped.
		c.logger.Warn("durable consumer unavailable, falling back to core NATS",
			"stream", c.options.Stream,
			"durable", c.options.Durable,
			"error", err,
		)
	}

	return c.startCore(handler)
}

func (c *Consumer) startDurable(handler Handler) error {
	js, err := jetstream.New(c.conn)
	if err != nil {
		return fmt.Errorf("create jetstream context: %w", err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if _, err := js.Stream(ctx, c.options.Stream); err != nil {
		return fmt.Errorf("stream %s: %w", c.options.Stream, err)
	}

	consumer, err := js.CreateOrUpdateConsumer(ctx, c.options.Stream, jetstream.ConsumerConfig{
		Durable:       c.options.Durable,
		AckPolicy:     jetstream.AckExplicitPolicy,
		DeliverPolicy: jetstream.DeliverNewPolicy,
		FilterSubject: c.options.Subject,
		MaxAckPending: 2048,
		AckWait:       30 * time.Second,
		MaxDeliver:    c.options.MaxDeliver,
		BackOff:       []time.Duration{time.Second, 2 * time.Second, 5 * time.Second, 15 * time.Second, 30 * time.Second},
	})
	if err != nil {
		return fmt.Errorf("create durable consumer %s: %w", c.options.Durable, err)
	}

	consumeCtx, err := consumer.Consume(func(message jetstream.Msg) {
		// Redelivery means the gateway already tried this event. The handler
		// deduplicates by event_id, so a retry cannot fan out twice.
		if err := handler(message.Subject(), message.Data()); err != nil {
			c.logger.Warn("failed to handle a durable event",
				"subject", message.Subject(),
				"error", err,
			)

			_ = message.NakWithDelay(time.Second)

			return
		}

		_ = message.Ack()
	})
	if err != nil {
		return fmt.Errorf("consume %s: %w", c.options.Durable, err)
	}

	c.mu.Lock()
	c.consume = consumeCtx
	c.mu.Unlock()

	c.logger.Info("gateway consumes durable events",
		"stream", c.options.Stream,
		"durable", c.options.Durable,
		"subject", c.options.Subject,
	)

	return nil
}

func (c *Consumer) startCore(handler Handler) error {
	sub, err := c.conn.Subscribe(c.options.Subject, func(message *nats.Msg) {
		if err := handler(message.Subject, message.Data); err != nil {
			c.logger.Debug("failed to handle a broker event", "error", err)
		}
	})
	if err != nil {
		return fmt.Errorf("subscribe to %s: %w", c.options.Subject, err)
	}

	c.mu.Lock()
	c.sub = sub
	c.mu.Unlock()

	return nil
}

func (c *Consumer) Stop() {
	c.mu.Lock()
	defer c.mu.Unlock()

	if c.consume != nil {
		c.consume.Stop()
		c.consume = nil
	}

	if c.sub != nil {
		if err := c.sub.Unsubscribe(); err != nil && !errors.Is(err, nats.ErrConnectionClosed) {
			c.logger.Debug("failed to unsubscribe from core nats", "error", err)
		}

		c.sub = nil
	}
}
