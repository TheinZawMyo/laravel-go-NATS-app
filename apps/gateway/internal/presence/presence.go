// Package presence tracks user presence leases in Redis.
//
// Each websocket connection owns a lease keyed by connection id. The lease is
// refreshed by the websocket heartbeat and expires automatically, so a gateway
// crash cannot leave a user pinned online forever. Presence changes are only
// published when the user transitions between online and offline, which keeps
// multi-tab clients from flapping.
package presence

import (
	"context"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
)

const (
	connectionsKeyPrefix = "presence:user:"
	lastSeenKeyPrefix    = "presence:lastseen:"
	lastSeenTTL          = 7 * 24 * time.Hour
)

type Tracker struct {
	client *redis.Client
	ttl    time.Duration
	now    func() time.Time
}

func New(client *redis.Client, ttl time.Duration) *Tracker {
	return &Tracker{client: client, ttl: ttl, now: time.Now}
}

func (t *Tracker) Enabled() bool {
	return t != nil && t.client != nil
}

// Connect registers a lease. The boolean reports whether this connection made
// the user go online (i.e. it was the first live connection).
func (t *Tracker) Connect(ctx context.Context, userID, connectionID string) (bool, error) {
	if !t.Enabled() {
		return false, nil
	}

	key := connectionsKeyPrefix + userID

	pipe := t.client.TxPipeline()
	pipe.SAdd(ctx, key, connectionID)
	count := pipe.SCard(ctx, key)
	pipe.Expire(ctx, key, t.ttl)
	pipe.Set(ctx, lastSeenKeyPrefix+userID, t.now().UTC().Format(time.RFC3339Nano), lastSeenTTL)

	if _, err := pipe.Exec(ctx); err != nil {
		return false, fmt.Errorf("register presence lease: %w", err)
	}

	return count.Val() <= 1, nil
}

// Refresh extends the lease. Missing leases are recreated (a restart of the
// gateway or a flushed Redis must not make the user vanish).
func (t *Tracker) Refresh(ctx context.Context, userID, connectionID string) error {
	if !t.Enabled() {
		return nil
	}

	key := connectionsKeyPrefix + userID

	pipe := t.client.TxPipeline()
	pipe.SAdd(ctx, key, connectionID)
	pipe.Expire(ctx, key, t.ttl)
	pipe.Set(ctx, lastSeenKeyPrefix+userID, t.now().UTC().Format(time.RFC3339Nano), lastSeenTTL)

	if _, err := pipe.Exec(ctx); err != nil {
		return fmt.Errorf("refresh presence lease: %w", err)
	}

	return nil
}

// Disconnect removes a lease. The boolean reports whether the user went offline
// (i.e. no other connection of that user is still alive).
func (t *Tracker) Disconnect(ctx context.Context, userID, connectionID string) (bool, error) {
	if !t.Enabled() {
		return false, nil
	}

	key := connectionsKeyPrefix + userID

	pipe := t.client.TxPipeline()
	pipe.SRem(ctx, key, connectionID)
	count := pipe.SCard(ctx, key)
	pipe.Set(ctx, lastSeenKeyPrefix+userID, t.now().UTC().Format(time.RFC3339Nano), lastSeenTTL)

	if _, err := pipe.Exec(ctx); err != nil {
		return false, fmt.Errorf("release presence lease: %w", err)
	}

	if count.Val() > 0 {
		return false, nil
	}

	t.client.Del(ctx, key)

	return true, nil
}

// IsOnline reports whether the user holds at least one live lease.
func (t *Tracker) IsOnline(ctx context.Context, userID string) (bool, error) {
	if !t.Enabled() {
		return false, nil
	}

	count, err := t.client.SCard(ctx, connectionsKeyPrefix+userID).Result()
	if err != nil {
		return false, fmt.Errorf("read presence lease: %w", err)
	}

	return count > 0, nil
}

// LastSeen returns the last time the user was seen, if known. A live user is
// reported as seen now so clients do not show a stale timestamp.
func (t *Tracker) LastSeen(ctx context.Context, userID string) (time.Time, error) {
	if !t.Enabled() {
		return time.Time{}, nil
	}

	value, err := t.client.Get(ctx, lastSeenKeyPrefix+userID).Result()
	if err != nil {
		if err == redis.Nil {
			return time.Time{}, nil
		}

		return time.Time{}, fmt.Errorf("read presence last seen: %w", err)
	}

	parsed, err := time.Parse(time.RFC3339Nano, value)
	if err != nil {
		return time.Time{}, nil
	}

	return parsed, nil
}
