import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ApiError, apiRequest } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useE2eeStore } from '@/stores/e2ee';
import type {
    ChatMessage,
    Conversation,
    ConversationInvite,
    ConversationRole,
    E2eeEncryption,
    MediaPage,
    MessageAttachment,
    MessagePage,
    MessagePin,
    MessageStatus,
    MessageTranslation,
    PublicUser,
} from '@/types';

type ChatPagePayload = {
    conversations: Conversation[];
    activeConversation: Conversation | null;
    activeMessages: ChatMessage[];
    activeHasMore: boolean;
    activeNextCursor: number | null;
};

/**
 * A conversation representation may arrive without `last_message` (the server
 * only includes it when the relation was loaded), so partial responses are
 * merged into the known summary instead of replacing it.
 */
export type ConversationPatch = Partial<Omit<Conversation, 'id'>> & {
    id: string;
};

export type CreateGroupInput = {
    title: string;
    description?: string | null;
    memberIds?: string[];
    historyVisibleToNewMembers?: boolean;
};

export type UpdateGroupInput = {
    title?: string;
    description?: string | null;
    historyVisibleToNewMembers?: boolean;
};

function uuid(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = (Math.random() * 16) | 0;
        const value = char === 'x' ? random : (random & 0x3) | 0x8;

        return value.toString(16);
    });
}

function sortMessages(messages: ChatMessage[]): ChatMessage[] {
    return [...messages].sort((first, second) => {
        if (first.seq !== second.seq) {
            return first.seq - second.seq;
        }

        return first.created_at.localeCompare(second.created_at);
    });
}

function sortConversations(conversations: Conversation[]): Conversation[] {
    return [...conversations].sort((first, second) =>
        second.updated_at.localeCompare(first.updated_at),
    );
}

function messageKey(message: ChatMessage): string {
    return message.id.startsWith('temp-')
        ? `client:${message.client_message_id}`
        : message.id;
}

function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        return error.message;
    }

    if (error instanceof Error) {
        return error.message;
    }

    return 'Something went wrong. Please try again.';
}

/**
 * Mirrors the server's `ConversationRole::rank()` so the UI offers exactly the
 * actions the policies will accept.
 */
function roleRank(role: ConversationRole | null | undefined): number {
    switch (role) {
        case 'owner':
            return 4;
        case 'admin':
            return 3;
        case 'moderator':
            return 2;
        case 'member':
            return 1;
        default:
            return 0;
    }
}

/**
 * Gallery page size. The timeline pages through messages with the server
 * default; the media panel asks explicitly so the grid fills a few rows at a
 * time instead of loading an entire conversation's history at once.
 */
const MEDIA_PAGE_SIZE = 40;

async function messageRequest(
    url: string,
    options: Parameters<typeof apiRequest>[1],
): Promise<ChatMessage> {
    const response = await apiRequest<{ data: ChatMessage }>(url, options);

    return response.data;
}

