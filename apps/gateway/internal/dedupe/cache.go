// Package dedupe keeps a bounded, time-limited set of recently handled event
// identifiers.
//
// Durable delivery is at-least-once: a gateway that is restarted after fanning
// an event out but before acknowledging it will receive the event again. Events
// are deduplicated on the outbox envelope event_id so a redelivery cannot make
// clients apply the same message twice.
package dedupe

import (
	"sync"
	"time"
)

type Cache struct {
	mu      sync.Mutex
	entries map[string]time.Time
	order   []string
	limit   int
	ttl     time.Duration
	now     func() time.Time
}

func New(limit int, ttl time.Duration) *Cache {
	if limit < 1 {
		limit = 1
	}

	return &Cache{
		entries: make(map[string]time.Time),
		limit:   limit,
		ttl:     ttl,
		now:     time.Now,
	}
}

// Seen records the identifier and reports whether it had already been seen
// within the TTL window.
func (c *Cache) Seen(id string) bool {
	if c == nil || id == "" {
		return false
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	now := c.now()

	if seenAt, ok := c.entries[id]; ok && now.Sub(seenAt) < c.ttl {
		return true
	}

	c.entries[id] = now
	c.order = append(c.order, id)
	c.evict(now)

	return false
}

// Len reports the number of tracked identifiers, used by tests and metrics.
func (c *Cache) Len() int {
	if c == nil {
		return 0
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	return len(c.entries)
}

// evict drops expired identifiers and hard-caps the cache at limit entries.
func (c *Cache) evict(now time.Time) {
	for len(c.order) > 0 {
		oldest := c.order[0]
		seenAt, ok := c.entries[oldest]
		expired := !ok || now.Sub(seenAt) >= c.ttl

		if !expired && len(c.order) <= c.limit {
			return
		}

		c.order = c.order[1:]
		delete(c.entries, oldest)
	}
}
