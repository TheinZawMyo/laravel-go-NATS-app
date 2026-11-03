import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import type { User } from '@/types';
import { useAuthStore } from './auth';

const user: User = {
    id: '0192f0f0-0000-7000-8000-000000000000',
    name: 'Test User',
    username: 'testuser',
    email: 'test@example.com',
    avatar_url: null,
    bio: null,
    status_text: null,
    status: 'active',
    is_admin: false,
    last_seen_visibility: 'everyone',
    profile_visibility: 'everyone',
    contact_requests_policy: 'everyone',
    email_verified_at: null,
    last_seen_at: null,
    created_at: '2026-10-29T00:00:00Z',
    updated_at: '2026-10-29T00:00:00Z',
};

describe('auth store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('starts unauthenticated', () => {
        const store = useAuthStore();

        expect(store.user).toBeNull();
        expect(store.isAuthenticated).toBe(false);
        expect(store.displayName).toBe('Guest');
    });

    it('stores the authenticated user', () => {
        const store = useAuthStore();

        store.setUser(user);

        expect(store.user).toEqual(user);
        expect(store.isAuthenticated).toBe(true);
        expect(store.displayName).toBe('Test User');
    });

    it('clears the user on logout', () => {
        const store = useAuthStore();
        store.setUser(user);

        store.clear();

        expect(store.user).toBeNull();
        expect(store.isAuthenticated).toBe(false);
    });
});
