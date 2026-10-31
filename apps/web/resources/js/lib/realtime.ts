import { apiRequest } from '@/lib/http';
import type {
    ConnectionReadyData,
    GatewayCredentials,
    RealtimeEnvelope,
    RealtimeStatus,
} from '@/types/realtime';

export class EventDeduplicator {
    private readonly seen = new Set<string>();

    private readonly order: string[] = [];

    public constructor(private readonly limit = 1000) {}

    public add(eventId: string): boolean {
        if (eventId === '' || this.seen.has(eventId)) {
            return false;
        }

        this.seen.add(eventId);
        this.order.push(eventId);

        if (this.order.length > this.limit) {
            const oldest = this.order.shift();

            if (oldest !== undefined) {
                this.seen.delete(oldest);
            }
        }

        return true;
    }

    public clear(): void {
        this.seen.clear();
        this.order.length = 0;
    }
}

export function reconnectDelay(
    attempt: number,
    baseDelayMs = 500,
    maxDelayMs = 30_000,
    random: () => number = Math.random,
): number {
    const exponent = Math.max(0, attempt - 1);
    const raw = Math.min(maxDelayMs, baseDelayMs * 2 ** exponent);
    const jitter = 0.5 + random();

    return Math.min(maxDelayMs, Math.round(raw * jitter));
}

type RealtimeClientOptions = {
    url?: string | null;
    tokenEndpoint?: string;
    onEvent: (event: RealtimeEnvelope) => void;
    onStatus?: (status: RealtimeStatus) => void;
    onError?: (message: string) => void;
    baseDelayMs?: number;
    maxDelayMs?: number;
    heartbeatMs?: number;
    pongTimeoutMs?: number;
    authTimeoutMs?: number;
    dedupeLimit?: number;
    random?: () => number;
    getToken?: () => Promise<GatewayCredentials>;
    /**
     * Highest conversation sequence the client has already applied. It is sent
     * as `last_seq` when subscribing so the gateway can answer with
     * `sync.required` when events were missed while offline.
     */
    getLastSeq?: (conversationId: string) => number | null;
    WebSocketImpl?: typeof WebSocket;
};

type ClientMessage = {
    type: string;
    id?: string;
    data?: unknown;
};

async function fetchGatewayToken(
    endpoint: string,
): Promise<GatewayCredentials> {
    const response = await apiRequest<{ data: GatewayCredentials }>(endpoint);

    return response.data;
}

function defaultGatewayUrl(): string | null {
    const configured = import.meta.env.VITE_GATEWAY_URL;

    return typeof configured === 'string' && configured !== ''
        ? configured
        : null;
}

const GATEWAY_PATH = '/ws';
const DEFAULT_MAX_SUBSCRIPTIONS = 250;

/**
 * The gateway serves its WebSocket upgrade on `/ws`. Deployments configure the
 * origin (`ws://localhost:8081`), so the path is appended unless it is already
 * present.
 */
export function normalizeGatewayUrl(url: string): string {
    const trimmed = url.trim();

    if (trimmed === '') {
        return trimmed;
    }

    try {
        const parsed = new URL(trimmed);

        if (parsed.pathname === '' || parsed.pathname === '/') {
            parsed.pathname = GATEWAY_PATH;
        }

        return parsed.toString();
    } catch {
        return trimmed;
    }
}

/**
 * Close codes that mean "the gateway is going away, reconnect immediately".
 */
export function isRetryableCloseCode(code: number): boolean {
    return code === 1000 || code === 1001 || code === 1012 || code === 1013;
}

export class RealtimeClient {
    private socket: WebSocket | null = null;

    private started = false;

    private ready = false;

    private connecting = false;

    private attempts = 0;

    private nonce = 0;

    private readonly subscriptions = new Set<string>();

    private maxSubscriptions: number = DEFAULT_MAX_SUBSCRIPTIONS;

    private truncationWarned = false;

    private readonly dedupe: EventDeduplicator;

    private reconnectTimer: ReturnType<typeof setTimeout> | null = null;

    private heartbeatTimer: ReturnType<typeof setInterval> | null = null;

    private pongTimer: ReturnType<typeof setTimeout> | null = null;

    private authTimer: ReturnType<typeof setTimeout> | null = null;

    private renewalTimer: ReturnType<typeof setTimeout> | null = null;

