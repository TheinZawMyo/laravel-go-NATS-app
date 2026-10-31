export type PublicUser = {
    id: string;
    name: string;
    username: string;
    avatar_url: string | null;
    bio: string | null;
    status_text: string | null;
    last_seen_at: string | null;
    online?: boolean;
};

export type UserDevice = {
    id: string;
    name: string | null;
    platform: string | null;
    last_active_at: string | null;
    revoked_at: string | null;
    created_at: string;
};
