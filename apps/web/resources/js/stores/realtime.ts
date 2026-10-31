import { defineStore } from 'pinia';
import { ref } from 'vue';
import { messagePreview } from '@/lib/format';
import { showNotification } from '@/lib/notifications';
import { RealtimeClient } from '@/lib/realtime';
import { useAuthStore } from '@/stores/auth';
import { useChatStore } from '@/stores/chat';
import { useConnectionStore } from '@/stores/connection';
import type { ChatMessage, Conversation } from '@/types';
import type {
    PresenceEventData,
    ReceiptEventData,
    RealtimeEnvelope,
    RealtimeStatus,
    SubscribedEventData,
    SyncRequiredEventData,
    TypingEventData,
} from '@/types/realtime';

type TypingEntry = {
    userId: string;
    expiresAt: number;
};

const TYPING_TTL_MS = 5000;

export const useRealtimeStore = defineStore('realtime', () => {
    const auth = useAuthStore();
    const chat = useChatStore();
    const connection = useConnectionStore();

    const typing = ref<Record<string, Record<string, TypingEntry>>>({});

    let client: RealtimeClient | null = null;
    let everConnected = false;

    const desiredSubscriptions = new Set<string>();
    const typingStopTimers = new Map<string, ReturnType<typeof setTimeout>>();
    const typingExpiryTimers = new Map<string, ReturnType<typeof setTimeout>>();

    function start(): void {
        if (client !== null) {
            return;
        }

        client = new RealtimeClient({
            onStatus: handleStatus,
            onError: (message) => connection.setError(message),
            onEvent: applyEvent,
            tokenEndpoint: '/api/v1/gateway/token',
            getLastSeq: (conversationId) => chat.lastAppliedSeq(conversationId),
        });

        client.setSubscriptions(desiredSubscriptions);
        client.start();
    }

    function stop(): void {
        client?.stop();
        client = null;
        everConnected = false;

        typingStopTimers.forEach((timer) => clearTimeout(timer));
        typingStopTimers.clear();

        typingExpiryTimers.forEach((timer) => clearTimeout(timer));
        typingExpiryTimers.clear();

        typing.value = {};
        connection.setStatus('disconnected');
        connection.resetAttempts();
    }

    function retry(): void {
        if (client === null) {
            start();

            return;
        }

        client.retryNow();
    }

    function syncSubscriptions(conversationIds: Iterable<string>): void {
        const next = new Set(conversationIds);

        desiredSubscriptions.clear();
        next.forEach((id) => desiredSubscriptions.add(id));

        client?.setSubscriptions(desiredSubscriptions);
    }

    function signalTyping(conversationId: string): void {
        if (client === null) {
            return;
        }

        client.sendTyping(conversationId, true);

        const existing = typingStopTimers.get(conversationId);

        if (existing !== undefined) {
            clearTimeout(existing);
        }

        typingStopTimers.set(
            conversationId,
            setTimeout(() => {
                typingStopTimers.delete(conversationId);
                client?.sendTyping(conversationId, false);
            }, 2500),
        );
    }

    function stopTyping(conversationId: string): void {
        const existing = typingStopTimers.get(conversationId);

        if (existing !== undefined) {
            clearTimeout(existing);
            typingStopTimers.delete(conversationId);
        }

        client?.sendTyping(conversationId, false);
    }

    function typingUsers(conversationId: string): string[] {
        const entries = typing.value[conversationId] ?? {};

        return Object.values(entries)
            .filter(
                (entry) =>
                    entry.userId !== auth.user?.id &&
                    entry.expiresAt > Date.now(),
            )
            .map((entry) => entry.userId);
    }

    function handleStatus(status: RealtimeStatus): void {
        connection.setStatus(status);

        if (status === 'reconnecting') {
            connection.incrementAttempts();
        }

        if (status === 'connected') {
            connection.resetAttempts();

            if (everConnected) {
                void reconcile();
            }

            everConnected = true;
        }
    }

    function applyEvent(envelope: RealtimeEnvelope): void {
        switch (envelope.type) {
            case 'message.created':
                applyCreatedMessage(envelope.data as ChatMessage);
                break;
            case 'message.updated':
            case 'message.deleted':
                chat.applyRealtimeMessage(envelope.data as ChatMessage);
                break;
            case 'conversation.updated':
                chat.upsertConversation(envelope.data as Conversation);
                break;
            case 'typing.updated':
                applyTyping(envelope.data as TypingEventData);
                break;
            case 'receipt.updated':
                applyReceipt(envelope.data as ReceiptEventData);
                break;
            case 'presence.updated':
                applyPresence(envelope.data as PresenceEventData);
                break;
            case 'conversation.subscribed':
                applySubscribed(envelope.data as SubscribedEventData);
                break;
            case 'sync.required':
                applySyncRequired(envelope);
                break;
        }
    }

    /**
     * The gateway acknowledges every subscription with the sequence it is at.
     * When the server is ahead of the cursor we sent, events were missed while
     * the socket was down and the page has to refetch over HTTP.
     */
    function applySubscribed(data: SubscribedEventData): void {
        const lastSeq = data.last_seq ?? null;

        if (lastSeq === null || data.latest_seq <= lastSeq) {
            return;
        }

        void chat.syncConversation(data.conversation_id);
    }

    function applySyncRequired(envelope: RealtimeEnvelope): void {
        const data = (envelope.data as SyncRequiredEventData | null) ?? null;
        const conversationId =
            data?.conversation_id ?? envelope.conversation_id ?? null;

        if (conversationId === null) {
            void reconcile();

            return;
        }

        void chat.syncConversation(conversationId);
    }

    function applyReceipt(data: ReceiptEventData): void {
        chat.applyReceipt(data.conversation_id, data.user_id, data.seq);
    }

    function applyCreatedMessage(message: ChatMessage): void {
        chat.applyRealtimeMessage(message);
        notify(message);
    }

    /**
     * Desktop notifications only fire for messages the user cannot already see:
     * someone else's message, in a conversation that is not focused right now.
     */
    function notify(message: ChatMessage): void {
        if (message.sender.id === auth.user?.id) {
            return;
        }

        const viewingThisConversation =
            message.conversation_id === chat.activeConversationId;

        if (
            viewingThisConversation &&
            (typeof document === 'undefined' || !document.hidden)
        ) {
            return;
        }

        showNotification(
            message.sender.name,
            messagePreview(message),
            message.conversation_id,
        );
    }

    function applyPresence(data: PresenceEventData): void {
        chat.applyPresence(
            data.conversation_id,
            data.user_id,
            data.online,
            data.last_seen_at !== undefined && data.last_seen_at !== ''
                ? data.last_seen_at
                : null,
        );
    }

    function applyTyping(data: TypingEventData): void {
        const users = typing.value[data.conversation_id] ?? {};
        const key = `${data.conversation_id}:${data.user_id}`;
        const existing = typingExpiryTimers.get(key);

        if (existing !== undefined) {
            clearTimeout(existing);
            typingExpiryTimers.delete(key);
        }

        if (!data.is_typing) {
            delete users[data.user_id];
            typing.value = { ...typing.value, [data.conversation_id]: users };

            return;
        }

        users[data.user_id] = {
            userId: data.user_id,
            expiresAt: new Date(data.expires_at).getTime(),
        };
        typing.value = { ...typing.value, [data.conversation_id]: users };

        typingExpiryTimers.set(
            key,
            setTimeout(() => {
                typingExpiryTimers.delete(key);
                delete users[data.user_id];
                typing.value = {
                    ...typing.value,
                    [data.conversation_id]: users,
                };
            }, TYPING_TTL_MS),
        );
    }

    async function reconcile(): Promise<void> {
        await chat.fetchConversations();

        if (chat.activeConversationId !== null) {
            await chat.refreshMessages();
        }
    }

    return {
        typing,
        start,
        stop,
        retry,
        syncSubscriptions,
        signalTyping,
        stopTyping,
        typingUsers,
    };
});