    private readonly socketImpl: typeof WebSocket;

    private readonly random: () => number;

    public constructor(private readonly options: RealtimeClientOptions) {
        this.dedupe = new EventDeduplicator(options.dedupeLimit ?? 1000);
        this.socketImpl = options.WebSocketImpl ?? WebSocket;
        this.random = options.random ?? Math.random;
    }

    public start(): void {
        if (this.started) {
            return;
        }

        this.started = true;
        void this.connect();
    }

    public stop(): void {
        this.started = false;
        this.ready = false;
        this.connecting = false;
        this.attempts = 0;
        this.clearReconnectTimer();
        this.clearConnectionTimers();

        const socket = this.socket;
        this.socket = null;
        this.nonce += 1;

        if (socket !== null) {
            try {
                socket.close(1000, 'client stopped');
            } catch {
                // The socket may already be closing.
            }
        }

        this.emitStatus('disconnected');
    }

    public retryNow(): void {
        if (!this.started) {
            this.start();

            return;
        }

        this.clearReconnectTimer();
        this.attempts = 0;

        // A half-open or stalled socket must be replaced, otherwise the retry is
        // silently ignored while `connect()` refuses to run with a live socket.
        const socket = this.socket;
        this.socket = null;
        this.ready = false;
        this.connecting = false;
        this.nonce += 1;
        this.clearConnectionTimers();

        if (socket !== null) {
            try {
                socket.close(1000, 'manual retry');
            } catch {
                // The socket may already be closed.
            }
        }

        void this.connect();
    }

    public setSubscriptions(conversationIds: Iterable<string>): void {
        const desired = new Set<string>();
        let truncated = false;

        for (const conversationId of conversationIds) {
            if (desired.size >= this.maxSubscriptions) {
                truncated = true;

                break;
            }

            desired.add(conversationId);
        }

        // A user with more conversations than the gateway allows should know that
        // the surplus ones only update when the page is reloaded.
        if (truncated && !this.truncationWarned) {
            this.truncationWarned = true;
            this.options.onError?.(
                'You have more conversations than the realtime subscription limit; the extra conversations will not update live.',
            );
        } else if (!truncated) {
            this.truncationWarned = false;
        }

        for (const conversationId of this.subscriptions) {
            if (!desired.has(conversationId)) {
                this.subscriptions.delete(conversationId);
                this.sendSubscription(
                    'conversation.unsubscribe',
                    conversationId,
                );
            }
        }

        for (const conversationId of desired) {
            if (!this.subscriptions.has(conversationId)) {
                this.subscriptions.add(conversationId);
                this.sendSubscription('conversation.subscribe', conversationId);
            }
        }
    }

    public subscribe(conversationId: string): void {
        if (this.subscriptions.has(conversationId)) {
            return;
        }

        if (this.subscriptions.size >= this.maxSubscriptions) {
            this.options.onError?.(
                'The realtime subscription limit was reached; some conversations will not update live.',
            );

            return;
        }

        this.subscriptions.add(conversationId);
        this.sendSubscription('conversation.subscribe', conversationId);
    }

    public unsubscribe(conversationId: string): void {
        if (!this.subscriptions.delete(conversationId)) {
            return;
        }

        this.sendSubscription('conversation.unsubscribe', conversationId);
    }

    public sendTyping(conversationId: string, isTyping: boolean): void {
        this.send({
            type: isTyping ? 'typing.start' : 'typing.stop',
            data: { conversation_id: conversationId },
        });
    }

    private async connect(): Promise<void> {
        if (!this.started || this.connecting || this.socket !== null) {
            return;
        }

        this.connecting = true;
        this.emitStatus(this.attempts === 0 ? 'connecting' : 'reconnecting');

        try {
            const credentials = await this.loadCredentials();
            const url = normalizeGatewayUrl(
                credentials.url ??
                    this.options.url ??
                    defaultGatewayUrl() ??
                    '',
            );

            if (url === '') {
                throw new Error('The gateway URL is not configured.');
            }

            if (!this.started) {
                this.connecting = false;

                return;
            }

            const socket = new this.socketImpl(url);
            const nonce = ++this.nonce;

            this.socket = socket;
            this.connecting = false;

            socket.onopen = () => this.handleOpen(nonce, credentials);
            socket.onmessage = (event) => {
                void this.handleMessage(nonce, event);
            };
            socket.onerror = () => {
                this.options.onError?.('The realtime connection failed.');
            };
            socket.onclose = (event) => this.handleClose(nonce, event);
        } catch (error) {
            this.connecting = false;
            this.options.onError?.(
                error instanceof Error
                    ? error.message
                    : 'Unable to connect to the realtime gateway.',
            );
            this.scheduleReconnect();
        }
    }

