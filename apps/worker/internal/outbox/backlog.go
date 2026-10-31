package outbox

import (
	"context"
	"log/slog"
	"sync"
	"time"

	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/promauto"
)

// BacklogStats is a snapshot of the outbox table.
type BacklogStats struct {
	// Pending counts rows that are still retryable (no terminal error yet).
	Pending int64
	// Failed counts rows that exhausted their attempts and kept last_error.
	Failed int64
	// Oldest is the created_at of the oldest retryable row.
	Oldest time.Time
	// HasOldest is false when the outbox has no retryable rows.
	HasOldest bool
}

// OldestAge reports how long the oldest retryable event has been waiting.
func (s BacklogStats) OldestAge(now time.Time) time.Duration {
	if !s.HasOldest || s.Oldest.IsZero() {
		return 0
	}

	age := now.Sub(s.Oldest)
	if age < 0 {
		return 0
	}

	return age
}

// BacklogSource exposes the depth of the outbox table.
type BacklogSource interface {
	Backlog(ctx context.Context) (BacklogStats, error)
}

type backlogGauges struct {
	pending prometheus.Gauge
	failed  prometheus.Gauge
	oldest  prometheus.Gauge
}

var (
	backlogMetricsOnce sync.Once
	backlogMetrics     *backlogGauges
)

func gaugesForBacklog() *backlogGauges {
	backlogMetricsOnce.Do(func() {
		backlogMetrics = &backlogGauges{
			pending: promauto.NewGauge(prometheus.GaugeOpts{
				Name: "worker_outbox_pending_events",
				Help: "Outbox events that are unpublished and still retryable.",
			}),
			failed: promauto.NewGauge(prometheus.GaugeOpts{
				Name: "worker_outbox_failed_events",
				Help: "Outbox events that exhausted their publish attempts.",
			}),
			oldest: promauto.NewGauge(prometheus.GaugeOpts{
				Name: "worker_outbox_oldest_event_age_seconds",
				Help: "Age of the oldest unpublished, still retryable outbox event.",
			}),
		}
	})

	return backlogMetrics
}

// BacklogMonitor publishes outbox depth and age as Prometheus gauges so an
// operator can alert on a dispatcher that has stopped draining the table.
type BacklogMonitor struct {
	source   BacklogSource
	logger   *slog.Logger
	interval time.Duration
	gauges   *backlogGauges
	now      func() time.Time
}

func NewBacklogMonitor(source BacklogSource, logger *slog.Logger, interval time.Duration) *BacklogMonitor {
	if logger == nil {
		logger = slog.Default()
	}

	if interval <= 0 {
		interval = 15 * time.Second
	}

	return &BacklogMonitor{
		source:   source,
		logger:   logger,
		interval: interval,
		gauges:   gaugesForBacklog(),
		now:      time.Now,
	}
}

// Run samples the outbox immediately and then on every interval until the
// context is cancelled.
func (m *BacklogMonitor) Run(ctx context.Context) {
	ticker := time.NewTicker(m.interval)
	defer ticker.Stop()

	m.Sample(ctx)

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			m.Sample(ctx)
		}
	}
}

// Sample records one snapshot and returns the stats it observed.
func (m *BacklogMonitor) Sample(ctx context.Context) (BacklogStats, error) {
	stats, err := m.source.Backlog(ctx)
	if err != nil {
		if ctx.Err() == nil {
			m.logger.Warn("failed to sample the outbox backlog", "error", err)
		}

		return BacklogStats{}, err
	}

	age := stats.OldestAge(m.now())

	m.gauges.pending.Set(float64(stats.Pending))
	m.gauges.failed.Set(float64(stats.Failed))
	m.gauges.oldest.Set(age.Seconds())

	if stats.Pending > 0 {
		m.logger.Debug("outbox backlog sampled",
			"pending", stats.Pending,
			"failed", stats.Failed,
			"oldest_age", age.String(),
		)
	}

	return stats, nil
}
