# Architecture

The system is split into three deployable applications that share PostgreSQL, Redis, and NATS JetStream.

```text
Vue 3 + Inertia + Tailwind + Pinia
              |
        HTTPS / WSS
              |
          Nginx / TLS
          /         \
 Laravel (apps/web)  Go gateway (apps/gateway)
          |                    |
          |                    +--- Redis (presence, routing, rate limits)
          |
     PostgreSQL (source of truth)
          |
   outbox_events
          |
     Go worker (apps/worker) — outbox dispatcher
          |
     NATS (core pub/sub; JetStream is the planned durable upgrade)
          |
   gateway fan-out / notifications
```

## Services

| Service | Path | Port | Responsibility |
|---|---|---|---|
| Web application | `apps/web` | 8000 | Authentication, authorization, profiles, conversations, message persistence, history, Inertia pages, `/api/v1` |
| Gateway | `apps/gateway` | 8081 | WebSocket lifecycle, connection registry, subscriptions, live delivery, health, metrics |
| Worker | `apps/worker` | 8082 | Outbox dispatch, event publishing, fan-out, notifications, cleanup |
| PostgreSQL | — | 5432 | Permanent data and transactional outbox |
| Redis | — | 6379 | Sessions, cache, queues, presence leases, routing, rate-limit counters |
| NATS JetStream | — | 4222 | Retained event delivery between the worker and the gateways |
| MinIO | — | 9000 | Media and attachment storage |

## Rules

1. Laravel owns business logic and permanent data.
2. The gateway never bypasses Laravel authorization or persistence.
3. The worker consumes durable events and must be idempotent.
4. PostgreSQL is the source of truth; Redis is ephemeral.
5. Message creation happens over HTTPS in Laravel, not over the WebSocket.
6. Every conversation operation checks membership.
7. Message history uses cursor pagination ordered by per-conversation `seq`.
8. Durable delivery is at-least-once; clients deduplicate by `event_id`.

The full WebSocket event and subscription contract lives in [`websocket-protocol.md`](websocket-protocol.md); the rationale for short lived renewable credentials, per-user caps, and inbound rate limiting is recorded in [`adr/0004-websocket-connection-hardening.md`](adr/0004-websocket-connection-hardening.md).

## Realtime event pipeline

A message never reaches the socket directly from the HTTP request. The path is:

1. `MessageService` writes the message **and** an `outbox_events` row in one
   PostgreSQL transaction (`App\Services\RealtimeEventPublisher`). The row stores
   the full gateway envelope: `version`, `event_id`, `type`, `conversation_id`,
   `seq`, `occurred_at`, and `data` (the same payload the REST API returns).
2. The worker claims due rows with `FOR UPDATE SKIP LOCKED`, so several workers
   can drain the table without publishing the same event twice.
3. The worker publishes the envelope to
   `EVENT_SUBJECT_PREFIX + <aggregate_type> + "." + <aggregate_id>`. Message
   events use the conversation aggregate, e.g.
   `chat.events.conversation.<conversation-id>`, which the gateway fans out to
   every subscriber of that conversation.
4. The gateway validates the envelope against the subject and delivers it. Anyone
   connected to another gateway instance receives it through its own NATS
   subscription.
5. Delivery is **at-least-once**. Clients deduplicate by message `id`/`seq` (the
   dashboard already collapses duplicates by `client_message_id`).

A publish failure leaves the row unpublished, increments `attempts`, records
`last_error`, and schedules a retry with exponential backoff
(1s → 30s). After `WORKER_OUTBOX_MAX_ATTEMPTS` the row stops retrying and stays
in the table as a dead letter for inspection.

Because the event row is committed with the message, a crash between the API
write and the broker publish cannot lose the message: the worker picks the row
up on its next poll.

### Outbox settings

| Variable | Default | Purpose |
|---|---|---|
| `EVENT_SUBJECT_PREFIX` | `chat.events.` | Must match `GATEWAY_EVENT_SUBJECT_PREFIX` |
| `WORKER_OUTBOX_POLL_MS` | 250 | Dispatch interval; this is the realtime latency floor |
| `WORKER_OUTBOX_BATCH_SIZE` | 100 | Rows claimed per poll |
| `WORKER_OUTBOX_MAX_ATTEMPTS` | 10 | Attempts before a row is treated as a dead letter |

The gateways subscribe to `GATEWAY_EVENT_SUBJECT_PREFIX + ">"`, so both services
must agree on the prefix. Production must use authenticated NATS credentials
and an appropriate replicated storage policy.


## Gateway connection limits

| Variable | Default | Purpose |
|---|---|---|
| `GATEWAY_MAX_CONNECTIONS` | 10000 | Sockets per gateway instance |
| `GATEWAY_MAX_CONNECTIONS_PER_USER` | 10 | Sockets per account; must not exceed the instance limit |
| `GATEWAY_INBOUND_RATE_PER_SECOND` | 30 | Inbound frames per second per connection |
| `GATEWAY_INBOUND_BURST` | 60 | Inbound burst allowance per connection |
| `GATEWAY_MAX_SUBSCRIPTIONS` | 250 | Conversation subscriptions per connection |
| `GATEWAY_MAX_FRAME_BYTES` | 65536 | Maximum inbound frame size |
| `GATEWAY_OUTBOUND_BUFFER` | 128 | Outbound queue before a slow consumer is closed |

`GATEWAY_TOKEN_TTL` in `apps/web` bounds how long a socket can live on a single credential; the gateway closes the socket at token expiry and expects an in-place `connection.authenticate` renewal.

## Health endpoints

| Endpoint | Purpose |
|---|---|
| `GET :8081/healthz` | Gateway liveness |
| `GET :8081/readyz` | Gateway readiness (PostgreSQL + Redis) |
| `GET :8081/metrics` | Prometheus metrics |
| `GET :8082/healthz` | Worker liveness |
| `GET :8082/readyz` | Worker readiness (PostgreSQL + Redis + NATS) |
| `GET :8082/metrics` | Prometheus metrics |

## Configuration

Each service reads configuration from environment variables. The Go services also load a local `.env` file when present. See the `.env.example` file in each application.
