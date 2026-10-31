# WebSocket protocol

The Go gateway (`apps/gateway`) owns live delivery. Laravel still owns every authorization decision and every permanent write, so nothing in this protocol may create or mutate message history.

## Connection lifecycle

1. The browser requests a short lived credential from Laravel: `GET /api/v1/gateway/token`.
   The response contains `token`, `expires_at`, and the gateway origin (`url`).
2. The client opens `GET <gateway origin>/ws` (the origin is enough; the client appends `/ws`).
   Origins outside `ALLOWED_ORIGINS` are rejected during the upgrade.
3. Within `GATEWAY_AUTH_TIMEOUT` the client sends `connection.authenticate`.
   Any other frame first closes the socket with `1008 authentication required`.
4. The gateway validates the token signature, expiry, and account status, then answers with `connection.ready`.
5. The gateway closes the socket with `1008 token expired` when the credential that authenticated it expires.
   The client renews by sending `connection.authenticate` again with a fresh token on the same socket.

`connection.ready` advertises the server limits so the client does not have to guess:

```json
{
  "version": 1,
  "event_id": "…",
  "type": "connection.ready",
  "occurred_at": "2026-10-31T00:00:00Z",
  "data": {
    "user_id": "…",
    "instance_id": "gateway-1",
    "version": 1,
    "heartbeat_seconds": 30,
    "max_subscriptions": 250,
    "max_frame_bytes": 65536,
    "connection_token_expires_at": "2026-10-31T00:05:00Z"
  }
}
```

## Envelope

Every server frame uses one envelope shape:

```json
{
  "version": 1,
  "event_id": "event-uuid",
  "type": "message.created",
  "conversation_id": "conversation-uuid",
  "seq": 105,
  "occurred_at": "2026-10-31T00:30:00Z",
  "data": {}
}
```

- `event_id` is stable and is the deduplication key on the client.
- `seq` orders messages inside one conversation and is present for message and receipt events.
- `conversation_id` is present for every conversation scoped event.

## Client to gateway

| Type | Payload | Notes |
|---|---|---|
| `connection.authenticate` | `{ "token": "…" }` | Required before anything else; also used for renewal |
| `conversation.subscribe` | `{ "conversation_id": "…", "last_seq": 105 }` | Authorized server side against membership; `last_seq` is the highest sequence the client already rendered and may be omitted |
| `conversation.unsubscribe` | `{ "conversation_id": "…" }` | Idempotent |
| `typing.start` | `{ "conversation_id": "…" }` | Requires an existing subscription |
| `typing.stop` | `{ "conversation_id": "…" }` | Requires an existing subscription |
| `receipt.delivered` | `{ "conversation_id": "…", "message_id": "…", "seq": 105 }` | Requires an existing subscription |
| `receipt.read` | `{ "conversation_id": "…", "message_id": "…", "seq": 105 }` | Requires an existing subscription |
| `ping` | `{ "id": "optional-request-id" }` | Answered with a `pong` envelope |

## Gateway to client

`connection.ready`, `connection.error`, `pong`, `message.created`, `message.updated`, `message.deleted`, `message.reaction.updated`, `receipt.updated`, `typing.updated`, `presence.updated`, `conversation.updated`, `conversation.subscribed`, `sync.required`.

Message events (`message.created`, `message.updated`, `message.deleted`) are never produced by the gateway itself. Laravel commits them to the `outbox_events` table in the same transaction as the message, and the worker publishes them to
`chat.events.conversation.<conversation-id>`; the gateway forwards the envelope unchanged. `data` therefore has exactly the shape of the REST message resource, and the event is delivered to **every** subscriber of the conversation, including the sender's other devices.

Typing and receipts use the ephemeral `chat.typing.*` subjects. Delivery is at-least-once: deduplicate by `event_id` and collapse messages by `id`/`client_message_id`.

Realtime state events carry small payloads, never message history:

| Type | `data` | Notes |
|---|---|---|
| `receipt.updated` | `{ "conversation_id", "user_id", "seq", "read" }` | Per member cursor; clients apply the highest `seq` per member and never move it backwards |
| `presence.updated` | `{ "conversation_id", "user_id", "online", "last_seen_at"? }` | `last_seen_at` is sent when a member goes offline; the UI formats the relative wording itself |
| `typing.updated` | `{ "conversation_id", "user_id", "is_typing", "expires_at" }` | Expires client side so a dropped `typing.stop` cannot leave a stuck indicator |
| `conversation.subscribed` | `{ "conversation_id", "latest_seq", "last_seq"? }` | Acknowledgement for every accepted `conversation.subscribe` |
| `sync.required` | `{ "conversation_id", "last_seq", "latest_seq", "reason" }` | The subscription cursor is behind `latest_seq`; history must be refetched over HTTP |

## Cursor and resync

The gateway never replays history. `latestSeq` is read from the latest committed message of the conversation, so the client can resume from its own cursor:

1. The client remembers the highest `seq` it rendered per conversation and sends it as `last_seq` on every `conversation.subscribe`, including the replays after a reconnect.
2. The gateway replies with `conversation.subscribed` carrying `latest_seq`, then echoes the client cursor as `last_seq` when it was supplied.
3. When `latest_seq` is greater than the client cursor the gateway immediately follows with `sync.required`. The client refetches the conversation summaries and, for the visible conversation, the newest message page over HTTP.
4. Both frames describe the same gap, so the client collapses concurrent resyncs for one conversation into a single refetch.
5. Presence is re-seeded right after the acknowledgement, so a reconnect does not leave stale online dots behind.