    private async loadCredentials(): Promise<GatewayCredentials> {
        if (this.options.getToken !== undefined) {
            return this.options.getToken();
        }

        return fetchGatewayToken(
            this.options.tokenEndpoint ?? '/api/v1/gateway/token',
        );
    }

    private handleOpen(nonce: number, credentials: GatewayCredentials): void {
        if (!this.isCurrent(nonce)) {
            return;
        }

        this.send({
            type: 'connection.authenticate',
            data: { token: credentials.token },
        });

        this.clearAuthTimer();
        this.authTimer = setTimeout(() => {
            if (!this.ready) {
                this.options.onError?.('Realtime authentication timed out.');
                this.closeSocket(1008, 'authentication timeout');
            }
        }, this.options.authTimeoutMs ?? 6000);
    }

    private async handleMessage(
        nonce: number,
        event: MessageEvent,
    ): Promise<void> {
        if (!this.isCurrent(nonce)) {
            return;
        }

        let payload: string;

        if (typeof event.data === 'string') {
            payload = event.data;
        } else if (event.data instanceof Blob) {
            payload = await event.data.text();
        } else {
            return;
        }

        let envelope: RealtimeEnvelope;

        try {
            envelope = JSON.parse(payload) as RealtimeEnvelope;
        } catch {
            this.options.onError?.('Received an invalid realtime event.');

            return;
        }

        if (envelope.version !== 1) {
            return;
        }

        if (envelope.type === 'connection.ready') {
            const ready =
                (envelope.data as Partial<ConnectionReadyData> | null) ?? null;

            if (
                typeof ready?.max_subscriptions === 'number' &&
                ready.max_subscriptions > 0
            ) {
                this.maxSubscriptions = ready.max_subscriptions;
            }

            this.ready = true;
            this.attempts = 0;
            this.clearAuthTimer();
            this.scheduleRenewal(ready?.connection_token_expires_at);
            this.startHeartbeat();
            this.restoreSubscriptions();
            this.emitStatus('connected');

            return;
        }

        if (envelope.type === 'connection.error') {
            const data = envelope.data as { message?: string } | null;

            this.options.onError?.(data?.message ?? 'Realtime request failed.');

            if (!this.ready) {
                this.closeSocket(1008, 'authentication failed');
            }

            return;
        }

        if (envelope.type === 'pong') {
            this.clearPongTimer();

            return;
        }

        this.clearPongTimer();

        if (!this.dedupe.add(envelope.event_id)) {
            return;
        }

        this.options.onEvent(envelope);
    }

    private handleClose(nonce: number, event: CloseEvent): void {
        if (!this.isCurrent(nonce)) {
            return;
        }

        this.socket = null;
        this.ready = false;
        this.connecting = false;
        this.clearConnectionTimers();

        if (!this.started) {
            this.emitStatus('disconnected');

            return;
        }

        const reason =
            typeof event.reason === 'string' ? event.reason.trim() : '';

        if (reason !== '') {
            this.options.onError?.(`Realtime connection closed: ${reason}.`);
        }

        if (isRetryableCloseCode(event.code)) {
            this.emitStatus('disconnected');
        }

        // A "going away" frame means the gateway is restarting, so retry right
        // away instead of backing off from a fresh attempt counter.
        if (event.code === 1001) {
            this.attempts = 0;
        }

        this.scheduleReconnect();
    }

    private scheduleReconnect(): void {
        if (!this.started || this.reconnectTimer !== null) {
            return;
        }

        this.attempts += 1;
        this.emitStatus('reconnecting');

        const delay = reconnectDelay(
            this.attempts,
            this.options.baseDelayMs ?? 500,
            this.options.maxDelayMs ?? 30_000,
            this.random,
        );

        this.reconnectTimer = setTimeout(() => {
            this.reconnectTimer = null;

            if (this.started) {
                void this.connect();
            }
        }, delay);
    }

