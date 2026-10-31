package outbox

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"testing"
	"time"
)

type publishedCall struct {
	subject string
	payload string
}

type fakePublisher struct {
	calls    []publishedCall
	err      error
	flushErr error
	flushes  int
}

func (p *fakePublisher) Publish(subject string, payload []byte) error {
	if p.err != nil {
		return p.err
	}

	p.calls = append(p.calls, publishedCall{subject: subject, payload: string(payload)})

	return nil
}

func (p *fakePublisher) Flush() error {
	p.flushes++

	return p.flushErr
}

type failure struct {
	id        string
	cause     error
	permanent bool
}

type fakeBatch struct {
	events    []Event
	published []string
	failures  []failure
	committed bool
	rolled    bool
}

func (b *fakeBatch) Events() []Event { return b.events }

func (b *fakeBatch) Published(_ context.Context, event Event) error {
	b.published = append(b.published, event.ID)

	return nil
}

func (b *fakeBatch) Failed(_ context.Context, event Event, cause error, permanent bool) error {
	b.failures = append(b.failures, failure{id: event.ID, cause: cause, permanent: permanent})

	return nil
}

func (b *fakeBatch) Commit(context.Context) error {
	b.committed = true

	return nil
}

func (b *fakeBatch) Rollback(context.Context) {
	b.rolled = true
}

type fakeClaimer struct {
	batch *fakeBatch
	err   error
	limit int
}

func (c *fakeClaimer) Claim(_ context.Context, limit int, _ int) (Batch, error) {
	c.limit = limit

	if c.err != nil {
		return nil, c.err
	}

	if c.batch == nil {
		return nil, nil
	}

	return c.batch, nil
}

func quietLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func event(id, eventType string) Event {
	return Event{
		ID:            id,
		AggregateType: "conversation",
		AggregateID:   "conversation-1",
		EventType:     eventType,
		Payload:       []byte(`{"type":"` + eventType + `"}`),
	}
}

func TestDispatchOncePublishesClaimedEvents(t *testing.T) {
	batch := &fakeBatch{events: []Event{event("1", "message.created"), event("2", "message.updated")}}
	claimer := &fakeClaimer{batch: batch}
	publisher := &fakePublisher{}

	dispatcher := New(claimer, publisher, quietLogger(), Options{BatchSize: 25})

	count, err := dispatcher.DispatchOnce(context.Background())
	if err != nil {
		t.Fatalf("dispatch: %v", err)
	}

	if count != 2 {
		t.Fatalf("expected 2 published events, got %d", count)
	}

	if !batch.committed || batch.rolled {
		t.Fatalf("expected committed batch, got committed=%v rolled=%v", batch.committed, batch.rolled)
	}

	if len(batch.failures) != 0 {
		t.Fatalf("expected no failures, got %+v", batch.failures)
	}

	if len(publisher.calls) != 2 {
		t.Fatalf("expected 2 broker publishes, got %d", len(publisher.calls))
	}

	if publisher.calls[0].subject != "chat.events.conversation.conversation-1" {
		t.Fatalf("unexpected subject %q", publisher.calls[0].subject)
	}

	if claimer.limit != 25 {
		t.Fatalf("expected claim limit 25, got %d", claimer.limit)
	}
}

func TestDispatchOnceRecordsRetryableFailures(t *testing.T) {
	batch := &fakeBatch{events: []Event{event("1", "message.created")}}
	publisher := &fakePublisher{err: errors.New("nats unavailable")}

	dispatcher := New(&fakeClaimer{batch: batch}, publisher, quietLogger(), Options{})

	count, err := dispatcher.DispatchOnce(context.Background())
	if err != nil {
		t.Fatalf("dispatch: %v", err)
	}

	if count != 0 {
		t.Fatalf("expected nothing published, got %d", count)
	}

	if len(batch.published) != 0 {
		t.Fatalf("expected no published marks, got %v", batch.published)
	}

	if len(batch.failures) != 1 || batch.failures[0].permanent {
		t.Fatalf("expected one retryable failure, got %+v", batch.failures)
	}

	if !batch.committed {
		t.Fatal("expected failure bookkeeping to be committed")
	}
}

