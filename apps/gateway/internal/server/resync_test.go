package server

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"chatapp/gateway/internal/protocol"
)

type fakeDirectory struct {
	latestSeq int64
	members   []string
	launch    []string
	err       error
}

func (d *fakeDirectory) LatestSeq(context.Context, string) (int64, error) {
	return d.latestSeq, d.err
}

func (d *fakeDirectory) MemberIDs(context.Context, string) ([]string, error) {
	return d.members, d.err
}

func (d *fakeDirectory) ConversationIDsFor(context.Context, string) ([]string, error) {
	return d.launch, d.err
}

func TestSubscribeWithStaleCursorRequestsResync(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"

	server, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{conversationID: true},
	})
	server.directory = &fakeDirectory{latestSeq: 9}

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	lastSeq := int64(4)
	acked := subscribeAndAck(t, conn, server, conversationID, &lastSeq)

	var subscribedData protocol.SubscribedData
	if err := json.Unmarshal(acked.Data, &subscribedData); err != nil {
		t.Fatalf("decode subscribed data: %v", err)
	}

	if subscribedData.LatestSeq != 9 {
		t.Fatalf("latest_seq = %d, want 9", subscribedData.LatestSeq)
	}

	syncEnvelope := readEnvelope(t, conn)
	if syncEnvelope.Type != protocol.ServerSyncRequired {
		t.Fatalf("expected sync.required, got %s", syncEnvelope.Type)
	}

	var syncData protocol.SyncRequiredData
	if err := json.Unmarshal(syncEnvelope.Data, &syncData); err != nil {
		t.Fatalf("decode sync data: %v", err)
	}

	if syncData.ConversationID != conversationID || syncData.LastSeq != 4 || syncData.LatestSeq != 9 {
		t.Fatalf("unexpected sync payload: %+v", syncData)
	}

	if syncData.Reason != "missed_events" {
		t.Fatalf("reason = %q, want missed_events", syncData.Reason)
	}
}

func TestSubscribeWithCurrentCursorDoesNotRequestResync(t *testing.T) {
	conversationID := "11111111-1111-4111-8111-111111111111"

	server, httpServer := testServer(t, &fakeAuthorizer{
		active: true,
		access: map[string]bool{conversationID: true},
	})
	server.directory = &fakeDirectory{latestSeq: 5}

	conn := dial(t, httpServer, "http://localhost:8000")
	authenticate(t, conn, signToken(t, testSecret, "user-1", time.Now().Add(time.Hour)))

	lastSeq := int64(5)
	subscribeAndAck(t, conn, server, conversationID, &lastSeq)

	// Nothing else may arrive: a caught-up client is not told to resync.
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()

	if _, _, err := conn.Read(ctx); err == nil {
		t.Fatal("expected no further events for a caught-up subscription")
	}
}
