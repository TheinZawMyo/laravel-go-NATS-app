import type { ContactRequest } from './chat';

export type RealtimeEventType =
    | 'connection.ready'
    | 'connection.error'
    | 'pong'
    | 'message.created'
    | 'message.updated'
    | 'message.deleted'
    | 'message.reaction.updated'
    | 'message.pinned'
    | 'message.unpinned'
    | 'receipt.updated'
    | 'typing.updated'
    | 'presence.updated'
    | 'conversation.updated'
    | 'conversation.removed'
    | 'conversation.subscribed'
    | 'contact.request.created'
    | 'contact.request.updated'
    | 'contact.updated'
    | 'block.updated'
    | 'sync.required'
    | 'call.signal';

export type RealtimeEnvelope<T = unknown> = {
    version: number;
    event_id: string;
    type: RealtimeEventType;
    conversation_id?: string | null;
    seq?: number | null;
    occurred_at: string;
    data: T;
};

export type ConnectionReadyData = {
    user_id: string;
    instance_id: string;
    version: number;
    heartbeat_seconds: number;
    max_subscriptions: number;
    max_frame_bytes: number;
    connection_token_expires_at?: string;
};

export type ConnectionErrorData = {
    code: string;
    message: string;
    request_id?: string;
};

export type TypingEventData = {
    conversation_id: string;
    user_id: string;
    is_typing: boolean;
    expires_at: string;
};

export type ReceiptEventData = {
    conversation_id: string;
    user_id: string;
    message_id: string;
    seq: number;
    read: boolean;
};

export type PresenceEventData = {
    conversation_id: string;
    user_id: string;
    online: boolean;
    last_seen_at?: string;
};

export type SubscribedEventData = {
    conversation_id: string;
    latest_seq: number;
    last_seq?: number | null;
};

export type SyncRequiredEventData = {
    conversation_id: string;
    last_seq: number;
    latest_seq: number;
    reason: string;
};

/**
 * Conversation metadata frames never carry the conversation itself: read
 * cursors and unread counts are per viewer, so the payload only says what
 * changed and the client refetches over HTTP.
 */
export type ConversationChangedEventData = {
    conversation_id: string;
    reason: string;
};

export type ConversationRemovedEventData = {
    conversation_id: string;
};

export type MessagePinnedEventData = {
    conversation_id: string;
    message_id: string;
    pinned_by: string;
};

export type ContactUpdatedEventData = {
    user_id: string;
    contact_id: string;
};

export type BlockUpdatedEventData = {
    user_id: string;
    blocked_user_id: string;
    blocked: boolean;
};

export type ContactRequestEventData = ContactRequest;

export type GatewayCredentials = {
    token: string;
    expires_at: string;
    url: string | null;
};

export type RealtimeStatus =
    | 'disconnected'
    | 'connecting'
    | 'connected'
    | 'reconnecting';
