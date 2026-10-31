import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    EventDeduplicator,
    isRetryableCloseCode,
    normalizeGatewayUrl,
    reconnectDelay,
    RealtimeClient,
} from './realtime';

type FakeEnvelope = {
    version?: number;
    event_id?: string;
    type: string;
    occurred_at?: string;
    data?: unknown;
};

class FakeWebSocket {
    public static readonly CONNECTING = 0;
    public static readonly OPEN = 1;
    public static readonly CLOSING = 2;
    public static readonly CLOSED = 3;

    public static instances: FakeWebSocket[] = [];

    public readyState = FakeWebSocket.CONNECTING;

    public sent: string[] = [];

    public closed = false;

    public onopen: ((event: Event) => void) | null = null;

    public onmessage: ((event: { data: string }) => void) | null = null;

    public onerror: ((event: Event) => void) | null = null;

    public onclose: ((event: { code: number; reason: string }) => void) | null =
        null;

    public constructor(public readonly url: string) {
        FakeWebSocket.instances.push(this);
    }

    public open(): void {
        this.readyState = FakeWebSocket.OPEN;
        this.onopen?.({} as Event);
    }

    public emit(envelope: FakeEnvelope): void {
        this.onmessage?.({ data: JSON.stringify(envelope) });
    }

    public send(data: string): void {
        this.sent.push(data);
    }

    public close(code = 1000, reason = ''): void {
        this.closed = true;
        this.readyState = FakeWebSocket.CLOSED;
        this.onclose?.({ code, reason });
    }

    public frames(): { type: string; data?: Record<string, unknown> }[] {
        return this.sent.map(
            (frame) =>
                JSON.parse(frame) as {
                    type: string;
                    data?: Record<string, unknown>;
                },
        );
    }
}

function readyEnvelope(
    expiresAt?: string,
    maxSubscriptions = 250,
): FakeEnvelope {
    return {
        version: 1,
        event_id: `ready-${Math.random()}`,
        type: 'connection.ready',
        occurred_at: new Date().toISOString(),
        data: {
            user_id: 'user-1',
            instance_id: 'gateway-test',
            version: 1,
            heartbeat_seconds: 25,
            max_subscriptions: maxSubscriptions,
            max_frame_bytes: 65_536,
            connection_token_expires_at: expiresAt,
        },
    };
}

afterEach(() => {
    FakeWebSocket.instances = [];
    vi.useRealTimers();
});

describe('EventDeduplicator', () => {
    it('accepts each event id once', () => {
        const dedupe = new EventDeduplicator();

        expect(dedupe.add('event-1')).toBe(true);
        expect(dedupe.add('event-1')).toBe(false);
        expect(dedupe.add('event-2')).toBe(true);
    });

    it('rejects empty ids and evicts the oldest tracked ids', () => {
        const dedupe = new EventDeduplicator(2);

        expect(dedupe.add('')).toBe(false);
        expect(dedupe.add('event-1')).toBe(true);
        expect(dedupe.add('event-2')).toBe(true);
        expect(dedupe.add('event-3')).toBe(true);
        expect(dedupe.add('event-1')).toBe(true);
    });
});

describe('reconnectDelay', () => {
    it('grows exponentially and stays within the jittered bounds', () => {
        expect(reconnectDelay(1, 500, 30000, () => 0)).toBe(250);
        expect(reconnectDelay(2, 500, 30000, () => 0)).toBe(500);
        expect(reconnectDelay(3, 500, 30000, () => 0)).toBe(1000);
        expect(reconnectDelay(8, 500, 30000, () => 0.999)).toBe(30000);
    });
});

describe('normalizeGatewayUrl', () => {
    it('appends the websocket path when only the origin is configured', () => {
        expect(normalizeGatewayUrl('ws://localhost:8081')).toBe(
            'ws://localhost:8081/ws',
        );
        expect(normalizeGatewayUrl('wss://chat.example.com/')).toBe(
            'wss://chat.example.com/ws',
        );
    });

    it('keeps an explicit path and query string', () => {
        expect(normalizeGatewayUrl('wss://chat.example.com/ws')).toBe(
            'wss://chat.example.com/ws',
        );
        expect(normalizeGatewayUrl('wss://chat.example.com/ws?region=eu')).toBe(
            'wss://chat.example.com/ws?region=eu',
        );
        expect(normalizeGatewayUrl('wss://chat.example.com/realtime')).toBe(
            'wss://chat.example.com/realtime',
        );
    });

    it('ignores blank and unparseable values', () => {
        expect(normalizeGatewayUrl('   ')).toBe('');
        expect(normalizeGatewayUrl('not a url')).toBe('not a url');
    });
});

describe('isRetryableCloseCode', () => {
    it('treats graceful and restarting closes as retryable', () => {
        expect(isRetryableCloseCode(1000)).toBe(true);
        expect(isRetryableCloseCode(1001)).toBe(true);
        expect(isRetryableCloseCode(1012)).toBe(true);
        expect(isRetryableCloseCode(1013)).toBe(true);
        expect(isRetryableCloseCode(1008)).toBe(false);
        expect(isRetryableCloseCode(1006)).toBe(false);
    });
});

