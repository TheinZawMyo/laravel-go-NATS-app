package outbox

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"sync/atomic"
	"testing"
	"time"

	"github.com/prometheus/client_golang/prometheus/testutil"
)

type fakeBacklog struct {
	stats BacklogStats
	err   error
	calls atomic.Int64
}

func (f *fakeBacklog) Backlog(context.Context) (BacklogStats, error) {
	f.calls.Add(1)

	return f.stats, f.err
}

func discardLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func TestBacklogMonitorRecordsDepthAndAge(t *testing.T) {
	now := time.Date(2026, 10, 31, 12, 0, 0, 0, time.UTC)

	source := &fakeBacklog{stats: BacklogStats{
		Pending:   4,
		Failed:    2,
		Oldest:    now.Add(-90 * time.Second),
		HasOldest: true,
	}}

	monitor := NewBacklogMonitor(source, discardLogger(), time.Minute)
	monitor.now = func() time.Time { return now }

	stats, err := monitor.Sample(context.Background())
	if err != nil {
		t.Fatalf("Sample() returned an error: %v", err)
	}

	if stats.Pending != 4 || stats.Failed != 2 {
		t.Fatalf("unexpected stats: %+v", stats)
	}

	if got := testutil.ToFloat64(monitor.gauges.pending); got != 4 {
		t.Errorf("pending gauge = %v, want 4", got)
	}

	if got := testutil.ToFloat64(monitor.gauges.failed); got != 2 {
		t.Errorf("failed gauge = %v, want 2", got)
	}

	if got := testutil.ToFloat64(monitor.gauges.oldest); got != 90 {
		t.Errorf("oldest age gauge = %v, want 90", got)
	}
}

func TestBacklogMonitorClearsGaugesWhenTheOutboxDrains(t *testing.T) {
	source := &fakeBacklog{stats: BacklogStats{
		Pending:   3,
		Oldest:    time.Now().Add(-time.Minute),
		HasOldest: true,
	}}

	monitor := NewBacklogMonitor(source, discardLogger(), time.Minute)

	if _, err := monitor.Sample(context.Background()); err != nil {
		t.Fatalf("Sample() returned an error: %v", err)
	}

	source.stats = BacklogStats{}

	if _, err := monitor.Sample(context.Background()); err != nil {
		t.Fatalf("Sample() returned an error: %v", err)
	}

	if got := testutil.ToFloat64(monitor.gauges.pending); got != 0 {
		t.Errorf("pending gauge = %v, want 0", got)
	}

	if got := testutil.ToFloat64(monitor.gauges.oldest); got != 0 {
		t.Errorf("oldest age gauge = %v, want 0", got)
	}
}

func TestBacklogMonitorReportsSourceFailures(t *testing.T) {
	source := &fakeBacklog{err: errors.New("database offline")}
	monitor := NewBacklogMonitor(source, discardLogger(), time.Minute)

	if _, err := monitor.Sample(context.Background()); err == nil {
		t.Fatal("expected Sample() to surface the store error")
	}
}

func TestBacklogMonitorRunSamplesUntilCancelled(t *testing.T) {
	source := &fakeBacklog{}

	monitor := NewBacklogMonitor(source, discardLogger(), 5*time.Millisecond)

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})

	go func() {
		defer close(done)

		monitor.Run(ctx)
	}()

	deadline := time.Now().Add(2 * time.Second)
	for source.calls.Load() < 2 && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}

	if source.calls.Load() < 2 {
		t.Fatalf("expected repeated samples, got %d", source.calls.Load())
	}

	cancel()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("Run() did not stop after the context was cancelled")
	}
}

func TestBacklogStatsOldestAgeIgnoresEmptyAndFutureRows(t *testing.T) {
	now := time.Date(2026, 10, 31, 12, 0, 0, 0, time.UTC)

	if age := (BacklogStats{}).OldestAge(now); age != 0 {
		t.Errorf("empty backlog age = %s, want 0", age)
	}

	future := BacklogStats{Oldest: now.Add(time.Minute), HasOldest: true}
	if age := future.OldestAge(now); age != 0 {
		t.Errorf("future backlog age = %s, want 0", age)
	}

	old := BacklogStats{Oldest: now.Add(-45 * time.Second), HasOldest: true}
	if age := old.OldestAge(now); age != 45*time.Second {
		t.Errorf("backlog age = %s, want 45s", age)
	}
}
