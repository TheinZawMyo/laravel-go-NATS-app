package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"

	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/nats-io/nats.go"
	"github.com/redis/go-redis/v9"

	"chatapp/worker/internal/config"
	"chatapp/worker/internal/health"
	"chatapp/worker/internal/outbox"
)

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: slog.LevelInfo,
	}))
	slog.SetDefault(logger)

	cfg, err := config.Load()
	if err != nil {
		logger.Error("invalid configuration", "error", err)
		os.Exit(1)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	db, err := pgxpool.New(ctx, cfg.DatabaseURL)
	if err != nil {
		logger.Error("failed to connect to postgres", "error", err)
		os.Exit(1)
	}
	defer db.Close()

	redisOpts, err := redis.ParseURL(cfg.RedisURL)
	if err != nil {
		logger.Error("invalid redis url", "error", err)
		os.Exit(1)
	}
	cache := redis.NewClient(redisOpts)
	defer func() { _ = cache.Close() }()

	nc, err := nats.Connect(cfg.NATSURL, nats.Name(cfg.InstanceID), nats.MaxReconnects(-1))
	if err != nil {
		logger.Error("failed to connect to nats", "error", err)
		os.Exit(1)
	}
	defer nc.Drain()

	healthServer := health.New(cfg.Addr, logger, health.Checker{
		DB:    db,
		Redis: cache,
		NATS:  nc,
	})

	store := outbox.NewPostgresStore(db)

	var publisher outbox.Publisher = outbox.NewNATSPublisher(nc)

	if cfg.JetStreamEnabled {
		jetStreamPublisher, publishErr := outbox.NewJetStreamPublisher(ctx, nc, outbox.JetStreamConfig{
			Stream:   cfg.JetStreamStream,
			Subjects: cfg.JetStreamSubjects(),
			MaxAge:   cfg.JetStreamMaxAge,
			Replicas: cfg.JetStreamReplicas,
		})
		if publishErr != nil {
			logger.Error("failed to configure the durable broker", "error", publishErr)
			os.Exit(1)
		}

		publisher = jetStreamPublisher

		logger.Info("outbox publishes into jetstream",
			"stream", cfg.JetStreamStream,
			"subjects", cfg.JetStreamSubjects(),
			"max_age", cfg.JetStreamMaxAge.String(),
			"replicas", cfg.JetStreamReplicas,
		)
	} else {
		logger.Warn("jetstream is disabled; outbox events are published to core NATS without retention")
	}

	dispatcher := outbox.New(
		store,
		publisher,
		logger,
		outbox.Options{
			SubjectPrefix: cfg.EventSubjectPrefix,
			BatchSize:     cfg.OutboxBatchSize,
			MaxAttempts:   cfg.OutboxMaxAttempts,
			Interval:      cfg.OutboxPollEvery,
		},
	)

	backlogMonitor := outbox.NewBacklogMonitor(store, logger, cfg.BacklogInterval)

	dispatcherDone := make(chan struct{})
	monitorDone := make(chan struct{})

	go func() {
		defer close(dispatcherDone)

		dispatcher.Run(ctx)
	}()

	go func() {
		defer close(monitorDone)

		backlogMonitor.Run(ctx)
	}()

	logger.Info(
		"worker started",
		"instance", cfg.InstanceID,
		"poll", cfg.PollEvery.String(),
		"outbox_poll", cfg.OutboxPollEvery.String(),
		"backlog_interval", cfg.BacklogInterval.String(),
	)

	err = healthServer.Run(ctx)
	if err != nil && !errors.Is(err, http.ErrServerClosed) {
		logger.Error("worker stopped", "error", err)
		stop()
		<-dispatcherDone
		<-monitorDone

		os.Exit(1)
	}

	<-dispatcherDone
	<-monitorDone

	logger.Info("worker stopped")
}
