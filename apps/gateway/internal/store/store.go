package store

import (
	"context"

	"github.com/jackc/pgx/v5/pgxpool"
)

type Store struct {
	db *pgxpool.Pool
}

func New(db *pgxpool.Pool) *Store {
	return &Store{db: db}
}

func (s *Store) UserIsActive(ctx context.Context, userID string) (bool, error) {
	var active bool

	err := s.db.QueryRow(ctx, `
		SELECT status = 'active' AND deleted_at IS NULL
		FROM users
		WHERE id = $1
	`, userID).Scan(&active)

	if err != nil {
		return false, err
	}

	return active, nil
}

func (s *Store) CanAccessConversation(ctx context.Context, userID, conversationID string) (bool, error) {
	var allowed bool

	err := s.db.QueryRow(ctx, `
		SELECT EXISTS (
			SELECT 1
			FROM conversation_members
			WHERE conversation_id = $1
				AND user_id = $2
				AND left_at IS NULL
		)
	`, conversationID, userID).Scan(&allowed)

	if err != nil {
		return false, err
	}

	return allowed, nil
}

// LatestSeq returns the highest sequence number in a conversation, used to
// detect clients that missed events while disconnected.
func (s *Store) LatestSeq(ctx context.Context, conversationID string) (int64, error) {
	var seq int64

	err := s.db.QueryRow(ctx, `
		SELECT COALESCE(MAX(seq), 0)
		FROM messages
		WHERE conversation_id = $1
			AND deleted_at IS NULL
	`, conversationID).Scan(&seq)

	if err != nil {
		return 0, err
	}

	return seq, nil
}

// ConversationIDsFor lists the conversations a user still belongs to, so
// presence transitions can be fanned out to the right subscribers.
func (s *Store) ConversationIDsFor(ctx context.Context, userID string) ([]string, error) {
	rows, err := s.db.Query(ctx, `
		SELECT conversation_id
		FROM conversation_members
		WHERE user_id = $1
			AND left_at IS NULL
	`, userID)
	if err != nil {
		return nil, err
	}

	defer rows.Close()

	var ids []string

	for rows.Next() {
		var id string

		if err := rows.Scan(&id); err != nil {
			return nil, err
		}

		ids = append(ids, id)
	}

	return ids, rows.Err()
}

// MemberIDs lists the active members of a conversation.
func (s *Store) MemberIDs(ctx context.Context, conversationID string) ([]string, error) {
	rows, err := s.db.Query(ctx, `
		SELECT user_id
		FROM conversation_members
		WHERE conversation_id = $1
			AND left_at IS NULL
	`, conversationID)
	if err != nil {
		return nil, err
	}

	defer rows.Close()

	var ids []string

	for rows.Next() {
		var id string

		if err := rows.Scan(&id); err != nil {
			return nil, err
		}

		ids = append(ids, id)
	}

	return ids, rows.Err()
}
