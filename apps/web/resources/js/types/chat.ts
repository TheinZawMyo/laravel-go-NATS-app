import type { PublicUser } from './user';

export type ConversationType = 'direct' | 'group';

export type ConversationRole = 'member' | 'admin' | 'owner';

export type MessageType = 'text' | 'system';

export type MessageStatus = 'sending' | 'sent' | 'failed';

export type ChatMessage = {
    id: string;
    conversation_id: string;
    seq: number;
    client_message_id: string;
    sender: PublicUser;
    type: MessageType;
    body: string | null;
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
    avatar_url: string | null;
    unread_count: number;
    last_read_seq: number;
    next_message_seq: number;
    participant: PublicUser | null;
    last_message: ChatMessage | null;
    members: ConversationMember[];
    created_at: string;
    updated_at: string;
};

export type MessagePage = {
    data: ChatMessage[];
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
