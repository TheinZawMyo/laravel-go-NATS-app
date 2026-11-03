import type { ChatMessage } from './chat';
import type { PublicUser } from './user';

export type ReportReason =
    | 'spam'
    | 'harassment'
    | 'impersonation'
    | 'scam'
    | 'inappropriate_content'
    | 'other';

export type UserReportStatus = 'open' | 'resolved' | 'dismissed';

export type UserReport = {
    id: string;
    reason: ReportReason;
    details: string | null;
    status: UserReportStatus;
    action_taken: string | null;
    resolution_note: string | null;
    reviewed_at: string | null;
    reporter?: Pick<
        PublicUser,
        'id' | 'name' | 'username' | 'avatar_url'
    > | null;
    reported_user?: Pick<
        PublicUser,
        'id' | 'name' | 'username' | 'avatar_url'
    > | null;
    reported_message?: ChatMessage | null;
    reviewer?: Pick<
        PublicUser,
        'id' | 'name' | 'username' | 'avatar_url'
    > | null;
    created_at: string;
    updated_at: string;
};

export type MessageSearchResult = ChatMessage & {
    conversation: {
        id: string;
        type: 'direct' | 'group';
        title: string | null;
    };
};

export type AuditLog = {
    id: string;
    action: string;
    subject_type: string | null;
    subject_id: string | null;
    metadata: Record<string, unknown> | null;
    ip_address: string | null;
    actor: Pick<PublicUser, 'id' | 'name' | 'username'> | null;
    created_at: string;
};
