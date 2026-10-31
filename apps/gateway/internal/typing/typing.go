// Package typing tracks who is typing in a conversation.
//
// Typing indicators are ephemeral: the state lives in Redis with a short TTL so
// an indicator disappears even when the client stops sending events without
// sending typing.stop (closed laptop, killed browser). The state also lets the
// gateway emit a matching "stopped typing" event when a connection drops.
package typing

import (
	"context"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
)

const connectionKeyPrefix = "typing:connection:"

type Store struct {
	client *redis.Client
	ttl    time.Duration
}

func New(client *redis.Client, ttl time.Duration) *Store {
	return &Store{client: client, ttl: ttl}
}

func (s *Store) Enabled() bool {
	return s != nil && s.client != nil
}

// Start marks the user as typing and remembers the conversation on the
// connection so a disconnect can publish the matching stop event.
func (s *Store) Start(ctx context.Context, conversationID, userID, connectionID string) error {
	if !s.Enabled() {
		return nil
	}

	pipe := s.client.TxPipeline()
	pipe.Set(ctx, key(conversationID, userID, connectionID), "1", s.ttl)
	pipe.SAdd(ctx, connectionKeyPrefix+connectionID, conversationID)
	pipe.Expire(ctx, connectionKeyPrefix+connectionID, s.ttl)

	if _, err := pipe.Exec(ctx); err != nil {
		return fmt.Errorf("record typing state: %w", err)
	}

	return nil
}

// Stop clears the typing state for one conversation.
func (s *Store) Stop(ctx context.Context, conversationID, userID, connectionID string) error {
	if !s.Enabled() {
		return nil
	}

	pipe := s.client.TxPipeline()
	pipe.Del(ctx, key(conversationID, userID, connectionID))
	pipe.SRem(ctx, connectionKeyPrefix+connectionID, conversationID)

	if _, err := pipe.Exec(ctx); err != nil {
		return fmt.Errorf("clear typing state: %w", err)
	}

	return nil
}

// ClearConnection drops every typing state owned by a connection and returns
// the conversations that must be told the user stopped typing.
func (s *Store) ClearConnection(ctx context.Context, userID, connectionID string) ([]string, error) {
	if !s.Enabled() {
		return nil, nil
	}

	connectionKey := connectionKeyPrefix + connectionID

	conversations, err := s.client.SMembers(ctx, connectionKey).Result()
	if err != nil && err != redis.Nil {
		return nil, fmt.Errorf("read typing conversations: %w", err)
	}

	pipe := s.client.TxPipeline()

	for _, conversationID := range conversations {
		pipe.Del(ctx, key(conversationID, userID, connectionID))
	}

	pipe.Del(ctx, connectionKey)

	if _, err := pipe.Exec(ctx); err != nil {
		return nil, fmt.Errorf("clear typing connection: %w", err)
	}

	return conversations, nil
}

func key(conversationID, userID, connectionID string) string {
	return "typing:" + conversationID + ":" + userID + ":" + connectionID
}
