import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Contact, ContactRequest, PublicUser, UserBlock } from '@/types';
import { useContactsStore } from './contacts';

const alice: PublicUser = {
    id: 'user-2',
    name: 'Alice',
    username: 'alice',
    avatar_url: null,
    bio: null,
    status_text: null,
    last_seen_at: null,
};

const bob: PublicUser = {
    id: 'user-3',
    name: 'Bob',
    username: 'bob',
    avatar_url: null,
    bio: null,
    status_text: null,
    last_seen_at: null,
};

const me: PublicUser = {
    id: 'user-1',
    name: 'Test User',
    username: 'testuser',
    avatar_url: null,
    bio: null,
    status_text: null,
    last_seen_at: null,
};

function contact(overrides: Partial<Contact> = {}): Contact {
    return {
        id: 'contact-1',
        nickname: null,
        user: alice,
        created_at: '2026-10-30T00:00:00Z',
        ...overrides,
    };
}

function request(overrides: Partial<ContactRequest> = {}): ContactRequest {
    return {
        id: 'request-1',
        status: 'pending',
        message: null,
        direction: 'incoming',
        sender: alice,
        receiver: me,
        responded_at: null,
        created_at: '2026-10-30T00:00:00Z',
        updated_at: '2026-10-30T00:00:00Z',
        ...overrides,
    };
}

function block(overrides: Partial<UserBlock> = {}): UserBlock {
    return {
        id: 'block-1',
        user: alice,
        reason: null,
        created_at: '2026-10-30T00:00:00Z',
        ...overrides,
    };
}

function success(body: unknown, status = 200): Response {
    return {
        ok: true,
        status,
        json: async () => body,
    } as Response;
}

const fetchMock = vi.fn();

describe('contacts store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    it('loads contacts, requests and blocks together', async () => {
        const store = useContactsStore();

        fetchMock.mockImplementation(async (url: string) => {
            if (url === '/api/v1/contacts') {
                return success({ data: [contact()] });
            }

            if (url === '/api/v1/contact-requests?direction=incoming') {
                return success({ data: [request()] });
            }

            if (url === '/api/v1/contact-requests?direction=outgoing') {
                return success({
                    data: [request({ id: 'request-2', receiver: bob })],
                });
            }

            if (url === '/api/v1/blocks') {
                return success({ data: [block()] });
            }

            throw new Error(`Unexpected request: ${url}`);
        });

        await store.fetchAll();

        expect(store.contacts).toHaveLength(1);
        expect(store.pendingIncomingCount).toBe(1);
        expect(store.outgoingRequests).toHaveLength(1);
        expect(store.blocks).toHaveLength(1);
        expect(store.relationshipFor('alice')).toBe('contact');
        expect(store.relationshipFor('bob')).toBe('request_sent');
        expect(store.isBlocked('alice')).toBe(true);
    });

    it('searches users by the trimmed term', async () => {
        const store = useContactsStore();

        fetchMock.mockResolvedValueOnce(
            success({ data: [{ ...bob, relationship: 'none' }] }),
        );

        await store.searchUsers('  @bob ');

        expect(store.searchResults).toHaveLength(1);
        expect(store.searchResults[0]?.username).toBe('bob');

        const [url] = fetchMock.mock.calls[0] as [string];
        expect(url).toBe('/api/v1/users?q=bob');

        store.clearSearch();

        expect(store.searchResults).toHaveLength(0);
    });

    it('accepts a request and refreshes the address book', async () => {
        const store = useContactsStore();
        store.incomingRequests = [request()];

        fetchMock.mockImplementation(async (url: string) => {
            if (url === '/api/v1/contact-requests/request-1/accept') {
                return success({
                    data: request({ status: 'accepted' }),
                });
            }

            if (url === '/api/v1/contacts') {
                return success({ data: [contact()] });
            }

            throw new Error(`Unexpected request: ${url}`);
        });

        await store.acceptRequest(request());

        expect(store.incomingRequests).toHaveLength(0);
        expect(store.contacts).toHaveLength(1);
        expect(store.relationshipFor('alice')).toBe('contact');
    });

    it('blocks a user and drops them from the contact list', async () => {
        const store = useContactsStore();
        store.contacts = [contact()];

        fetchMock.mockResolvedValueOnce(success({ data: block() }, 201));

        await store.blockUser('alice');

        expect(store.blocks).toHaveLength(1);
        expect(store.contacts).toHaveLength(0);
        expect(store.isBlocked('alice')).toBe(true);

        fetchMock.mockResolvedValueOnce({
            ok: true,
            status: 204,
            json: async () => null,
        } as Response);

        await store.unblockUser('alice');

        expect(store.blocks).toHaveLength(0);

        const [url, options] = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(url).toBe('/api/v1/blocks/alice');
        expect(options.method).toBe('DELETE');
    });

    it('records a sent contact request as outgoing', async () => {
        const store = useContactsStore();

        fetchMock.mockResolvedValueOnce(
            success({ data: request({ receiver: bob }) }, 201),
        );

        await store.sendRequest('bob', 'Hi there');

        expect(store.outgoingRequests).toHaveLength(1);
        expect(store.relationshipFor('bob')).toBe('request_sent');

        const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/api/v1/contact-requests');
        expect(JSON.parse(options.body as string)).toEqual({
            username: 'bob',
            message: 'Hi there',
        });
    });
});
