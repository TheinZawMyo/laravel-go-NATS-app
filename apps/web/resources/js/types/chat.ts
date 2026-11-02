import type { PublicUser } from './user';

export type ConversationType = 'direct' | 'group';

export type ConversationRole = 'member' | 'moderator' | 'admin' | 'owner';

export type MessageType = 'text' | 'system';

export type MessageStatus = 'sending' | 'sent' | 'failed';

export type AttachmentKind = 'image' | 'video' | 'audio' | 'file';

export type MessageAttachment = {
    id: string;
    message_id: string | null;
    name: string;
    mime_type: string;
    kind: AttachmentKind;
    size_bytes: number;
    width: number | null;
    height: number | null;
    duration_ms: number | null;
    url: string | null;
    thumbnail_url: string | null;
    created_at: string;
    /** Gallery rows carry the seq of the message they belong to. */
    message_seq?: number | null;
};

export type ContactRequestStatus =
    | 'pending'
    | 'accepted'
    | 'rejected'
    | 'cancelled';

export type ContactRequestDirection = 'incoming' | 'outgoing';

export type ChatMessage = {
    id: string;
    conversation_id: string;
    seq: number;
    client_message_id: string;
    sender: PublicUser;
    type: MessageType;
    body: string | null;
    attachments: MessageAttachment[];
    reply_to: ChatMessage | null;
    edited_at: string | null;
    deleted_at: string | null;
    created_at: string;
    updated_at: string;
    status?: MessageStatus;
    error?: string | null;
};

export type ConversationMember = {
    role: ConversationRole;
    last_read_seq: number;
    user: PublicUser;
};

export type Conversation = {
    id: string;
    type: ConversationType;
    title: string | null;
    description: string | null;
    avatar_url: string | null;
    unread_count: number;
    last_read_seq: number;
    next_message_seq: number;
    viewer_role: ConversationRole | null;
    history_visible_to_new_members: boolean;
    muted_until: string | null;
    is_muted: boolean;
    archived: boolean;
    participant: PublicUser | null;
    last_message: ChatMessage | null;
    members: ConversationMember[];
    created_at: string;
    updated_at: string;
};

export type Contact = {
    id: string;
    nickname: string | null;
    user: PublicUser;
    created_at: string;
};

export type ContactRequest = {
    id: string;
    status: ContactRequestStatus;
    message: string | null;
    direction: ContactRequestDirection | null;
    sender: PublicUser;
    receiver: PublicUser;
    responded_at: string | null;
    created_at: string;
    updated_at: string;
};

export type UserBlock = {
    id: string;
    user: PublicUser;
    reason: string | null;
    created_at: string;
};

export type MessagePin = {
    conversation_id: string;
    message: ChatMessage;
    pinned_by: PublicUser;
    pinned_at: string;
};

export type InviteConversationPreview = {
    id: string;
    type: ConversationType;
    title: string | null;
    description: string | null;
    avatar_url: string | null;
    member_count: number;
};

export type ConversationInvite = {
    id: string;
    token: string;
    url: string;
    expires_at: string | null;
    revoked_at: string | null;
    max_uses: number | null;
    uses: number;
    is_active: boolean;
    created_at: string;
    conversation?: InviteConversationPreview;
};

export type MessagePage = {
    data: ChatMessage[];
    meta: {
        has_more: boolean;
        next_cursor: number | null;
    };
};

export type MediaPage = {
    data: MessageAttachment[];
    meta: {
        has_more: boolean;
        next_cursor: number | null;
    };
};

export type ChatPageProps = {
    conversations: Conversation[];
    activeConversation: Conversation | null;
    activeMessages: ChatMessage[];
    activeHasMore: boolean;
    activeNextCursor: number | null;
    currentUserId: string;
};

export type JoinPageProps = {
    token: string;
};
