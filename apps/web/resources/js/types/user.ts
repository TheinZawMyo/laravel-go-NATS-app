/**
 * How the signed in user relates to another account. Only user search fills
 * this in, which is why it stays optional on the shared representation.
 */
export type UserRelationship =
    | 'none'
    | 'contact'
    | 'request_sent'
    | 'request_received';

export type PublicUser = {
    id: string;
    name: string;
    username: string;
    avatar_url: string | null;
    bio: string | null;
    status_text: string | null;
    last_seen_at: string | null;
    online?: boolean;
    relationship?: UserRelationship;
};

export type UserDevice = {
    id: string;
    name: string | null;
    platform: string | null;
    last_active_at: string | null;
    revoked_at: string | null;
    created_at: string;
};
