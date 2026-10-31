package dedupe

import (
	"testing"
	"time"
)

func TestSeenReportsRepeatsWithinTTL(t *testing.T) {
	cache := New(100, time.Minute)

	if cache.Seen("event-1") {
		t.Fatal("first sighting must not be a duplicate")
	}

	if !cache.Seen("event-1") {
		t.Fatal("second sighting must be a duplicate")
	}

	if cache.Seen("event-2") {
		t.Fatal("a different event must not be a duplicate")
	}

	if got := cache.Len(); got != 2 {
		t.Fatalf("Len() = %d, want 2", got)
	}
}

func TestSeenForgetsExpiredEvents(t *testing.T) {
	cache := New(100, time.Minute)

	now := time.Now()
	cache.now = func() time.Time { return now }

	cache.Seen("event-1")

	now = now.Add(2 * time.Minute)

	if cache.Seen("event-1") {
		t.Fatal("event must be forgotten after the TTL")
	}
}

func TestCacheCapsItsSize(t *testing.T) {
	cache := New(3, time.Hour)

	for _, id := range []string{"a", "b", "c", "d", "e"} {
		cache.Seen(id)
	}

	if got := cache.Len(); got > 3 {
		t.Fatalf("Len() = %d, want at most 3", got)
	}
}

func TestEmptyIdentifiersAreIgnored(t *testing.T) {
	cache := New(10, time.Minute)

	if cache.Seen("") {
		t.Fatal("an empty id must never be treated as a duplicate")
	}

	var nilCache *Cache
	if nilCache.Seen("event-1") {
		t.Fatal("a nil cache must not report duplicates")
	}

	if nilCache.Len() != 0 {
		t.Fatal("a nil cache must report a zero length")
	}
}