export const useChatStore = defineStore('chat', () => {
    const auth = useAuthStore();
    const e2ee = useE2eeStore();

    const conversations = ref<Conversation[]>([]);
    const activeConversationId = ref<string | null>(null);
    const messages = ref<ChatMessage[]>([]);
    const hasMore = ref(false);
    const nextCursor = ref<number | null>(null);
    const replyTo = ref<ChatMessage | null>(null);

    /**
     * Highest server sequence applied per conversation. It is the cursor the
     * realtime client sends as `last_seq` so the gateway can flag missed events.
     */
    const appliedSeq = ref<Record<string, number>>({});

    const loadingConversations = ref(false);
    const loadingMessages = ref(false);
    const loadingMore = ref(false);
    const refreshing = ref(false);
    const sending = ref(false);
    const error = ref<string | null>(null);

    /** Pinned messages per conversation, loaded lazily by the pins panel. */
    const pins = ref<Record<string, MessagePin[]>>({});
    const loadingPins = ref(false);

    /**
     * Shared media per conversation, loaded lazily by the media gallery. The
     * rows are newest message first, so `mediaCursor` is the seq of the oldest
     * message already loaded and doubles as the `before_seq` page cursor.
     */
    const media = ref<Record<string, MessageAttachment[]>>({});
    const mediaCursor = ref<Record<string, number | null>>({});
    const mediaHasMore = ref<Record<string, boolean>>({});
    const loadingMedia = ref(false);

    /** Invite links per group, loaded lazily by the group info dialog. */
    const invites = ref<Record<string, ConversationInvite[]>>({});
    const loadingInvites = ref(false);

    /**
     * Translations are immutable per message version and language, so the
     * client mirrors the server cache instead of asking again on every open.
     * Keys are `${messageId}:${language}`.
     */
    const translations = ref<Record<string, MessageTranslation>>({});
    const translating = ref<Record<string, boolean>>({});

    let selectionRequest = 0;

    /** Conversations with a resync already in flight, keyed by id. */
    const resyncing = new Set<string>();

    const activeConversation = computed<Conversation | null>(
        () =>
            conversations.value.find(
                (conversation) =>
                    conversation.id === activeConversationId.value,
            ) ?? null,
    );

    const activeIsEncrypted = computed(
        () => activeConversation.value?.e2ee_enabled === true,
    );

    async function decryptInto(message: ChatMessage): Promise<void> {
        if (
            message.is_encrypted !== true ||
            message.encryption === null ||
            message.encryption === undefined ||
            message.body !== null
        ) {
            return;
        }

        try {
            message.body = await e2ee.decryptMessage(
                message.conversation_id,
                message.encryption,
            );
            message.decryption_failed = false;
        } catch {
            message.decryption_failed = true;
        }
    }

    async function decryptList(
        conversationId: string,
        list: ChatMessage[],
    ): Promise<void> {
        const encrypted = list.filter(
            (message) =>
                message.is_encrypted === true &&
                message.encryption !== null &&
                message.encryption !== undefined &&
                message.body === null,
        );

        for (const message of encrypted) {
            try {
                message.body = await e2ee.decryptMessage(
                    conversationId,
                    message.encryption as E2eeEncryption,
                );
                message.decryption_failed = false;
            } catch {
                message.decryption_failed = true;
            }
        }
    }

    async function ensureConversationKeys(
        conversationId: string,
    ): Promise<void> {
        const conversation = conversations.value.find(
            (current) => current.id === conversationId,
        );

        if (conversation?.e2ee_enabled !== true) {
            return;
        }

        try {
            await e2ee.ensureConversationReady(conversation);
        } catch {
            // Locked devices surface the state in the composer; key setup is
            // retried when the user unlocks.
        }
    }

    async function refreshDecryption(): Promise<void> {
        const conversation = activeConversation.value;

        if (conversation === null) {
            return;
        }

        await ensureConversationKeys(conversation.id);
        await decryptList(conversation.id, messages.value);

        for (const current of conversations.value) {
            if (current.last_message?.is_encrypted === true) {
                await decryptInto(current.last_message);
            }
        }
    }

    async function enableActiveEncryption(): Promise<void> {
        const conversation = activeConversation.value;

        if (conversation === null) {
            return;
        }

        await e2ee.enableConversation(conversation);
        await refreshConversation(conversation.id);
        await refreshDecryption();
    }

    function noteAppliedSeq(conversationId: string, seq: number): void {
        if (!Number.isFinite(seq) || seq <= 0) {
            return;
        }

        if (seq <= (appliedSeq.value[conversationId] ?? 0)) {
            return;
        }

        appliedSeq.value = { ...appliedSeq.value, [conversationId]: seq };
    }

    /**
     * The highest sequence this client has rendered for a conversation. Falls
     * back to the newest message in the summary so a freshly loaded page still
     * resumes from a sensible cursor.
     */
    function lastAppliedSeq(conversationId: string): number | null {
        const recorded = appliedSeq.value[conversationId] ?? 0;

        if (recorded > 0) {
            return recorded;
        }

        const latest =
            conversations.value.find(
                (conversation) => conversation.id === conversationId,
            )?.last_message?.seq ?? 0;

        return latest > 0 ? latest : null;
    }

    /**
     * Read cursors are authoritative in Laravel. Realtime receipts only make
     * them show up sooner, and they are applied monotonically per member.
     */
    function applyReceipt(
        conversationId: string,
        userId: string,
        seq: number,
    ): void {
        if (!Number.isFinite(seq) || seq <= 0) {
            return;
        }

        const conversation = conversations.value.find(
            (current) => current.id === conversationId,
        );

        if (conversation !== undefined) {
            const member = conversation.members.find(
                (current) => current.user.id === userId,
            );

            if (member !== undefined) {
                member.last_read_seq = Math.max(member.last_read_seq, seq);
            }

            if (userId === auth.user?.id) {
                conversation.last_read_seq = Math.max(
                    conversation.last_read_seq,
                    seq,
                );

                // Another device of the same user may read an older message
                // while newer ones are still unread, so only clear the badge
                // once the cursor reaches the newest known message.
                const latest = conversation.last_message?.seq ?? 0;

                if (seq >= latest) {
                    conversation.unread_count = 0;
                }
            }
        }
    }

    function readSeqFor(conversationId: string, userId: string): number {
        const member = conversations.value
            .find((conversation) => conversation.id === conversationId)
            ?.members.find((current) => current.user.id === userId);

        return member?.last_read_seq ?? 0;
    }

    /**
     * Highest cursor among everyone except the signed in user, which is all a
     * direct conversation needs to decide whether a message was read.
     */
    function othersReadSeq(conversationId: string): number {
        const conversation = conversations.value.find(
            (current) => current.id === conversationId,
        );

        if (conversation === undefined) {
            return 0;
        }

        return conversation.members
            .filter((member) => member.user.id !== auth.user?.id)
            .reduce(
                (highest, member) =>
                    Math.max(
                        highest,
                        readSeqFor(conversationId, member.user.id),
                    ),
                0,
            );
    }

    function applyPresence(
        conversationId: string,
        userId: string,
        online: boolean,
        lastSeenAt: string | null = null,
    ): void {
        const conversation = conversations.value.find(
            (current) => current.id === conversationId,
        );

        if (conversation === undefined) {
            return;
        }

        const annotate = (candidate: PublicUser): void => {
            if (candidate.id !== userId) {
                return;
            }

            candidate.online = online;

            if (lastSeenAt !== null) {
                candidate.last_seen_at = lastSeenAt;
            }
        };

        if (conversation.participant !== null) {
            annotate(conversation.participant);
        }

        conversation.members.forEach((member) => annotate(member.user));
    }

    /**
     * Realtime resync is a hint to refetch over HTTP; the WebSocket never
     * replays history. Refreshing the active conversation keeps its messages,
     * while summary refreshes cover every other conversation.
     *
     * The gateway acknowledges a subscription with `conversation.subscribed`
     * and, when the cursor is behind, follows up with `sync.required`. Both
     * arrive for the same gap, so concurrent resyncs for one conversation are
     * collapsed into a single round trip.
     */
    async function syncConversation(conversationId: string): Promise<void> {
        if (resyncing.has(conversationId)) {
            return;
        }

        resyncing.add(conversationId);

        try {
            await fetchConversations();

            if (conversationId === activeConversationId.value) {
                await refreshMessages();
            }
        } finally {
            resyncing.delete(conversationId);
        }
    }

    function initialize(payload: ChatPagePayload): void {
        conversations.value = sortConversations(payload.conversations);
        activeConversationId.value = payload.activeConversation?.id ?? null;

        if (
            payload.activeConversation !== null &&
            !conversations.value.some(
                (conversation) =>
                    conversation.id === payload.activeConversation?.id,
            )
        ) {
            conversations.value = sortConversations([
                ...conversations.value,
                payload.activeConversation,
            ]);
        }

        messages.value = sortMessages(payload.activeMessages);
        hasMore.value = payload.activeHasMore;
        nextCursor.value = payload.activeNextCursor;
        replyTo.value = null;
        error.value = null;

        const latest = messages.value.at(-1);

        if (latest !== undefined) {
            noteAppliedSeq(latest.conversation_id, latest.seq);
            void markRead(latest.seq);
        }

    }

    function upsertConversation(conversation: Conversation): void {
        const index = conversations.value.findIndex(
            (current) => current.id === conversation.id,
        );

        if (index >= 0) {
            conversations.value.splice(index, 1, conversation);
        } else {
            conversations.value.push(conversation);
        }

        conversations.value = sortConversations(conversations.value);
    }

    /**
     * Copy only the keys a response actually carried. Assigning the whole
     * payload would drop `last_message` (and with it the list preview) every
     * time the server answered with a partially loaded conversation.
     */
    function assignDefined(target: object, patch: object): void {
        const writable = target as Record<string, unknown>;

        Object.entries(patch).forEach(([key, value]) => {
            if (value !== undefined) {
                writable[key] = value;
            }
        });
    }

    function mergeConversation(patch: ConversationPatch): void {
        const current = conversations.value.find(
            (conversation) => conversation.id === patch.id,
        );

        if (current === undefined) {
            // A conversation this client has never seen (for example a group a
            // moderator just added the user to) can only be summarised by the
            // server, so refetch instead of inventing a partial entry.
            void fetchConversations();

            return;
        }

        assignDefined(current, patch);
        conversations.value = sortConversations(conversations.value);
    }

    function removeConversation(conversationId: string): void {
        conversations.value = conversations.value.filter(
            (conversation) => conversation.id !== conversationId,
        );

        delete appliedSeq.value[conversationId];
        delete pins.value[conversationId];
        delete invites.value[conversationId];
        delete media.value[conversationId];
        delete mediaCursor.value[conversationId];
        delete mediaHasMore.value[conversationId];

        if (activeConversationId.value === conversationId) {
            closeConversation();
        }
    }

    /**
     * Refetch one summary without disturbing the open thread. Realtime
     * `conversation.updated` frames carry no viewer state, so the client asks
     * for its own representation.
     */
    async function refreshConversation(conversationId: string): Promise<void> {
        try {
            const response = await apiRequest<{ data: Conversation }>(
                `/api/v1/conversations/${conversationId}`,
            );

            upsertConversation(response.data);

            if (
                activeConversationId.value === conversationId &&
                response.data.e2ee_enabled
            ) {
                await ensureConversationKeys(conversationId);
            }
        } catch (requestError) {
            if (
                requestError instanceof ApiError &&
                requestError.status === 404
            ) {
                removeConversation(conversationId);

                return;
            }

            error.value = errorMessage(requestError);
        }
    }

    function touchConversation(message: ChatMessage): void {
        const conversation = conversations.value.find(
            (current) => current.id === message.conversation_id,
        );

        if (conversation === undefined) {
            return;
        }

        conversation.last_message = message;
        conversation.updated_at = message.updated_at;
        conversation.next_message_seq = Math.max(
            conversation.next_message_seq,
            message.seq,
        );

        conversations.value = sortConversations(conversations.value);
    }

    function upsertMessage(message: ChatMessage): void {
        const normalized: ChatMessage = {
            ...message,
            status: 'sent',
            error: null,
        };

        noteAppliedSeq(normalized.conversation_id, normalized.seq);

        const index = messages.value.findIndex(
            (current) =>
                current.id === message.id ||
                (current.client_message_id === message.client_message_id &&
                    current.sender.id === message.sender.id),
        );

        if (index >= 0) {
            const previous = messages.value[index];

            // An edit changes the translatable text, so any client-side copy
            // of the old translation must not be reused.
            if (previous !== undefined && previous.body !== normalized.body) {
                forgetTranslations(normalized.id);
            }

            messages.value.splice(index, 1, normalized);
        } else {
            messages.value.push(normalized);
        }

        messages.value = sortMessages(messages.value);
        void decryptInto(normalized);
    }

    function applyRealtimeMessage(message: ChatMessage): void {
        void decryptInto(message);
        noteAppliedSeq(message.conversation_id, message.seq);

        const conversation = conversations.value.find(
            (current) => current.id === message.conversation_id,
        );

        if (conversation === undefined) {
            void fetchConversations();

            return;
        }

        const previousSeq = conversation.last_message?.seq ?? 0;
        const isNewSeq = message.seq > previousSeq;

        if (conversation.id === activeConversationId.value) {
            upsertMessage(message);
        }

        if (
            isNewSeq &&
            message.conversation_id !== activeConversationId.value
        ) {
            conversation.unread_count += 1;
        }

        if (isNewSeq || conversation.last_message?.id === message.id) {
            conversation.last_message = message;
            conversation.next_message_seq = Math.max(
                conversation.next_message_seq,
                message.seq,
            );
            conversation.updated_at = message.updated_at;
            conversations.value = sortConversations(conversations.value);
        }

        if (message.conversation_id === activeConversationId.value) {
            const latest = messages.value.at(-1);

            if (latest !== undefined) {
                void markRead(latest.seq);
            }
        }
    }

    function replaceUrl(path: string): void {
        if (typeof window !== 'undefined') {
            window.history.replaceState({}, '', path);
        }
    }

    async function fetchConversations(): Promise<void> {
        loadingConversations.value = true;

        try {
            const response = await apiRequest<{ data: Conversation[] }>(
                '/api/v1/conversations',
            );

            conversations.value = sortConversations(response.data);
        } catch (requestError) {
            error.value = errorMessage(requestError);
        } finally {
            loadingConversations.value = false;
        }
    }

    async function selectConversation(conversationId: string): Promise<void> {
        const request = ++selectionRequest;

        activeConversationId.value = conversationId;
        messages.value = [];
        hasMore.value = false;
        nextCursor.value = null;
        replyTo.value = null;
        error.value = null;
        loadingMessages.value = true;
        replaceUrl(`/chats/${conversationId}`);

        try {
            const page = await apiRequest<MessagePage>(
                `/api/v1/conversations/${conversationId}/messages`,
            );

            if (request !== selectionRequest) {
                return;
            }

            messages.value = sortMessages(page.data);
            hasMore.value = page.meta.has_more;
            nextCursor.value = page.meta.next_cursor;

            void ensureConversationKeys(conversationId).then(() =>
                decryptList(conversationId, messages.value),
            );

            const latest = messages.value.at(-1);

            if (latest !== undefined) {
                noteAppliedSeq(conversationId, latest.seq);
                void markRead(latest.seq);
            }
        } catch (requestError) {
            if (request === selectionRequest) {
                error.value = errorMessage(requestError);
            }
        } finally {
            if (request === selectionRequest) {
                loadingMessages.value = false;
            }
        }
    }

    function closeConversation(): void {
        selectionRequest += 1;
        activeConversationId.value = null;
        messages.value = [];
        hasMore.value = false;
        nextCursor.value = null;
        replyTo.value = null;
        error.value = null;
        replaceUrl('/chats');
    }

    async function loadOlder(): Promise<void> {
        if (
            activeConversationId.value === null ||
            loadingMore.value ||
            !hasMore.value ||
            nextCursor.value === null
        ) {
            return;
        }

        loadingMore.value = true;

        try {
            const page = await apiRequest<MessagePage>(
                `/api/v1/conversations/${activeConversationId.value}/messages?before_seq=${nextCursor.value}`,
            );

            const known = new Set(
                messages.value.map((message) => messageKey(message)),
            );
            const older = page.data.filter(
                (message) => !known.has(messageKey(message)),
            );

            messages.value = sortMessages([...older, ...messages.value]);
            void decryptList(activeConversationId.value, older);
            hasMore.value = page.meta.has_more;
            nextCursor.value = page.meta.next_cursor;
        } catch (requestError) {
            error.value = errorMessage(requestError);
        } finally {
            loadingMore.value = false;
        }
    }

    async function refreshMessages(): Promise<void> {
        if (activeConversationId.value === null || refreshing.value) {
            return;
        }

        refreshing.value = true;

        try {
            const page = await apiRequest<MessagePage>(
                `/api/v1/conversations/${activeConversationId.value}/messages`,
            );

            page.data.forEach(upsertMessage);
            void decryptList(activeConversationId.value, page.data);

            if (!hasMore.value && page.meta.has_more) {
                hasMore.value = true;
                nextCursor.value = page.meta.next_cursor;
            }

            const latest = messages.value.at(-1);

            if (latest !== undefined) {
                void markRead(latest.seq);
            }
        } catch (requestError) {
            error.value = errorMessage(requestError);
        } finally {
            refreshing.value = false;
        }
    }

    async function markRead(seq: number): Promise<void> {
        const conversation = activeConversation.value;

        if (conversation === null || seq <= conversation.last_read_seq) {
            return;
        }

        conversation.last_read_seq = seq;
        conversation.unread_count = 0;

        try {
            await apiRequest<{ data: { last_read_seq: number } }>(
                `/api/v1/conversations/${conversation.id}/read`,
                {
                    method: 'POST',
                    body: { seq },
                },
            );
        } catch {
            // Read cursors are reconciled on refresh; a failed receipt is not fatal.
        }
    }

    async function sendMessage(
        body: string,
        replyToId: string | null = null,
        attachments: MessageAttachment[] = [],
    ): Promise<ChatMessage | null> {
        const conversation = activeConversation.value;
        const sender = auth.user;

        if (conversation === null || sender === null) {
            return null;
        }

        const trimmed = body.trim();

        if (trimmed === '' && attachments.length === 0) {
            return null;
        }

        const clientMessageId = uuid();
        let encryption: E2eeEncryption | null = null;

        if (conversation.e2ee_enabled && trimmed !== '') {
            try {
                encryption = await e2ee.encryptForMessage(
                    conversation.id,
                    clientMessageId,
                    trimmed,
                );
            } catch (encryptionError) {
                error.value = errorMessage(encryptionError);

                return null;
            }
        }

        const now = new Date().toISOString();
        const highestSeq = messages.value.reduce(
            (highest, message) => Math.max(highest, message.seq),
            conversation.next_message_seq,
        );

        const optimistic: ChatMessage = {
            id: `temp-${clientMessageId}`,
            conversation_id: conversation.id,
            seq: highestSeq + 1,
            client_message_id: clientMessageId,
            sender: sender as PublicUser,
            type: 'text',
            is_encrypted: conversation.e2ee_enabled,
            body: trimmed === '' ? null : trimmed,
            encryption,
            attachments,
            reply_to:
                messages.value.find((message) => message.id === replyToId) ??
                null,
            edited_at: null,
            deleted_at: null,
            created_at: now,
            updated_at: now,
            status: 'sending',
            error: null,
        };

        messages.value = sortMessages([...messages.value, optimistic]);
        sending.value = true;
        error.value = null;

        try {
            const message = await messageRequest(
                `/api/v1/conversations/${conversation.id}/messages`,
                {
                    method: 'POST',
                    body: {
                        client_message_id: clientMessageId,
                        body: encryption !== null ? null : trimmed,
                        encryption: encryption ?? undefined,
                        attachment_ids: attachments.map(
                            (attachment) => attachment.id,
                        ),
                        reply_to_message_id: replyToId,
                    },
                },
            );

            upsertMessage(message);
            touchConversation(message);

            return message;
        } catch (requestError) {
            const failed = messages.value.find(
                (message) => message.client_message_id === clientMessageId,
            );

            if (failed !== undefined) {
                failed.status = 'failed';
                failed.error = errorMessage(requestError);
            }

            error.value = errorMessage(requestError);

            return null;
        } finally {
            sending.value = false;
        }
    }

    async function retryMessage(message: ChatMessage): Promise<void> {
        const attachmentIds = message.attachments.map(
            (attachment) => attachment.id,
        );

        if (
            message.status !== 'failed' ||
            (message.body === null && attachmentIds.length === 0)
        ) {
            return;
        }

        message.status = 'sending';
        message.error = null;

        try {
            const conversation = conversations.value.find(
                (current) => current.id === message.conversation_id,
            );
            let encryption = message.encryption ?? null;

            if (
                conversation?.e2ee_enabled === true &&
                message.body !== null &&
                message.body.trim() !== ''
            ) {
                encryption = await e2ee.encryptForMessage(
                    message.conversation_id,
                    message.client_message_id,
                    message.body,
                );
                message.encryption = encryption;
            }

            const sent = await messageRequest(
                `/api/v1/conversations/${message.conversation_id}/messages`,
                {
                    method: 'POST',
                    body: {
                        client_message_id: message.client_message_id,
                        body: encryption !== null ? null : (message.body ?? ''),
                        encryption: encryption ?? undefined,
                        attachment_ids: attachmentIds,
                        reply_to_message_id: message.reply_to?.id ?? null,
                    },
                },
            );

            upsertMessage(sent);
            touchConversation(sent);
        } catch (requestError) {
            message.status = 'failed';
            message.error = errorMessage(requestError);
        }
    }

    async function editMessage(
        message: ChatMessage,
        body: string,
    ): Promise<ChatMessage> {
        let encryption: E2eeEncryption | null = null;

        if (message.is_encrypted === true) {
            encryption = await e2ee.encryptForEdit(
                message.conversation_id,
                message.id,
                body,
            );
        }

        const updated = await messageRequest(`/api/v1/messages/${message.id}`, {
            method: 'PATCH',
            body: {
                body: encryption !== null ? null : body,
                encryption: encryption ?? undefined,
            },
        });

        upsertMessage(updated);

        if (activeConversation.value?.last_message?.id === updated.id) {
            touchConversation(updated);
        }

        return updated;
    }

    async function deleteMessage(message: ChatMessage): Promise<void> {
        const deleted = await messageRequest(`/api/v1/messages/${message.id}`, {
            method: 'DELETE',
        });

        upsertMessage(deleted);
        await fetchConversations();
    }

    async function openDirect(username: string): Promise<Conversation> {
        const response = await apiRequest<{ data: Conversation }>(
            '/api/v1/conversations/direct',
            {
                method: 'POST',
                body: { username },
            },
        );

        upsertConversation(response.data);
        await selectConversation(response.data.id);

        return response.data;
    }

    function canManageGroup(
        conversation: Conversation | null = activeConversation.value,
    ): boolean {
        return (
            conversation?.type === 'group' &&
            roleRank(conversation.viewer_role) >= roleRank('admin')
        );
    }

    function canAssignRoles(
        conversation: Conversation | null = activeConversation.value,
    ): boolean {
        return (
            conversation?.type === 'group' &&
            conversation.viewer_role === 'owner'
        );
    }

    function canPin(
        conversation: Conversation | null = activeConversation.value,
    ): boolean {
        if (conversation === null) {
            return false;
        }

        if (conversation.type === 'direct') {
            return true;
        }

        return roleRank(conversation.viewer_role) >= roleRank('moderator');
    }

    async function createGroup(input: CreateGroupInput): Promise<Conversation> {
        const response = await apiRequest<{ data: Conversation }>(
            '/api/v1/groups',
            {
                method: 'POST',
                body: {
                    title: input.title,
                    description: input.description ?? null,
                    member_ids: input.memberIds ?? [],
                    history_visible_to_new_members:
                        input.historyVisibleToNewMembers ?? false,
                },
            },
        );

        upsertConversation(response.data);
        await selectConversation(response.data.id);

        return response.data;
    }

    async function updateGroup(
        conversationId: string,
        input: UpdateGroupInput,
    ): Promise<void> {
        const response = await apiRequest<{ data: Conversation }>(
            `/api/v1/groups/${conversationId}`,
            {
                method: 'PATCH',
                body: {
                    title: input.title,
                    description: input.description,
                    history_visible_to_new_members:
                        input.historyVisibleToNewMembers,
                },
            },
        );

        mergeConversation(response.data);
    }

    async function addGroupMembers(
        conversationId: string,
        userIds: string[],
    ): Promise<void> {
        const response = await apiRequest<{ data: Conversation }>(
            `/api/v1/groups/${conversationId}/members`,
            {
                method: 'POST',
                body: { user_ids: userIds },
            },
        );

        mergeConversation(response.data);
    }

    async function removeGroupMember(
        conversationId: string,
        username: string,
    ): Promise<void> {
        const response = await apiRequest<{ data: Conversation }>(
            `/api/v1/groups/${conversationId}/members/${username}`,
            { method: 'DELETE' },
        );

        mergeConversation(response.data);
    }

    async function changeGroupMemberRole(
        conversationId: string,
        username: string,
        role: ConversationRole,
    ): Promise<void> {
        const response = await apiRequest<{ data: Conversation }>(
            `/api/v1/groups/${conversationId}/members/${username}/role`,
            {
                method: 'POST',
                body: { role },
            },
        );

        mergeConversation(response.data);
    }

    async function leaveGroup(conversationId: string): Promise<void> {
        await apiRequest<void>(`/api/v1/groups/${conversationId}/leave`, {
            method: 'POST',
        });

        removeConversation(conversationId);
    }

    async function setMuted(
        conversationId: string,
        isMuted: boolean,
        until: string | null = null,
    ): Promise<void> {
        const response = await apiRequest<{ data: Conversation }>(
            `/api/v1/conversations/${conversationId}/mute`,
            {
                method: 'POST',
                body: { is_muted: isMuted, until: isMuted ? until : null },
            },
        );

        mergeConversation(response.data);
    }

    async function setArchived(
        conversationId: string,
        archived: boolean,
    ): Promise<void> {
        const response = await apiRequest<{ data: Conversation }>(
            `/api/v1/conversations/${conversationId}/archive`,
            {
                method: 'POST',
                body: { archived },
            },
        );

        mergeConversation(response.data);
    }

    function pinsFor(conversationId: string | null): MessagePin[] {
        return conversationId === null
            ? []
            : (pins.value[conversationId] ?? []);
    }

    function isPinned(
        conversationId: string | null,
        messageId: string,
    ): boolean {
        return pinsFor(conversationId).some(
            (pin) => pin.message.id === messageId,
        );
    }

    async function fetchPins(conversationId: string): Promise<void> {
        loadingPins.value = true;

        try {
            const response = await apiRequest<{ data: MessagePin[] }>(
                `/api/v1/conversations/${conversationId}/pins`,
            );

            pins.value = { ...pins.value, [conversationId]: response.data };
        } catch (requestError) {
            error.value = errorMessage(requestError);
        } finally {
            loadingPins.value = false;
        }
    }

    async function pinMessage(message: ChatMessage): Promise<void> {
        const response = await apiRequest<{ data: MessagePin }>(
            `/api/v1/messages/${message.id}/pin`,
            { method: 'POST' },
        );

        const others = pinsFor(message.conversation_id).filter(
            (pin) => pin.message.id !== message.id,
        );

        pins.value = {
            ...pins.value,
            [message.conversation_id]: [response.data, ...others],
        };
    }

    async function unpinMessage(message: ChatMessage): Promise<void> {
        await apiRequest<void>(`/api/v1/messages/${message.id}/pin`, {
            method: 'DELETE',
        });

        pins.value = {
            ...pins.value,
            [message.conversation_id]: pinsFor(message.conversation_id).filter(
                (pin) => pin.message.id !== message.id,
            ),
        };
    }

    /**
     * Another member pinned something. Only refresh when this client already
     * has the list loaded, so an idle tab never fetches pins it does not show.
     */
    function invalidatePins(conversationId: string): void {
        if (pins.value[conversationId] === undefined) {
            return;
        }

        void fetchPins(conversationId);
    }

    function mediaFor(conversationId: string | null): MessageAttachment[] {
        return conversationId === null
            ? []
            : (media.value[conversationId] ?? []);
    }

    function mediaHasMoreFor(conversationId: string | null): boolean {
        return conversationId === null
            ? false
            : (mediaHasMore.value[conversationId] ?? false);
    }

    /**
     * Page through the shared media gallery. `reset` reloads from the newest
     * attachment; otherwise the request continues where the last page stopped.
     * The API cannot answer with duplicates, but a retry after a partial
     * failure can, so ids already in memory are skipped.
     */
    function translationKey(messageId: string, language: string): string {
        return `${messageId}:${language}`;
    }

    function forgetTranslations(messageId: string): void {
        const prefix = `${messageId}:`;

        for (const key of Object.keys(translations.value)) {
            if (key.startsWith(prefix)) {
                delete translations.value[key];
            }
        }
    }

    function translationFor(
        messageId: string,
        language: string | null | undefined,
    ): MessageTranslation | null {
        if (language === null || language === undefined) {
            return null;
        }

        return translations.value[translationKey(messageId, language)] ?? null;
    }

    function isTranslating(messageId: string, language: string): boolean {
        return translating.value[translationKey(messageId, language)] === true;
    }

    async function translateMessage(
        message: ChatMessage,
        language: string,
    ): Promise<MessageTranslation> {
        const key = translationKey(message.id, language);
        const cached = translations.value[key];

        if (cached !== undefined) {
            return cached;
        }

        translating.value[key] = true;

        try {
            const response = await apiRequest<{ data: MessageTranslation }>(
                `/api/v1/messages/${message.id}/translate`,
                { method: 'POST', body: { language } },
            );

            translations.value[key] = response.data;

            return response.data;
        } finally {
            delete translating.value[key];
        }
    }

    async function fetchMedia(
        conversationId: string,
        reset = false,
    ): Promise<void> {
        if (loadingMedia.value) {
            return;
        }

        loadingMedia.value = true;

        try {
            const beforeSeq = reset
                ? null
                : (mediaCursor.value[conversationId] ?? null);
            const params = new URLSearchParams({
                limit: String(MEDIA_PAGE_SIZE),
            });

            if (beforeSeq !== null) {
                params.set('before_seq', String(beforeSeq));
            }

            const page = await apiRequest<MediaPage>(
                `/api/v1/conversations/${conversationId}/media?${params.toString()}`,
            );

            const existing = reset ? [] : (media.value[conversationId] ?? []);
            const seen = new Set(existing.map((item) => item.id));
            const merged = [...existing];

            for (const item of page.data) {
                if (!seen.has(item.id)) {
                    seen.add(item.id);
                    merged.push(item);
                }
            }

            media.value = { ...media.value, [conversationId]: merged };
            mediaHasMore.value = {
                ...mediaHasMore.value,
                [conversationId]: page.meta.has_more,
            };
            mediaCursor.value = {
                ...mediaCursor.value,
                [conversationId]: page.meta.next_cursor,
            };
        } catch (requestError) {
            error.value = errorMessage(requestError);
        } finally {
            loadingMedia.value = false;
        }
    }

    async function fetchInvites(conversationId: string): Promise<void> {
        loadingInvites.value = true;

        try {
            const response = await apiRequest<{ data: ConversationInvite[] }>(
                `/api/v1/groups/${conversationId}/invites`,
            );

            invites.value = {
                ...invites.value,
                [conversationId]: response.data,
            };
        } catch (requestError) {
            error.value = errorMessage(requestError);
        } finally {
            loadingInvites.value = false;
        }
    }

    async function createInvite(
        conversationId: string,
        input: { expiresAt?: string | null; maxUses?: number | null } = {},
    ): Promise<ConversationInvite> {
        const response = await apiRequest<{ data: ConversationInvite }>(
            `/api/v1/groups/${conversationId}/invites`,
            {
                method: 'POST',
                body: {
                    expires_at: input.expiresAt ?? null,
                    max_uses: input.maxUses ?? null,
                },
            },
        );

        invites.value = {
            ...invites.value,
            [conversationId]: [
                response.data,
                ...(invites.value[conversationId] ?? []),
            ],
        };

        return response.data;
    }

    async function revokeInvite(
        conversationId: string,
        inviteId: string,
    ): Promise<void> {
        await apiRequest<void>(
            `/api/v1/groups/${conversationId}/invites/${inviteId}`,
            { method: 'DELETE' },
        );

        invites.value = {
            ...invites.value,
            [conversationId]: (invites.value[conversationId] ?? []).filter(
                (invite) => invite.id !== inviteId,
            ),
        };
    }

    function setReplyTo(message: ChatMessage | null): void {
        replyTo.value = message;
    }

    function setMessageStatus(
        message: ChatMessage,
        status: MessageStatus,
    ): void {
        message.status = status;
    }

    return {
        conversations,
        activeConversationId,
        activeConversation,
        messages,
        hasMore,
        nextCursor,
        replyTo,
        pins,
        loadingPins,
        media,
        loadingMedia,
        fetchMedia,
        mediaFor,
        mediaHasMoreFor,
        invites,
        loadingInvites,
        translateMessage,
        translationFor,
        isTranslating,
        loadingConversations,
        loadingMessages,
        loadingMore,
        refreshing,
        sending,
        error,
        initialize,
        fetchConversations,
        refreshConversation,
        selectConversation,
        closeConversation,
        loadOlder,
        refreshMessages,
        markRead,
        activeIsEncrypted,
        enableActiveEncryption,
        refreshDecryption,
        decryptMessageForDisplay: decryptInto,
        sendMessage,
        retryMessage,
        editMessage,
        deleteMessage,
        openDirect,
        createGroup,
        updateGroup,
        addGroupMembers,
        removeGroupMember,
        changeGroupMemberRole,
        leaveGroup,
        setMuted,
        setArchived,
        fetchPins,
        pinMessage,
        unpinMessage,
        invalidatePins,
        pinsFor,
        isPinned,
        fetchInvites,
        createInvite,
        revokeInvite,
        canManageGroup,
        canAssignRoles,
        canPin,
        setReplyTo,
        setMessageStatus,
        upsertConversation,
        mergeConversation,
        removeConversation,
        upsertMessage,
        applyRealtimeMessage,
        applyReceipt,
        applyPresence,
        lastAppliedSeq,
        othersReadSeq,
        syncConversation,
        touchConversation,
    };
});
