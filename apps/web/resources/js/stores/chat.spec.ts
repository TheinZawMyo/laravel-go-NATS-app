import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    ChatMessage,
    Conversation,
    MessageAttachment,
    MessagePin,
    User,
} from '@/types';
import { useAuthStore } from './auth';
import { useChatStore } from './chat';

const user: User = {
    id: 'user-1',
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

const other = {
    id: 'user-2',
    name: 'Alice',
    username: 'alice',
    avatar_url: null,
    bio: null,
    status_text: null,
    last_seen_at: null,
};

function createConversation(
    overrides: Partial<Conversation> = {},
): Conversation {
    return {
        id: 'conversation-1',
        type: 'direct',
        title: null,
        description: null,
        avatar_url: null,
        unread_count: 0,
        last_read_seq: 0,
        next_message_seq: 0,
        viewer_role: 'member',
        history_visible_to_new_members: false,
        muted_until: null,
        is_muted: false,
        archived: false,
        participant: other,
        last_message: null,
        members: [
            { role: 'member', last_read_seq: 0, user },
            { role: 'member', last_read_seq: 0, user: other },
        ],
        created_at: '2026-10-29T00:00:00Z',
        updated_at: '2026-10-29T00:00:00Z',
        ...overrides,
    };
}

const conversation = createConversation();

function message(overrides: Partial<ChatMessage> = {}): ChatMessage {
    return {
        id: 'message-1',
        conversation_id: conversation.id,
        seq: 1,
        client_message_id: 'client-1',
        sender: other,
        type: 'text',
        body: 'Hello',
        attachments: [],
        reply_to: null,
        edited_at: null,
        deleted_at: null,
        created_at: '2026-10-29T00:00:00Z',
        updated_at: '2026-10-29T00:00:00Z',
        ...overrides,
    };
}

function attachment(
    overrides: Partial<MessageAttachment> = {},
): MessageAttachment {
    return {
        id: 'attachment-1',
        message_id: 'message-1',
        name: 'photo.png',
        mime_type: 'image/png',
        kind: 'image',
        size_bytes: 1024,
        width: 100,
        height: 100,
        duration_ms: null,
        url: '/api/v1/messages/message-1/attachments/attachment-1',
        thumbnail_url: null,
        created_at: '2026-10-29T00:00:00Z',
        message_seq: 10,
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

describe('chat store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        fetchMock.mockReset();
        fetchMock.mockResolvedValue(success({ data: { last_read_seq: 0 } }));
        vi.stubGlobal('fetch', fetchMock);
        useAuthStore().setUser(user);
    });

    it('initialises conversations and active messages in order', () => {
        const store = useChatStore();

        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [
                message({ id: 'message-2', seq: 2, body: 'Second' }),
                message({ id: 'message-1', seq: 1, body: 'First' }),
            ],
            activeHasMore: false,
            activeNextCursor: 1,
        });

        expect(store.conversations).toHaveLength(1);
        expect(store.activeConversation?.id).toBe(conversation.id);
        expect(store.messages.map((item) => item.seq)).toEqual([1, 2]);
        expect(store.nextCursor).toBe(1);
    });

    it('sends optimistically and reconciles with the server response', async () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        fetchMock.mockImplementationOnce(
            async (_url: string, options: RequestInit) => {
                const payload = JSON.parse(options.body as string) as Record<
                    string,
                    string
                >;

                return success(
                    {
                        data: message({
                            id: 'server-message',
                            client_message_id: payload.client_message_id,
                            sender: user,
                            body: 'Hello',
                        }),
                    },
                    201,
                );
            },
        );

        const pending = store.sendMessage('Hello');

        expect(store.messages).toHaveLength(1);
        expect(store.messages[0]?.status).toBe('sending');

        const sent = await pending;

        expect(sent?.id).toBe('server-message');
        expect(store.messages).toHaveLength(1);
        expect(store.messages[0]?.status).toBe('sent');
        expect(store.messages[0]?.id).toBe('server-message');
        expect(store.activeConversation?.last_message?.id).toBe(
            'server-message',
        );

        const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
        const body = JSON.parse(options.body as string) as Record<
            string,
            unknown
        >;

        expect(url).toBe(`/api/v1/conversations/${conversation.id}/messages`);
        expect(options.method).toBe('POST');
        expect(body.body).toBe('Hello');
        expect(typeof body.client_message_id).toBe('string');
    });

    it('marks an optimistic message as failed and can retry it', async () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        fetchMock.mockResolvedValueOnce({
            ok: false,
            status: 500,
            json: async () => ({ message: 'Server error' }),
        } as Response);

        await store.sendMessage('Hello');

        expect(store.messages[0]?.status).toBe('failed');
        expect(store.error).toBe('Server error');

        const failed = store.messages[0]!;
        const clientMessageId = failed.client_message_id;

        fetchMock.mockResolvedValueOnce(
            success({
                data: message({
                    id: 'server-message',
                    client_message_id: clientMessageId,
                    sender: user,
                    body: 'Hello',
                }),
            }),
        );

        await store.retryMessage(failed);

        expect(store.messages).toHaveLength(1);
        expect(store.messages[0]?.id).toBe('server-message');
        expect(store.messages[0]?.status).toBe('sent');

        const [, options] = fetchMock.mock.calls[1] as [string, RequestInit];
        const body = JSON.parse(options.body as string) as Record<
            string,
            unknown
        >;

        expect(body.client_message_id).toBe(clientMessageId);
    });

    it('prepends older messages and advances the cursor', async () => {
        const store = useChatStore();
        const activeConversation = createConversation({
            last_read_seq: 32,
            next_message_seq: 32,
        });

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [
                message({ id: 'message-31', seq: 31 }),
                message({ id: 'message-32', seq: 32 }),
            ],
            activeHasMore: true,
            activeNextCursor: 31,
        });

        fetchMock.mockResolvedValueOnce(
            success({
                data: [
                    message({ id: 'message-29', seq: 29 }),
                    message({ id: 'message-30', seq: 30 }),
                ],
                meta: { has_more: false, next_cursor: 29 },
            }),
        );

        await store.loadOlder();

        expect(store.messages.map((item) => item.seq)).toEqual([
            29, 30, 31, 32,
        ]);
        expect(store.hasMore).toBe(false);
        expect(store.nextCursor).toBe(29);

        const [url] = fetchMock.mock.calls[0] as [string];
        expect(url).toContain('before_seq=31');
    });

    it('loads a conversation and marks its latest message as read', async () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation: null,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        fetchMock.mockImplementation(async (url: string) => {
            if (url.endsWith('/messages')) {
                return success({
                    data: [message({ id: 'message-5', seq: 5 })],
                    meta: { has_more: false, next_cursor: 5 },
                });
            }

            return success({ data: { last_read_seq: 5 } });
        });

        await store.selectConversation(conversation.id);

        expect(store.activeConversationId).toBe(conversation.id);
        expect(store.messages).toHaveLength(1);
        expect(store.activeConversation?.last_read_seq).toBe(5);

        const endpoints = fetchMock.mock.calls.map(([url]) => url as string);
        expect(endpoints).toContain(
            `/api/v1/conversations/${conversation.id}/read`,
        );
    });

    it('applies realtime messages and increments unread counts for inactive chats', () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation: null,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        const incoming = message({
            id: 'message-9',
            seq: 9,
            sender: other,
            body: 'Live update',
        });

        store.applyRealtimeMessage(incoming);

        expect(store.messages).toHaveLength(0);
        expect(store.conversations[0]?.last_message?.id).toBe('message-9');
        expect(store.conversations[0]?.unread_count).toBe(1);

        store.applyRealtimeMessage(incoming);

        expect(store.conversations[0]?.unread_count).toBe(1);
    });

    it('reconciles realtime messages with optimistic and HTTP messages', () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        const sent = message({
            id: 'server-message',
            client_message_id: 'client-reconcile',
            seq: 1,
            sender: user,
            body: 'Hello',
        });

        store.applyRealtimeMessage(sent);
        store.applyRealtimeMessage(sent);

        expect(store.messages).toHaveLength(1);
        expect(store.messages[0]?.id).toBe('server-message');
        expect(store.messages[0]?.status).toBe('sent');
        expect(store.activeConversation?.last_message?.id).toBe(
            'server-message',
        );
    });

    it('tracks read receipts monotonically and clears the local unread count', () => {
        const store = useChatStore();
        const activeConversation = createConversation({ unread_count: 3 });

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        store.applyReceipt(conversation.id, other.id, 5);

        expect(store.othersReadSeq(conversation.id)).toBe(5);

        // Receipts are per member cursors, so a stale one cannot move backwards.
        store.applyReceipt(conversation.id, other.id, 2);

        expect(store.othersReadSeq(conversation.id)).toBe(5);

        store.applyReceipt(conversation.id, user.id, 4);

        expect(store.activeConversation?.last_read_seq).toBe(4);
        expect(store.activeConversation?.unread_count).toBe(0);
    });

    it('keeps the unread badge when another device read an older message', () => {
        const store = useChatStore();
        const activeConversation = createConversation({
            unread_count: 4,
            last_message: message({ seq: 9 }),
        });

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        store.applyReceipt(conversation.id, user.id, 4);

        expect(store.activeConversation?.last_read_seq).toBe(4);
        expect(store.activeConversation?.unread_count).toBe(4);
    });

    it('applies presence updates to the participant and members', () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        store.applyPresence(conversation.id, other.id, true);

        expect(store.activeConversation?.participant?.online).toBe(true);
        expect(
            store.activeConversation?.members.find(
                (member) => member.user.id === other.id,
            )?.user.online,
        ).toBe(true);

        store.applyPresence(
            conversation.id,
            other.id,
            false,
            '2026-10-30T12:00:00Z',
        );

        expect(store.activeConversation?.participant?.online).toBe(false);
        expect(store.activeConversation?.participant?.last_seen_at).toBe(
            '2026-10-30T12:00:00Z',
        );
    });

    it('reports the last applied sequence for gap detection', () => {
        const store = useChatStore();
        const activeConversation = createConversation({
            last_message: message({ seq: 4 }),
        });

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [message({ seq: 4 })],
            activeHasMore: false,
            activeNextCursor: null,
        });

        expect(store.lastAppliedSeq(conversation.id)).toBe(4);
        expect(store.lastAppliedSeq('conversation-unknown')).toBeNull();

        store.applyRealtimeMessage(
            message({ id: 'message-9', seq: 9, sender: other }),
        );

        expect(store.lastAppliedSeq(conversation.id)).toBe(9);
    });

    it('refetches a conversation over HTTP when a resync is required', async () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        fetchMock.mockReset();
        fetchMock.mockImplementation(async (url: string) => {
            if (url.endsWith('/messages')) {
                return success({
                    data: [
                        message({ id: 'message-7', seq: 7, body: 'Missed' }),
                    ],
                    meta: { has_more: false, next_cursor: null },
                });
            }

            return success({ data: [activeConversation] });
        });

        await store.syncConversation(activeConversation.id);

        // A resync refetches summaries, then the visible conversation page.
        expect(
            fetchMock.mock.calls
                .map(([url]) => url)
                .filter((url) => !url.endsWith('/read')),
        ).toEqual([
            '/api/v1/conversations',
            `/api/v1/conversations/${conversation.id}/messages`,
        ]);
        expect(store.messages.map((item) => item.seq)).toContain(7);
    });

    it('collapses concurrent resyncs for the same conversation', async () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        fetchMock.mockReset();
        fetchMock.mockImplementation(async (url: string) =>
            url.endsWith('/messages')
                ? success({
                      data: [],
                      meta: { has_more: false, next_cursor: null },
                  })
                : success({ data: [activeConversation] }),
        );

        await Promise.all([
            store.syncConversation(activeConversation.id),
            store.syncConversation(activeConversation.id),
        ]);

        const urls = (): string[] => fetchMock.mock.calls.map(([url]) => url);

        // One summary fetch and one message page, not two of each.
        expect(
            urls().filter((url) => url === '/api/v1/conversations'),
        ).toHaveLength(1);
        expect(urls().filter((url) => url.endsWith('/messages'))).toHaveLength(
            1,
        );

        // Once the round trip settles, the next gap can resync again.
        await store.syncConversation(activeConversation.id);

        expect(
            urls().filter((url) => url === '/api/v1/conversations'),
        ).toHaveLength(2);
        expect(urls().filter((url) => url.endsWith('/messages'))).toHaveLength(
            2,
        );
    });

    it('opens a direct conversation through the API', async () => {
        const store = useChatStore();

        fetchMock.mockImplementation(async (url: string) => {
            if (url.endsWith('/conversations/direct')) {
                return success({ data: conversation }, 201);
            }

            return success({
                data: [],
                meta: { has_more: false, next_cursor: null },
            });
        });

        await store.openDirect('alice');

        expect(store.conversations[0]?.id).toBe(conversation.id);
        expect(store.activeConversationId).toBe(conversation.id);

        const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/api/v1/conversations/direct');
        expect(JSON.parse(options.body as string)).toEqual({
            username: 'alice',
        });
    });

    it('merges a partial mute response without losing the preview', async () => {
        const store = useChatStore();
        const activeConversation = createConversation({
            last_message: message(),
        });

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        fetchMock.mockResolvedValueOnce(
            success({
                data: {
                    id: conversation.id,
                    is_muted: true,
                    muted_until: '2030-01-01T00:00:00Z',
                },
            }),
        );

        await store.setMuted(conversation.id, true);

        expect(store.activeConversation?.is_muted).toBe(true);
        expect(store.activeConversation?.muted_until).toBe(
            '2030-01-01T00:00:00Z',
        );
        expect(store.activeConversation?.last_message?.id).toBe('message-1');
        expect(store.activeConversation?.participant?.username).toBe('alice');

        const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];

        expect(url).toBe(`/api/v1/conversations/${conversation.id}/mute`);
        expect(options.method).toBe('POST');
        expect(JSON.parse(options.body as string)).toEqual({
            is_muted: true,
            until: null,
        });
    });

    it('archives a conversation through the API', async () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        fetchMock.mockResolvedValueOnce(
            success({ data: { id: conversation.id, archived: true } }),
        );

        await store.setArchived(conversation.id, true);

        expect(store.activeConversation?.archived).toBe(true);

        const [, options] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(options.method).toBe('POST');
        expect(JSON.parse(options.body as string)).toEqual({ archived: true });
    });

    it('loads pinned messages and unpins one', async () => {
        const store = useChatStore();
        const activeConversation = createConversation();

        store.initialize({
            conversations: [activeConversation],
            activeConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        const pin: MessagePin = {
            conversation_id: conversation.id,
            message: message(),
            pinned_by: user,
            pinned_at: '2026-10-30T00:00:00Z',
        };

        fetchMock.mockResolvedValueOnce(success({ data: [pin] }));
        await store.fetchPins(conversation.id);

        expect(store.isPinned(conversation.id, 'message-1')).toBe(true);

        fetchMock.mockResolvedValueOnce({
            ok: true,
            status: 204,
            json: async () => null,
        } as Response);

        await store.unpinMessage(pin.message);

        expect(store.pinsFor(conversation.id)).toHaveLength(0);

        const [url, options] = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(url).toBe('/api/v1/messages/message-1/pin');
        expect(options.method).toBe('DELETE');
    });

    it('removes a conversation the viewer leaves', async () => {
        const store = useChatStore();
        const group = createConversation({
            id: 'group-1',
            type: 'group',
            title: 'Team',
            participant: null,
            viewer_role: 'member',
        });

        store.initialize({
            conversations: [group],
            activeConversation: group,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        fetchMock.mockResolvedValueOnce({
            ok: true,
            status: 204,
            json: async () => null,
        } as Response);

        await store.leaveGroup('group-1');

        expect(store.conversations).toHaveLength(0);
        expect(store.activeConversation).toBeNull();
    });

    it('pages the shared media gallery with the cursor and skips duplicates', async () => {
        const store = useChatStore();

        fetchMock.mockResolvedValueOnce(
            success({
                data: [
                    attachment({ id: 'attachment-3', message_seq: 30 }),
                    attachment({ id: 'attachment-2', message_seq: 20 }),
                ],
                meta: { has_more: true, next_cursor: 20 },
            }),
        );

        await store.fetchMedia(conversation.id, true);

        expect(store.mediaFor(conversation.id).map((item) => item.id)).toEqual([
            'attachment-3',
            'attachment-2',
        ]);
        expect(store.mediaHasMoreFor(conversation.id)).toBe(true);

        const [firstUrl] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(firstUrl).toContain(
            `/api/v1/conversations/${conversation.id}/media?`,
        );
        expect(firstUrl).toContain('limit=40');
        expect(firstUrl).not.toContain('before_seq');

        // The retry repeats an id the client already holds: dedupe, don't grow.
        fetchMock.mockResolvedValueOnce(
            success({
                data: [
                    attachment({ id: 'attachment-2', message_seq: 20 }),
                    attachment({ id: 'attachment-1', message_seq: 10 }),
                ],
                meta: { has_more: false, next_cursor: null },
            }),
        );

        await store.fetchMedia(conversation.id);

        expect(store.mediaFor(conversation.id).map((item) => item.id)).toEqual([
            'attachment-3',
            'attachment-2',
            'attachment-1',
        ]);
        expect(store.mediaHasMoreFor(conversation.id)).toBe(false);

        const [secondUrl] = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(secondUrl).toContain('before_seq=20');
    });

    it('reloads the gallery from the newest attachment on reset', async () => {
        const store = useChatStore();

        fetchMock.mockResolvedValueOnce(
            success({
                data: [attachment({ id: 'attachment-2', message_seq: 20 })],
                meta: { has_more: true, next_cursor: 20 },
            }),
        );

        await store.fetchMedia(conversation.id, true);

        fetchMock.mockResolvedValueOnce(
            success({
                data: [attachment({ id: 'attachment-9', message_seq: 90 })],
                meta: { has_more: true, next_cursor: 90 },
            }),
        );

        await store.fetchMedia(conversation.id, true);

        expect(store.mediaFor(conversation.id).map((item) => item.id)).toEqual([
            'attachment-9',
        ]);

        const [url] = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(url).not.toContain('before_seq');
    });

    it('returns an empty gallery for an unknown conversation', () => {
        const store = useChatStore();

        expect(store.mediaFor(null)).toEqual([]);
        expect(store.mediaHasMoreFor(null)).toBe(false);
    });
});