func TestDispatchOnceSkipsUnroutableEvents(t *testing.T) {
	batch := &fakeBatch{events: []Event{{
		ID:            "1",
		AggregateType: "unknown",
		AggregateID:   "aggregate-1",
		EventType:     "message.created",
		Payload:       []byte(`{}`),
	}}}
	publisher := &fakePublisher{}

	dispatcher := New(&fakeClaimer{batch: batch}, publisher, quietLogger(), Options{})

	if _, err := dispatcher.DispatchOnce(context.Background()); err != nil {
		t.Fatalf("dispatch: %v", err)
	}

	if len(publisher.calls) != 0 {
		t.Fatalf("expected no publishes, got %+v", publisher.calls)
	}

	if len(batch.failures) != 1 || !batch.failures[0].permanent {
		t.Fatalf("expected one permanent failure, got %+v", batch.failures)
	}
}

func TestDispatchOnceHandlesEmptyBatches(t *testing.T) {
	batch := &fakeBatch{}
	dispatcher := New(&fakeClaimer{batch: batch}, &fakePublisher{}, quietLogger(), Options{})

	count, err := dispatcher.DispatchOnce(context.Background())
	if err != nil {
		t.Fatalf("dispatch: %v", err)
	}

	if count != 0 || !batch.rolled || batch.committed {
		t.Fatalf(
			"expected rolled back empty batch, got count=%d rolled=%v committed=%v",
			count, batch.rolled, batch.committed,
		)
	}
}

func TestDispatchOncePropagatesClaimErrors(t *testing.T) {
	dispatcher := New(
		&fakeClaimer{err: errors.New("database down")},
		&fakePublisher{},
		quietLogger(),
		Options{},
	)

	if _, err := dispatcher.DispatchOnce(context.Background()); err == nil {
		t.Fatal("expected claim error to propagate")
	}
}

func TestSubjectRouting(t *testing.T) {
	cases := []struct {
		aggregateType string
		aggregateID   string
		want          string
		wantErr       bool
	}{
		{aggregateType: "conversation", aggregateID: "abc", want: "chat.events.conversation.abc"},
		{aggregateType: "user", aggregateID: "u1", want: "chat.events.user.u1"},
		{aggregateType: "conversation", aggregateID: "  ", wantErr: true},
		{aggregateType: "", aggregateID: "abc", wantErr: true},
		{aggregateType: "message", aggregateID: "abc", wantErr: true},
	}

	for _, testCase := range cases {
		subject, err := Subject("chat.events.", testCase.aggregateType, testCase.aggregateID)

		if testCase.wantErr {
			if err == nil {
				t.Fatalf("expected error for %+v, got subject %q", testCase, subject)
			}

			continue
		}

		if err != nil {
			t.Fatalf("unexpected error for %+v: %v", testCase, err)
		}

		if subject != testCase.want {
			t.Fatalf("expected %q, got %q", testCase.want, subject)
		}
	}
}

func TestBackoffGrowsAndCaps(t *testing.T) {
	base := time.Second
	ceiling := 8 * time.Second

	cases := map[int]time.Duration{
		1: base,
		2: 2 * time.Second,
		3: 4 * time.Second,
		4: ceiling,
		9: ceiling,
	}

	for attempts, want := range cases {
		if got := Backoff(attempts, base, ceiling); got != want {
			t.Fatalf("attempts %d: expected %s, got %s", attempts, want, got)
		}
	}
}

func TestDispatchOnceRetriesWhenTheBrokerFlushFails(t *testing.T) {
	batch := &fakeBatch{events: []Event{event("1", "message.created")}}
	publisher := &fakePublisher{flushErr: errors.New("flush timeout")}

	dispatcher := New(&fakeClaimer{batch: batch}, publisher, quietLogger(), Options{})

	count, err := dispatcher.DispatchOnce(context.Background())
	if err != nil {
		t.Fatalf("dispatch: %v", err)
	}

	if count != 0 {
		t.Fatalf("expected nothing acknowledged, got %d", count)
	}

	if publisher.flushes != 1 {
		t.Fatalf("expected one flush, got %d", publisher.flushes)
	}

	if len(batch.published) != 0 {
		t.Fatalf("expected no published marks, got %v", batch.published)
	}

	if len(batch.failures) != 1 || batch.failures[0].permanent {
		t.Fatalf("expected one retryable failure, got %+v", batch.failures)
	}
}
