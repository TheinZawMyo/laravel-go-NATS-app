package outbox

import (
	"context"
	"fmt"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

// PostgresStore claims outbox rows with row locks so several workers can drain
// the table concurrently without publishing the same event twice.
type PostgresStore struct {
	pool *pgxpool.Pool
}

func NewPostgresStore(pool *pgxpool.Pool) *PostgresStore {
	return &PostgresStore{pool: pool}
}

const backlogQuery = `
	SELECT
		COUNT(*) FILTER (WHERE last_error IS NULL)::bigint,
		COUNT(*) FILTER (WHERE last_error IS NOT NULL)::bigint,
		MIN(created_at) FILTER (WHERE last_error IS NULL)
	FROM outbox_events
	WHERE published_at IS NULL`

// Backlog reports how many events are still waiting, how many are dead
// lettered, and how old the oldest retryable event is.
func (s *PostgresStore) Backlog(ctx context.Context) (BacklogStats, error) {
	var (
		stats  BacklogStats
		oldest *time.Time
	)

	err := s.pool.QueryRow(ctx, backlogQuery).Scan(&stats.Pending, &stats.Failed, &oldest)
	if err != nil {
		return BacklogStats{}, fmt.Errorf("read outbox backlog: %w", err)
	}

	if oldest != nil {
		stats.Oldest = *oldest
		stats.HasOldest = true
	}

	return stats, nil
}

const claimQuery = `
	SELECT id, aggregate_type, aggregate_id, event_type, payload, attempts
	FROM outbox_events
	WHERE published_at IS NULL
	  AND available_at <= now()
	  AND attempts < $2
	ORDER BY created_at
	LIMIT $1
	FOR UPDATE SKIP LOCKED`

func (s *PostgresStore) Claim(ctx context.Context, limit int, maxAttempts int) (Batch, error) {
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return nil, fmt.Errorf("begin outbox transaction: %w", err)
	}

	rows, err := tx.Query(ctx, claimQuery, limit, maxAttempts)
	if err != nil {
		_ = tx.Rollback(ctx)

		return nil, fmt.Errorf("claim outbox rows: %w", err)
	}

	events, err := collect(rows)
	if err != nil {
		_ = tx.Rollback(ctx)

		return nil, err
	}

	return &pgBatch{tx: tx, events: events, maxAttempts: maxAttempts}, nil
}

func collect(rows pgx.Rows) ([]Event, error) {
	defer rows.Close()

	events := make([]Event, 0, 16)

	for rows.Next() {
		var event Event

		if err := rows.Scan(
			&event.ID,
			&event.AggregateType,
			&event.AggregateID,
			&event.EventType,
			&event.Payload,
			&event.Attempts,
		); err != nil {
			return nil, fmt.Errorf("scan outbox row: %w", err)
		}

		events = append(events, event)
	}

	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read outbox rows: %w", err)
	}

	return events, nil
}

type pgBatch struct {
	tx          pgx.Tx
	events      []Event
	maxAttempts int
	done        bool
}

// Retry pacing for events the broker rejected. Doubles per attempt and stops
// at the ceiling so a long outage does not spin the database.
const (
	backoffBase = time.Second
	backoffMax  = 30 * time.Second
)

func (b *pgBatch) Events() []Event {
	return b.events
}

func (b *pgBatch) Published(ctx context.Context, event Event) error {
	_, err := b.tx.Exec(ctx, `
		UPDATE outbox_events
		SET published_at = now(), attempts = attempts + 1, last_error = NULL, updated_at = now()
		WHERE id = $1`, event.ID)
	if err != nil {
		return fmt.Errorf("mark event %s published: %w", event.ID, err)
	}

	return nil
}

func (b *pgBatch) Failed(ctx context.Context, event Event, cause error, permanent bool) error {
	attempts := event.Attempts + 1
	delay := Backoff(attempts, backoffBase, backoffMax)

	if permanent || attempts >= b.maxAttempts {
		attempts = b.maxAttempts
		delay = time.Hour
	}

	message := ""
	if cause != nil {
		message = cause.Error()
	}

	_, err := b.tx.Exec(ctx, `
		UPDATE outbox_events
		SET attempts = $2, available_at = now() + $3::interval, last_error = $4, updated_at = now()
		WHERE id = $1`,
		event.ID, attempts, fmt.Sprintf("%d milliseconds", delay.Milliseconds()), message,
	)
	if err != nil {
		return fmt.Errorf("record event %s failure: %w", event.ID, err)
	}

	return nil
}

func (b *pgBatch) Commit(ctx context.Context) error {
	if b.done {
		return nil
	}

	b.done = true

	return b.tx.Commit(ctx)
}

func (b *pgBatch) Rollback(ctx context.Context) {
	if b.done {
		return
	}

	b.done = true

	_ = b.tx.Rollback(ctx)
}
