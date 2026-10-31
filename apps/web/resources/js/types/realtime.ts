export type RealtimeEventType =
    | 'connection.ready'
    | 'connection.error'
    | 'pong'
    | 'message.created'
    | 'message.updated'
    | 'message.deleted'
    | 'message.reaction.updated'
    | 'receipt.updated'
    | 'typing.updated'
    | 'presence.updated'
    | 'conversation.updated'
    | 'conversation.subscribed'
    | 'sync.required';

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