A subscription sent without `last_seq` is treated as "unknown cursor": the gateway acknowledges it and never asks for a resync.

## Presence troubleshooting

Presence is derived from the leases the gateway writes to Redis and is only published when a member transitions between online and offline. When an online dot never appears:

1. `curl -s localhost:8081/metrics | grep gateway_presence` must expose `gateway_presence_transitions_total`. A missing metric means the running gateway binary predates the presence code, so restart it (`make gateway-dev`) after pulling gateway changes.
2. `redis-cli --scan --pattern 'presence:user:*'` lists one key per online user while a socket is open; the key disappears with that user's last connection, and `presence:lastseen:*` keeps the timestamp for the offline label.
3. The REST snapshot has to read the same Redis database and prefix as the gateway: `PRESENCE_ENABLED`, `PRESENCE_REDIS_CONNECTION`, `REDIS_PRESENCE_DB` and `REDIS_PRESENCE_PREFIX` in the web `.env` must match the gateway's `REDIS_URL`.
4. `php artisan tinker` → `app(App\Services\PresenceService::class)->snapshot([$userId])` reporting `online: false` while step 2 shows a key means Laravel is pointed at the wrong database or prefix.


Errors are sent as an envelope with `type: "connection.error"` and a `data.code`:

| Code | Meaning |
|---|---|
| `invalid_message` | The frame was not valid JSON |
| `invalid_payload` | A required field was missing or malformed |
| `unsupported_type` | The message type is not part of the protocol |
| `subscription_limit` | `max_subscriptions` was reached |
| `subscription_failed` | The membership lookup failed; retry later |
| `forbidden` | The user is not a member of the conversation |
| `not_subscribed` | Typing or receipt sent before subscribing |

## Validation rules

- Conversation IDs and receipt `message_id` values must be UUIDv4 strings.
- Receipts must carry `seq > 0`.
- Typing and receipt frames are rejected with `not_subscribed` unless the connection already subscribed.
- Broker events for `chat.events.conversation.*` and `chat.typing.*` must carry the same `conversation_id` in the envelope as in the NATS subject. Mismatched events are dropped and counted in `gateway_events_dropped_total{reason="conversation_mismatch"}` so one mis-published event can never fan out into another conversation.
- `typing.updated` payloads are additionally checked against the subject.

## Limits

| Limit | Setting | Behavior when exceeded |
|---|---|---|
| Frame size | `GATEWAY_MAX_FRAME_BYTES` | Read limit closes the socket instead of buffering |
| Subscriptions per connection | `GATEWAY_MAX_SUBSCRIPTIONS` | `connection.error` with `subscription_limit` |
| Global connections | `GATEWAY_MAX_CONNECTIONS` | Upgrade rejected with `503` |
| Connections per user | `GATEWAY_MAX_CONNECTIONS_PER_USER` | Close `1008 connection limit reached` |
| Inbound frames per second | `GATEWAY_INBOUND_RATE_PER_SECOND` / `GATEWAY_INBOUND_BURST` | Excess frames are dropped and counted; the token bucket refills continuously |
| Upgrades per IP | 4 per second, burst 10 | Upgrade rejected with `429` |
| Outbound queue | `GATEWAY_OUTBOUND_BUFFER` | Slow consumer closed with `1013` |

The per user cap is checked against the registry before the connection joins it, so one noisy client cannot starve every other user on the instance. Renewal frames refresh the expiry timer instead of creating a second registry entry.

## Close codes

| Code | Reason | Client behavior |
|---|---|---|
| `1000` / `1001` | Normal closure, gateway draining | Reconnect; backoff attempts reset on `1001` |
| `1008` | Policy violation: invalid credentials, expired token, connection limit, authentication required | Reconnect only after fetching a fresh token |
| `1009` | Message too big | Do not retry the same frame |
| `1012` / `1013` | Service restart, try again later (slow consumer) | Reconnect with backoff |
| `1006` | Transport failure | Reconnect with exponential backoff and jitter |

`isRetryableCloseCode` in `apps/web/resources/js/lib/realtime.ts` encodes this table. Close reasons are surfaced through the connection state so the UI can explain why realtime dropped.

## Client responsibilities

- Reconnect with exponential backoff plus jitter, then replay `conversation.subscribe` for every conversation still on screen.
- Re-authenticate on the same socket before `connection_token_expires_at`, and fall back to a full reconnect if renewal fails.
- Deduplicate by `event_id`, and reconcile live events against HTTP responses by `seq`.
- Track the highest rendered `seq` per conversation, send it as `last_seq`, and refetch over HTTP when `sync.required` or an ahead `conversation.subscribed` arrives.
- Never exceed `max_subscriptions`; when more conversations are needed, page them instead of subscribing to everything.

## Metrics

`gateway_inbound_throttled_total`, `gateway_token_renewals_total`, `gateway_slow_consumers_total`, `gateway_events_dropped_total{reason}`, `gateway_websocket_auth_failures_total{reason}`, `gateway_websocket_upgrade_rejections_total{reason}`, and `gateway_subscription_errors_total{reason}` make the failure modes above observable.