describe('RealtimeClient', () => {
    const WebSocketImpl = FakeWebSocket as unknown as typeof WebSocket;

    async function connectClient(
        overrides: Partial<
            ConstructorParameters<typeof RealtimeClient>[0]
        > = {},
        expiresAt?: string,
    ) {
        const client = new RealtimeClient({
            onEvent: () => {},
            WebSocketImpl,
            getToken: async () => ({
                token: 'token-1',
                expires_at: '',
                url: 'ws://localhost:8081',
            }),
            heartbeatMs: 600_000,
            ...overrides,
        });

        client.start();
        await vi.advanceTimersByTimeAsync(0);

        const socket = FakeWebSocket.instances.at(-1);

        if (socket === undefined) {
            throw new Error('the client did not open a socket');
        }

        socket.open();
        socket.emit(readyEnvelope(expiresAt));

        return { client, socket };
    }

    it('connects to the /ws path advertised by the gateway origin', async () => {
        vi.useFakeTimers();

        const { client, socket } = await connectClient();

        expect(socket.url).toBe('ws://localhost:8081/ws');

        client.stop();
    });

    it('replaces a stale socket when a retry is requested', async () => {
        vi.useFakeTimers();

        const { client, socket } = await connectClient();

        client.retryNow();
        await vi.advanceTimersByTimeAsync(0);

        expect(socket.closed).toBe(true);
        expect(FakeWebSocket.instances).toHaveLength(2);
        expect(FakeWebSocket.instances.at(-1)?.url).toBe(
            'ws://localhost:8081/ws',
        );

        client.stop();
    });

    it('renews the gateway token before it expires', async () => {
        vi.useFakeTimers();

        let issued = 0;

        const { client, socket } = await connectClient(
            {
                getToken: async () => {
                    issued += 1;

                    return {
                        token: `token-${issued}`,
                        expires_at: '',
                        url: 'ws://localhost:8081',
                    };
                },
            },
            new Date(Date.now() + 60_000).toISOString(),
        );

        expect(issued).toBe(1);

        await vi.advanceTimersByTimeAsync(48_000);

        expect(issued).toBe(2);

        const authenticate = socket
            .frames()
            .filter((frame) => frame.type === 'connection.authenticate')
            .at(-1);

        expect(authenticate?.data?.token).toBe('token-2');

        client.stop();
    });

    it('caps subscriptions at the limit advertised by the gateway', async () => {
        vi.useFakeTimers();

        const client = new RealtimeClient({
            onEvent: () => {},
            WebSocketImpl,
            getToken: async () => ({
                token: 'token-1',
                expires_at: '',
                url: 'ws://localhost:8081',
            }),
            heartbeatMs: 600_000,
        });

        client.start();
        await vi.advanceTimersByTimeAsync(0);

        const socket = FakeWebSocket.instances.at(-1);

        if (socket === undefined) {
            throw new Error('the client did not open a socket');
        }

        socket.open();
        socket.emit(readyEnvelope(undefined, 250));

        client.setSubscriptions(
            Array.from({ length: 400 }, (_, index) => `conversation-${index}`),
        );

        const subscribed = socket
            .frames()
            .filter((frame) => frame.type === 'conversation.subscribe')
            .map((frame) => frame.data?.conversation_id);

        expect(subscribed).toHaveLength(250);

        client.stop();
    });

    it('warns once when the conversation list exceeds the subscription cap', async () => {
        vi.useFakeTimers();

        const errors: string[] = [];

        const client = new RealtimeClient({
            onEvent: () => {},
            onError: (message) => errors.push(message),
            WebSocketImpl,
            getToken: async () => ({
                token: 'token-1',
                expires_at: '',
                url: 'ws://localhost:8081',
            }),
            heartbeatMs: 600_000,
        });

        client.start();
        await vi.advanceTimersByTimeAsync(0);

        const socket = FakeWebSocket.instances.at(-1);

        if (socket === undefined) {
            throw new Error('the client did not open a socket');
        }

        socket.open();
        socket.emit(readyEnvelope(undefined, 2));

        client.setSubscriptions([
            'conversation-1',
            'conversation-2',
            'conversation-3',
        ]);
        client.setSubscriptions([
            'conversation-1',
            'conversation-2',
            'conversation-3',
        ]);

        expect(errors).toEqual([
            'You have more conversations than the realtime subscription limit; the extra conversations will not update live.',
        ]);

        client.stop();
    });

    it('sends the last applied sequence when subscribing', async () => {
        vi.useFakeTimers();

        const { client, socket } = await connectClient({
            getLastSeq: (conversationId) =>
                conversationId === 'conversation-1' ? 42 : null,
        });

        client.setSubscriptions(['conversation-1', 'conversation-2']);

        const frames = socket
            .frames()
            .filter((frame) => frame.type === 'conversation.subscribe');

        expect(frames).toEqual([
            {
                type: 'conversation.subscribe',
                data: { conversation_id: 'conversation-1', last_seq: 42 },
            },
            {
                type: 'conversation.subscribe',
                data: { conversation_id: 'conversation-2' },
            },
        ]);

        client.stop();
    });

    it('surfaces the close reason reported by the gateway', async () => {
        vi.useFakeTimers();

        const errors: string[] = [];
        const statuses: string[] = [];

        const { client, socket } = await connectClient({
            onError: (message) => errors.push(message),
            onStatus: (status) => statuses.push(status),
        });

        socket.close(1008, 'connection limit reached');

        expect(errors).toEqual([
            'Realtime connection closed: connection limit reached.',
        ]);
        expect(statuses).toEqual(['connecting', 'connected', 'reconnecting']);

        client.stop();
    });
});