    private restoreSubscriptions(): void {
        for (const conversationId of this.subscriptions) {
            this.sendSubscription('conversation.subscribe', conversationId);
        }
    }

    /**
     * Gateway tokens are short lived. Renew well before the expiry the gateway
     * advertises, otherwise the socket is closed mid-session.
     */
    private scheduleRenewal(expiresAt: string | undefined): void {
        this.clearRenewalTimer();

        if (expiresAt === undefined) {
            return;
        }

        const expires = Date.parse(expiresAt);

        if (Number.isNaN(expires)) {
            return;
        }

        const ttl = expires - Date.now();

        if (ttl <= 0) {
            return;
        }

        const lead = Math.min(60_000, Math.max(5_000, Math.floor(ttl * 0.2)));
        const delay = Math.max(1_000, ttl - lead);

        this.renewalTimer = setTimeout(() => {
            this.renewalTimer = null;
            void this.renewToken();
        }, delay);
    }

    private async renewToken(): Promise<void> {
        if (!this.started || !this.ready) {
            return;
        }

        try {
            const credentials = await this.loadCredentials();

            if (!this.started || !this.ready) {
                return;
            }

            // The gateway answers with a fresh `connection.ready` envelope, which
            // schedules the next renewal.
            this.send({
                type: 'connection.authenticate',
                data: { token: credentials.token },
            });
        } catch {
            this.options.onError?.(
                'Unable to renew the realtime connection token.',
            );
            // The gateway closes the socket once the current token expires, and
            // the close handler reconnects with a new token.
        }
    }

    private sendSubscription(type: string, conversationId: string): void {
        if (!this.ready) {
            return;
        }

        const data: { conversation_id: string; last_seq?: number } = {
            conversation_id: conversationId,
        };

        // Sending the cursor on resume lets the gateway decide whether the
        // subscription is current or the client must refetch over HTTP.
        if (type === 'conversation.subscribe') {
            const lastSeq = this.options.getLastSeq?.(conversationId) ?? null;

            if (lastSeq !== null && lastSeq > 0) {
                data.last_seq = lastSeq;
            }
        }

        this.send({ type, data });
    }

    private send(message: ClientMessage): void {
        if (
            this.socket === null ||
            this.socket.readyState !== this.socketImpl.OPEN
        ) {
            return;
        }

        try {
            this.socket.send(JSON.stringify(message));
        } catch {
            this.options.onError?.('Unable to send a realtime message.');
        }
    }

    private startHeartbeat(): void {
        this.clearHeartbeatTimer();

        const heartbeatMs = this.options.heartbeatMs ?? 25_000;

        this.heartbeatTimer = setInterval(() => {
            if (!this.ready) {
                return;
            }

            this.send({ type: 'ping' });
            this.clearPongTimer();

            this.pongTimer = setTimeout(() => {
                this.options.onError?.('Realtime heartbeat timed out.');
                this.closeSocket(4000, 'heartbeat timeout');
            }, this.options.pongTimeoutMs ?? 10_000);
        }, heartbeatMs);
    }

    private closeSocket(code: number, reason: string): void {
        const socket = this.socket;

        if (socket === null) {
            return;
        }

        this.ready = false;
        this.clearConnectionTimers();

        try {
            socket.close(code, reason);
        } catch {
            // The socket may already be closed.
        }
    }

    private isCurrent(nonce: number): boolean {
        return this.nonce === nonce;
    }

    private clearConnectionTimers(): void {
        this.clearAuthTimer();
        this.clearHeartbeatTimer();
        this.clearPongTimer();
        this.clearRenewalTimer();
    }

    private clearReconnectTimer(): void {
        if (this.reconnectTimer !== null) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }

    private clearAuthTimer(): void {
        if (this.authTimer !== null) {
            clearTimeout(this.authTimer);
            this.authTimer = null;
        }
    }

    private clearHeartbeatTimer(): void {
        if (this.heartbeatTimer !== null) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    }

    private clearPongTimer(): void {
        if (this.pongTimer !== null) {
            clearTimeout(this.pongTimer);
            this.pongTimer = null;
        }
    }

    private clearRenewalTimer(): void {
        if (this.renewalTimer !== null) {
            clearTimeout(this.renewalTimer);
            this.renewalTimer = null;
        }
    }

    private emitStatus(status: RealtimeStatus): void {
        this.options.onStatus?.(status);
    }
}
