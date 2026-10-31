package protocol

import (
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"time"
)

const Version = 1

const (
	ClientAuthenticate = "connection.authenticate"
	ClientSubscribe    = "conversation.subscribe"
	ClientUnsubscribe  = "conversation.unsubscribe"
	ClientTypingStart  = "typing.start"
	ClientTypingStop   = "typing.stop"
	ClientReceipt      = "receipt.delivered"
	ClientReadReceipt  = "receipt.read"
	ClientPing         = "ping"

	ServerReady               = "connection.ready"
	ServerError               = "connection.error"
	ServerPong                = "pong"
	ServerMessageCreated      = "message.created"
	ServerMessageUpdated      = "message.updated"
	ServerMessageDeleted      = "message.deleted"
	ServerReactionUpdated     = "message.reaction.updated"
	ServerReceiptUpdated      = "receipt.updated"
	ServerTypingUpdated       = "typing.updated"
	ServerPresenceUpdated     = "presence.updated"
	ServerConversationUpdated = "conversation.updated"
	ServerSubscribed          = "conversation.subscribed"
	ServerSyncRequired        = "sync.required"
)

type ClientMessage struct {
	Type      string          `json:"type"`
	RequestID string          `json:"id,omitempty"`
	Data      json.RawMessage `json:"data,omitempty"`
}

type AuthData struct {
	Token string `json:"token"`
}

type ConversationData struct {
	ConversationID string `json:"conversation_id"`
	// LastSeq is the highest conversation sequence the client already applied.
	// When it is behind the server, the gateway answers with sync.required.
	LastSeq *int64 `json:"last_seq,omitempty"`
}

type ReceiptData struct {
	ConversationID string `json:"conversation_id"`
	MessageID      string `json:"message_id"`
	Seq            int64  `json:"seq"`
}

type ReadyData struct {
	UserID                   string `json:"user_id"`
	InstanceID               string `json:"instance_id"`
	Version                  int    `json:"version"`
	HeartbeatSeconds         int    `json:"heartbeat_seconds"`
	MaxSubscriptions         int    `json:"max_subscriptions"`
	MaxFrameBytes            int64  `json:"max_frame_bytes"`
	ConnectionTokenExpiresAt string `json:"connection_token_expires_at,omitempty"`
}

type ErrorData struct {
	Code      string `json:"code"`
	Message   string `json:"message"`
	RequestID string `json:"request_id,omitempty"`
}

type PongData struct {
	RequestID string `json:"request_id,omitempty"`
}

type TypingData struct {
	ConversationID string `json:"conversation_id"`
	UserID         string `json:"user_id"`
	IsTyping       bool   `json:"is_typing"`
	ExpiresAt      string `json:"expires_at"`
}

type ReceiptUpdatedData struct {
	ConversationID string `json:"conversation_id"`
	UserID         string `json:"user_id"`
	MessageID      string `json:"message_id"`
	Seq            int64  `json:"seq"`
	Read           bool   `json:"read"`
}

// PresenceData is broadcast when a member of a conversation goes online or
// offline. It is conversation-scoped so subscribers can render presence dots
// without knowing which conversations a user belongs to.
type PresenceData struct {
	ConversationID string `json:"conversation_id"`
	UserID         string `json:"user_id"`
	Online         bool   `json:"online"`
	LastSeenAt     string `json:"last_seen_at,omitempty"`
}

// SyncRequiredData tells a client that it missed events and must refetch the
// conversation from the HTTP API.
type SyncRequiredData struct {
	ConversationID string `json:"conversation_id"`
	LastSeq        int64  `json:"last_seq"`
	LatestSeq      int64  `json:"latest_seq"`
	Reason         string `json:"reason"`
}

// SubscribedData acknowledges a subscription and hands the client the cursor it
// should send on the next reconnect.
type SubscribedData struct {
	ConversationID string `json:"conversation_id"`
	LatestSeq      int64  `json:"latest_seq"`
	LastSeq        *int64 `json:"last_seq,omitempty"`
}

type Envelope struct {
	Version        int             `json:"version"`
	EventID        string          `json:"event_id"`
	Type           string          `json:"type"`
	ConversationID *string         `json:"conversation_id,omitempty"`
	Seq            *int64          `json:"seq,omitempty"`
	OccurredAt     string          `json:"occurred_at"`
	Data           json.RawMessage `json:"data"`
}

func NewEnvelope(eventType string, conversationID *string, seq *int64, data any) (Envelope, error) {
	raw, err := json.Marshal(data)
	if err != nil {
		return Envelope{}, err
	}

	return Envelope{
		Version:        Version,
		EventID:        NewID(),
		Type:           eventType,
		ConversationID: conversationID,
		Seq:            seq,
		OccurredAt:     time.Now().UTC().Format(time.RFC3339),
		Data:           raw,
	}, nil
}

func ErrorEnvelope(requestID, code, message string) Envelope {
	env, _ := NewEnvelope(ServerError, nil, nil, ErrorData{
		Code:      code,
		Message:   message,
		RequestID: requestID,
	})

	return env
}

func NewID() string {
	var bytes [16]byte

	if _, err := rand.Read(bytes[:]); err != nil {
		binary.BigEndian.PutUint64(bytes[:8], uint64(time.Now().UnixNano()))
	}

	bytes[6] = (bytes[6] & 0x0f) | 0x40
	bytes[8] = (bytes[8] & 0x3f) | 0x80

	encoded := make([]byte, 36)
	hex.Encode(encoded[0:8], bytes[0:4])
	encoded[8] = '-'
	hex.Encode(encoded[9:13], bytes[4:6])
	encoded[13] = '-'
	hex.Encode(encoded[14:18], bytes[6:8])
	encoded[18] = '-'
	hex.Encode(encoded[19:23], bytes[8:10])
	encoded[23] = '-'
	hex.Encode(encoded[24:36], bytes[10:16])

	return string(encoded)
}
