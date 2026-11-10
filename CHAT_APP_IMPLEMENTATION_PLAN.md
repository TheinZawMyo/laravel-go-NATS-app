# Chat Web Application — Architecture & Implementation Plan

**Project type:** Messenger / Telegram-style public chat application  
**Initial scale:** 1,000–10,000 registered users  
**Primary stack:** Vue 3, Laravel, Go, Inertia.js, Tailwind CSS  
**Architecture:** Laravel Modular Monolith + Go WebSocket Gateway + Go Worker

---

## Table of Contents

- [Phase 0 — Architecture and Project Setup](#phase-0--architecture-and-project-setup)
- [Phase 1 — Foundation and Authentication](#phase-1--foundation-and-authentication)
- [Phase 2 — Core Messaging](#phase-2--core-messaging)
- [Phase 3 — Go WebSocket Gateway](#phase-3--go-websocket-gateway)
- [Phase 4 — Reliable Events and Background Workers](#phase-4--reliable-events-and-background-workers)
- [Phase 5 — Groups, Contacts, and Social Features](#phase-5--groups-contacts-and-social-features)
- [Phase 6 — Media and File Sharing](#phase-6--media-and-file-sharing)
- [Phase 7 — Receipts, Presence, and Notifications](#phase-7--receipts-presence-and-notifications)
- [Phase 8 — Search, Moderation, and Privacy](#phase-8--search-moderation-and-privacy)
- [Phase 9 — Testing, Security, and Production Readiness](#phase-9--testing-security-and-production-readiness)
- [Phase 10 — Advanced Features and Scaling](#phase-10--advanced-features-and-scaling)
- [Database Schema](#database-schema)
- [API Design](#api-design)
- [WebSocket Protocol](#websocket-protocol)
- [Deployment Architecture](#deployment-architecture)
- [Acceptance Criteria](#acceptance-criteria)

---

## Architecture Principles

1. **Laravel owns business logic and permanent data.** Message creation, authorization, conversation membership, edits, deletions, and moderation are validated by Laravel.
2. **Go Gateway owns live WebSocket connections and event delivery.** It must not bypass Laravel's authorization and persistence rules.
3. **Go Worker handles asynchronous event processing.** Use it for outbox dispatching, fan-out, notifications, and other independent jobs.
4. **PostgreSQL is the source of truth.** Redis is for cache, presence, ephemeral state, and coordination—not permanent message history.
5. **Use durable event delivery.** Redis Pub/Sub alone is not a durable queue. Use a PostgreSQL transactional outbox plus one durable broker, such as RabbitMQ or NATS JetStream.
6. **Design for at-least-once delivery.** Make consumers idempotent and deduplicate events. Do not promise exactly-once network delivery.
7. **Use cursor pagination.** Avoid offset pagination for long message histories.
8. **Build incrementally.** Do not introduce Kubernetes or multiple overlapping realtime systems before there is a demonstrated need.

## System Architecture

```text
Vue 3 + Inertia.js + Tailwind CSS + Pinia
             |
        HTTPS / WSS
             |
          Nginx / TLS
          /         \
         /           \
 Laravel Application  Go WebSocket Gateway
         |                    |
         |                    +---- Redis (presence/routing)
         |
 PostgreSQL
         |
 Transactional Outbox
         |
 Go Worker / Durable Broker
         |
 Gateway event delivery / notifications / fan-out

Object storage (S3-compatible / MinIO) stores media files.
```

### Service Responsibilities

| Service | Responsibilities |
|---|---|
| Laravel | Authentication, authorization, profiles, contacts, conversations, message creation, message history, groups, moderation, API, Inertia pages |
| Go Gateway | WebSocket lifecycle, authentication, connection registry, subscriptions, live event delivery, acknowledgments, reconnect handling |
| Go Worker | Outbox processing, event publishing, notifications, fan-out, media jobs, cleanup jobs |
| PostgreSQL | Users, conversations, memberships, messages, receipts, notifications, outbox events |
| Redis | Presence leases, ephemeral typing indicators, cache, rate-limit counters, gateway routing |
| Durable broker | Retained/retriable event delivery between workers and gateways |
| S3-compatible storage | Images, videos, audio, voice messages, documents, thumbnails |

**Inertia.js note:** Inertia handles Laravel-driven page rendering and navigation. It does not replace WebSockets. Use HTTP/Inertia for pages and mutations, and a native WebSocket client for Go Gateway events.

---

# Phase 0 — Architecture and Project Setup

## Goals

- Confirm service boundaries and shared contracts.
- Create the repository and development environment.
- Establish coding standards and environment configuration.

## Tasks

- [ ] Create a monorepo.
- [ ] Create Laravel + Vue 3 + Inertia.js application.
- [ ] Configure TypeScript and Tailwind CSS.
- [ ] Configure shadcn-vue or another accessible UI component system.
- [ ] Configure PostgreSQL.
- [ ] Configure Redis.
- [ ] Choose one durable broker: RabbitMQ or NATS JetStream.
- [ ] Create Go Gateway and Go Worker modules.
- [ ] Define API conventions and WebSocket event contracts.
- [ ] Create `.env.example` files without secrets.
- [ ] Configure Docker Compose for local development.
- [ ] Add code formatting, linting, and basic CI checks.
- [ ] Write initial architecture decision records in `docs/`.

## Deliverables

- Working local development environment.
- Laravel, Vue, and Go services can start independently.
- Shared API and event contract documentation.
- Initial CI pipeline.

## Completion Criteria

- [ ] A developer can start the system using documented commands.
- [ ] Secrets are not committed to Git.
- [ ] Health endpoints are available for the Go services.
- [ ] PostgreSQL and Redis connections work from the intended services.

---

# Phase 1 — Foundation and Authentication

## Goals

Build the application shell, authentication, user profiles, and authorization foundation.

## Frontend

- [ ] Build responsive application layout.
- [ ] Create login and registration pages.
- [ ] Create password reset pages.
- [ ] Create profile and account settings pages.
- [ ] Create reusable dialogs, dropdowns, menus, buttons, and form components.
- [ ] Set up Pinia stores and TypeScript types.
- [ ] Add form validation and loading/error states.

## Backend

- [ ] Implement registration and login.
- [ ] Implement logout and session invalidation.
- [ ] Implement email verification and password reset.
- [ ] Implement profile update and avatar metadata.
- [ ] Implement username uniqueness and user lookup.
- [ ] Implement Laravel Policies and Form Requests.
- [ ] Configure CSRF protection and secure session cookies.
- [ ] Add account status and session/device management foundations.

## Deliverables

- Authentication flow.
- User profiles.
- Reusable UI foundation.
- Authorization conventions.

## Completion Criteria

- [ ] Users can register, log in, log out, and reset passwords.
- [ ] Protected pages require authentication.
- [ ] Unauthorized profile updates are rejected.
- [ ] Browser session cookies use secure production settings.

---

# Phase 2 — Core Messaging

## Goals

Implement persistent direct messaging and message history before realtime delivery.

## Database and Backend

- [ ] Create conversations and conversation-membership migrations.
- [ ] Create messages table and required indexes.
- [ ] Implement direct conversation creation.
- [ ] Prevent duplicate direct conversations with a canonical participant-pair key.
- [ ] Implement message creation inside a database transaction.
- [ ] Generate a per-conversation sequence number transactionally.
- [ ] Implement idempotency using `sender_id + client_message_id`.
- [ ] Implement cursor-based message history.
- [ ] Implement reply-to-message relationships.
- [ ] Implement message edit and delete rules.
- [ ] Add authorization checks for every conversation and message operation.
- [ ] Add unread counts and latest-message previews.

## Frontend

- [ ] Build conversation list.
- [ ] Build message timeline.
- [ ] Build message composer.
- [ ] Add loading, empty, error, and retry states.
- [ ] Add optimistic UI with reconciliation against server responses.
- [ ] Add cursor-based history loading.
- [ ] Add virtualized rendering for long conversations.

## Deliverables

- Functional non-realtime direct chat.
- Durable messages and history.
- Idempotent message creation.

## Completion Criteria

- [ ] Users can create and reopen direct conversations.
- [ ] Messages persist across page reloads.
- [ ] Retrying a request with the same idempotency key does not create a duplicate.
- [ ] Users cannot read messages from conversations they are not authorized to access.
- [ ] History pagination remains stable as new messages arrive.

---

# Phase 3 — Go WebSocket Gateway

## Goals

Implement persistent connections and authenticated realtime delivery.

## Gateway

- [x] Choose and configure a maintained Go WebSocket library (`github.com/coder/websocket`).
- [x] Implement `/healthz` and `/readyz`.
- [x] Implement connection authentication.
- [x] Validate allowed WebSocket origins.
- [x] Configure ping/pong or heartbeat handling.
- [x] Implement bounded inbound and outbound buffers.
- [x] Implement write deadlines and maximum frame/message sizes.
- [x] Implement graceful shutdown and connection draining.
- [x] Implement connection registry by user and gateway instance.
- [x] Implement authorized conversation subscriptions.
- [x] Implement event envelope versioning.
- [x] Implement slow-consumer handling.
- [x] Add structured logs and basic metrics.
- [x] Close sockets when the connection token expires and support in-place re-authentication.
- [x] Enforce a per-user connection cap (`GATEWAY_MAX_CONNECTIONS_PER_USER`).
- [x] Enforce a per-connection inbound frame rate limit (`GATEWAY_INBOUND_RATE_PER_SECOND`, `GATEWAY_INBOUND_BURST`).
- [x] Cross-check broker subjects against the envelope conversation, and validate receipts (`seq > 0`, UUID `message_id`).
- [x] Drain connections with close code `1001` on shutdown.

## Frontend

- [x] Create a reusable WebSocket composable/service.
- [x] Implement connection state.
- [x] Implement reconnect with exponential backoff and jitter.
- [x] Restore authorized subscriptions after reconnect.
- [x] Deduplicate events by stable event/message ID.
- [x] Reconcile live events with HTTP responses.
- [x] Renew the gateway token before `connection_token_expires_at` and honour close codes.
- [x] Normalize the configured gateway origin to the `/ws` endpoint.
- [x] Respect `max_subscriptions` from `connection.ready`.

## Deliverables

- Authenticated WebSocket Gateway.
- Live connection status in the UI.
- Versioned event protocol.
- [Protocol contract](docs/websocket-protocol.md) and [hardening ADR](docs/adr/0004-websocket-connection-hardening.md).

## Completion Criteria

- [x] Invalid credentials and origins are rejected.
- [x] Gateway restarts do not permanently prevent clients from reconnecting.
- [x] Slow clients cannot cause unbounded memory growth.
- [x] Connection state and failures are observable.

---

# Phase 4 — Reliable Events and Background Workers

## Goals

Ensure committed messages can be delivered after retries, worker restarts, and temporary outages.

## Backend and Infrastructure

- [x] Create an `outbox_events` table. (`2026_10_29_000018_create_outbox_events_table.php`)
- [x] Insert the message and outbox event in the same database transaction. (`App\Services\RealtimeEventPublisher`, called from `MessageService`)
- [x] Implement a Go outbox dispatcher. (`apps/worker/internal/outbox`)
- [ ] Configure one durable broker. (core NATS today; JetStream retention still to do)
- [x] Implement retry and backoff policies. (exponential 1s → 30s, capped by `WORKER_OUTBOX_MAX_ATTEMPTS`)
- [ ] Implement idempotent event consumers.
- [x] Track attempts, last errors, and publication timestamps.
- [x] Implement dead-letter or failed-event handling. (exhausted rows keep `last_error` and stop retrying)
- [x] Implement gateway event fan-out. (`chat.events.conversation.*` → subscribers)
- [ ] Implement client resynchronization from the last confirmed sequence. (clients re-fetch history on reconnect; cursor-based resync still to do)

## Deliverables

- Durable event pipeline.
- Retry-safe workers.
- Recovery path for missed events.

## Completion Criteria

- [x] A committed message is not lost merely because a worker is temporarily down.
- [x] Duplicate broker deliveries do not create duplicate messages. (clients collapse by `id`/`client_message_id`; the API is idempotent per `client_message_id`)
- [x] Failed events can be inspected and retried. (rows keep `attempts`, `last_error`, `available_at`; clear the error to retry)
- [x] Clients can recover missed messages through the Laravel API. (history is cursor paginated by `seq`, and clients refetch after reconnect)
- [ ] Outbox backlog and oldest event age are monitored.

---

# Phase 5 — Groups, Contacts, and Social Features

## Goals

Add the social features expected in a Messenger/Telegram-style application.

## Features

- [ ] Search users by username.
- [ ] Send, accept, reject, and cancel contact requests.
- [ ] Add and remove contacts.
- [ ] Block and unblock users.
- [ ] Create group conversations.
- [ ] Add and remove group members.
- [ ] Implement owner, admin, moderator, and member permissions as needed.
- [ ] Implement group title, description, and avatar.
- [ ] Implement group invite links with expiration and revocation.
- [ ] Implement pin and unpin messages.
- [ ] Implement mute and archive conversation options.
- [ ] Define history visibility for members who leave and rejoin.
- [ ] Broadcast membership and conversation metadata updates.

## Completion Criteria

- [ ] Only authorized users can change group membership or settings.
- [ ] Block rules are applied consistently to new conversations and messages.
- [ ] Removed members lose access to future private events.
- [ ] Duplicate contact requests and duplicate memberships are prevented.

---

# Phase 6 — Media and File Sharing

## Goals

Support secure sharing of images, videos, audio, voice messages, and documents.

## Backend

- [x] Configure S3-compatible object storage or MinIO.
- [x] Implement presigned upload requests.
- [x] Validate file size, MIME type, and file signature.
- [x] Generate random storage keys; do not trust user-provided paths.
- [x] Store attachment metadata in `message_attachments`.
- [x] Implement upload completion validation.
- [x] Implement media processing and thumbnail jobs.
- [x] Add malware scanning where appropriate.
- [x] Enforce download authorization.
- [x] Implement abandoned-upload cleanup.
- [x] Define retention and deletion behavior for attachments.

## Frontend

- [x] Implement drag-and-drop uploads.
- [x] Show upload progress and failures.
- [x] Preview images and supported media.
- [x] Implement voice-message recording if required.
- [x] Display file type, size, and download status.
- [x] Add a shared media/files gallery.

## Completion Criteria

- [x] Unauthorized users cannot access private attachments.
- [x] Oversized, malformed, and disallowed files are rejected.
- [x] Media processing failures can be retried.
- [x] Files remain available independently of application container restarts.

---

# Phase 7 — Receipts, Presence, and Notifications

## Goals

Add delivery/read status, online presence, typing indicators, and notifications.

## Presence

- [ ] Track active connections with Redis leases and expiration.
- [ ] Support multiple devices and browser tabs per user.
- [ ] Mark a user offline only when no valid connections remain.
- [ ] Aggregate and debounce presence changes.
- [ ] Clean up stale presence data.

## Typing Indicators

- [ ] Implement `typing.start` and `typing.stop`.
- [ ] Apply rate limits and short TTLs.
- [ ] Keep typing state ephemeral.
- [ ] Send typing updates only to authorized members.

## Receipts

- [ ] Implement monotonic `last_read_seq` per membership.
- [ ] Validate read cursor updates.
- [ ] Add delivered/read timestamps where required.
- [ ] Choose per-message receipts only when the product needs them.
- [ ] Handle read state across multiple devices.

## Notifications

- [ ] Implement in-app notifications.
- [ ] Add browser push notifications where supported.
- [ ] Register and revoke device push tokens.
- [ ] Add mute and notification preferences.
- [ ] Retry failed notification deliveries.
- [ ] Avoid leaking private message content in notification previews.

## Completion Criteria

- [ ] Multiple tabs/devices show correct online status.
- [ ] Read cursors never move backwards.
- [ ] Typing indicators expire even if a client disconnects unexpectedly.
- [ ] Notification failures do not block message persistence.

---

# Phase 8 — Search, Moderation, and Privacy

## Goals

Improve discovery, user safety, and account/privacy controls.

## Features

- [x] Search users and conversations.
- [x] Search messages within authorized conversations.
- [x] Evaluate PostgreSQL full-text search or Meilisearch.
- [x] Implement user reporting.
- [x] Implement moderation review tools.
- [x] Implement account suspension and ban flows.
- [x] Add abuse controls for spam, repeated requests, and suspicious uploads.
- [x] Add privacy settings for last seen, profile visibility, and contact requests.
- [x] Add session/device revocation.
- [x] Define account export and deletion processes.
- [x] Define message and attachment retention policies.
- [x] Record administrative actions in audit logs.

## Completion Criteria

- [x] Search results never expose messages from unauthorized conversations.
- [x] Moderation actions are auditable.
- [x] Users can manage supported privacy settings.
- [x] Retention and deletion behavior is documented and tested.

---

# Phase 9 — Testing, Security, and Production Readiness

## Testing

- [x] Laravel feature and policy tests using Pest. (existing API, auth, moderation, privacy, and policy coverage)
- [x] Vue component tests using Vitest and Vue Test Utils. (components, stores, realtime, and formatting)
- [x] Browser end-to-end tests using Playwright. (guest landing page and protected-route smoke test; CI provisions Chromium)
- [x] Go unit and integration tests. (gateway protocol/server and worker outbox/config packages)
- [x] WebSocket protocol and reconnect tests. (protocol, token/idempotency, and history resync coverage)
- [x] Broker retry and duplicate-event tests. (outbox retry/JetStream and gateway dedupe coverage)
- [ ] Database migration and restore tests.
- [ ] Load tests for concurrent WebSocket connections and message throughput.
- [ ] Security tests for authorization bypass, XSS, CSRF, upload validation, and rate limiting. (policy/API/upload/auth coverage exists; dedicated XSS and sustained rate-limit penetration coverage remains needed)

## Operations

- [ ] Configure TLS and Nginx WebSocket proxying. (Nginx proxy example added; certificate issuance and deployment wiring are environment-specific)
- [x] Configure health checks and graceful shutdown. (Go liveness/readiness endpoints and graceful gateway draining are implemented)
- [ ] Configure structured logs and request/event correlation IDs. (Go services have structured logs; end-to-end correlation and Laravel coverage remain)
- [ ] Add Prometheus metrics and Grafana dashboards if self-hosted monitoring is chosen. (service metrics endpoints exist; dashboards and alerts remain deployment work)
- [ ] Configure error tracking.
- [ ] Configure database backups and test restoration. (backup/restore scripts and drill procedure added; execution and offsite encrypted storage remain)
- [ ] Configure Redis persistence/eviction policy appropriate to its role.
- [ ] Configure broker retention, authentication, and dead-letter handling. (JetStream retention and outbox dead-letter records are implemented; production credentials/replication policy remain)
- [x] Add deployment and rollback procedures. (see `docs/operations.md`)
- [x] Create operational runbooks. (see `docs/operations.md`)

## Completion Criteria

- [ ] No known critical authorization vulnerabilities.
- [ ] Core flows pass automated tests.
- [ ] Load tests meet agreed latency and error-rate targets.
- [ ] Services recover safely after restarts.
- [ ] Backups have been restored successfully in a test environment.
- [ ] Operators can identify failed events, queue backlog, and gateway health.

---

# Phase 10 — Advanced Features and Scaling

Only start these after the core messaging flow is reliable.

## Optional Features

- [x] Voice and video calls using WebRTC. (direct conversations use browser `RTCPeerConnection` with a Go Pion media relay, caller pending/cancel state, incoming caller identification with answer/decline, accept-confirmed duration, audio/video call layouts, mute/camera controls, and authorized transient signaling; video calling is temporarily disabled in the Chat frontend while caller-side freezes are investigated; production deployments should configure TURN servers and ICE UDP reachability)
- [ ] Screen sharing.
- [x] End-to-end encryption (E2EE) baseline. (browser-side message and attachment encryption, per-conversation key versions, per-member key wraps, and passphrase-encrypted identity backup are implemented; production hardening and multi-device support remain below)
- [ ] Disappearing messages.
- [x] Message scheduling. (text messages can be scheduled up to 30 days ahead, listed/cancelled by their sender, and dispatched through Laravel's scheduler)
- [ ] Threaded replies.
- [ ] Advanced message search.
- [ ] Multi-device session improvements.
- [x] Message translation. (users choose a default translation language in profile settings or pick any supported language per message; translations are cached per message and language, invalidated on edit, and served by a configurable provider (keyless MyMemory by default, or DeepL / self-hosted LibreTranslate); encrypted messages are excluded because the server has no plaintext)
- [ ] Bot and integration APIs.
- [ ] Public channels and large broadcast groups.
- [ ] Additional gateway instances and connection-aware routing.
- [ ] Database read replicas or partitioning if measured load requires them.

## Scaling Rules

- [ ] Scale Go Gateway horizontally only after cross-instance routing is implemented.
- [ ] Use Redis or broker-backed routing to reach connections on other gateway instances.
- [ ] Keep durable messages in PostgreSQL.
- [ ] Add database partitioning only when measurements justify it.
- [ ] Use load tests to determine connection and throughput limits.
- [ ] Track concurrent sockets, message rate, group size, payload size, and delivery latency separately.
- [ ] Do not add Kubernetes unless operational complexity and scale justify it.

## End-to-End Encryption (E2EE)

### Implemented baseline

- [x] Generate a browser identity key pair with Web Crypto (ECDH P-256); register only its public key and fingerprint with Laravel.
- [x] Generate an AES-256-GCM conversation key in the browser and publish a separately encrypted wrap for each active member using ephemeral ECDH P-256.
- [x] Encrypt message bodies in the browser with AES-GCM. Bind each ciphertext to its conversation, message/client-message ID, and key version with additional authenticated data (AAD).
- [x] Encrypt attachment bytes in the browser with a per-attachment AES-GCM key; wrap that key with the conversation key before upload.
- [x] Store ciphertext, encryption metadata, public keys, and recipient-specific wraps on the server; Laravel authorizes access and checks the active key version and message AAD.
- [x] Support passphrase-encrypted identity-key backup using PBKDF2-SHA-256 and AES-GCM, plus restore/unlock in the browser.
- [x] Require active members to have registered an identity before enabling encryption or distributing a new conversation key.
- [x] Reject plaintext messages in an encrypted conversation and disallow scheduled messages for encrypted conversations.

### Current design limits

- Identity private-key material is encrypted in IndexedDB using a non-extractable device AES-GCM key; legacy cleartext `localStorage` data is migrated and removed. This protects against reading the stored record alone, but same-origin script execution can still use the unlocked identity and remains an XSS risk. It is not a device-specific identity or multi-device model.
- Conversation encryption uses a shared AES key per version, not a ratcheting protocol. Key wrapping now derives AES-GCM keys with HKDF-SHA-256 and binds each wrap to its conversation, version, and recipient. A compromised conversation key can still expose messages protected by that version; forward secrecy and post-compromise recovery are not provided.
- Group membership changes now revoke server-side wraps for departing/returning members, mark rotation as required, and block sends/uploads/edits until a new version is published. This cannot erase keys or plaintext a former member already obtained. New members receive only the new version, subject to existing history visibility rules.
- A public-key fingerprint is displayed/stored, but there is no verified out-of-band safety-number flow or key-transparency mechanism. A compromised server could substitute public keys before users verify them.
- Backups are recoverable by anyone who knows the passphrase and obtains the backup ciphertext. The server cannot recover a lost passphrase; users must be told that losing both the device key and passphrase may make encrypted history unrecoverable.
- Search, moderation, server-side previews, notification snippets, and plaintext exports cannot inspect encrypted bodies. Keep these capabilities disabled or explicitly client-side for encrypted content; never silently fall back to plaintext.
- E2EE protects content, not all metadata. The service still sees account/conversation membership, timing, delivery state, ciphertext sizes, and attachment traffic metadata.

### Hardening and completion tasks

- [ ] Treat the current Web Crypto construction as a baseline, not an independently reviewed protocol. Commission a security review before claiming production-grade E2EE; document the threat model, supported browsers, cryptographic formats, and protocol/version migration policy.
- [ ] Replace the current local vault with a reviewed device-key lifecycle and minimize the time private JWK material remains in application memory. Prefer a non-extractable identity key where recovery requirements allow; keep backup export limited to an explicit recovery flow.
- [ ] Add a device model: device-specific identity/prekeys, device enrollment and verification, per-device key wraps, device listing/revocation, and rekeying when a device is removed. Define how existing conversations are securely joined on a new device.
- [x] Require a new sequential conversation key version after group membership changes; revoke old server-side wraps for departing/returning members, serialize membership changes with key publication, and block sends until rotation completes. Identity/device revocation and suspected-compromise flows remain.
- [ ] Bind encrypted attachment metadata to authenticated context (conversation, message/attachment identity, and key version) and validate envelope sizes/encodings. Review MIME/name/thumbnail handling so cleartext metadata is not accidentally uploaded or exposed.
- [ ] Validate backup parameters and payload bounds server-side, version the backup envelope, and provide recovery guidance. Never log private keys, passphrases, plaintext, or decrypted attachment data.
- [ ] Add tests for wrong passphrases, tampered ciphertext/AAD, cross-conversation replay, missing or stale wraps, unauthorized membership, membership changes, device loss, and ciphertext-only persistence across messages, edits, and attachments. (Crypto primitive round trips/tamper checks, key rotation gating, wrap revocation on removal/rejoin, and version sequencing are covered; broader cases remain.)
- [ ] Add user-visible encrypted/unencrypted state, key fingerprint verification, backup status, and clear recovery limitations. Warn before enabling encryption where current server-side features will stop working.
- [ ] Document encrypted-content behavior for search, moderation/reporting, notifications, exports, retention, account deletion, and backups; ensure no server feature assumes access to plaintext.
- [ ] Decide whether to adopt a vetted, interoperable protocol/library (for example, a Signal-protocol implementation) before adding multi-device support or making strong security guarantees.

### E2EE acceptance criteria

- [ ] A server/database operator cannot recover message or attachment plaintext from persisted records, logs, or backups.
- [ ] Only an authorized member/device with the correct key material can decrypt content; tampering and replay across message or conversation context fails authentication.
- [ ] Key rotation and device/member revocation follow documented rules, and new members do not gain historical access unless the product explicitly grants it.
- [ ] Lost-device and backup/passphrase recovery outcomes are understandable and tested; no silent plaintext fallback occurs.
- [ ] Security review findings are resolved or explicitly recorded before the product presents E2EE as production-ready.

---

# Database Schema

The following is the recommended logical schema. Exact column types, constraints, and migration details should be finalized during implementation.

## `users`

- `id UUID PRIMARY KEY`
- `username UNIQUE`
- `email UNIQUE`
- `password`
- `display_name`
- `avatar_key NULL`
- `bio NULL`
- `status_text NULL`
- `translation_language NULL` — default target language for message translation
- `last_seen_at NULL`
- `created_at`
- `updated_at`
- `deleted_at NULL`

## `conversations`

- `id UUID PRIMARY KEY`
- `type` — `direct` or `group`
- `title NULL`
- `avatar_key NULL`
- `created_by FK users.id`
- `direct_pair_key NULL UNIQUE` — canonical sorted pair for direct chats
- `last_message_id NULL`
- `next_message_seq BIGINT`
- `created_at`
- `updated_at`

## `conversation_members`

- `conversation_id FK conversations.id`
- `user_id FK users.id`
- `role`
- `joined_at`
- `left_at NULL`
- `last_read_seq BIGINT DEFAULT 0`
- `muted_until NULL`
- `archived_at NULL`
- Composite primary key: `(conversation_id, user_id)`

## `messages`

- `id UUID PRIMARY KEY`
- `conversation_id FK conversations.id`
- `seq BIGINT`
- `sender_id FK users.id`
- `client_message_id UUID`
- `type`
- `body NULL`
- `reply_to_message_id NULL`
- `forwarded_from_message_id NULL`
- `edited_at NULL`
- `deleted_at NULL`
- `created_at`
- Unique: `(conversation_id, seq)`
- Unique: `(sender_id, client_message_id)`

## `message_attachments`

- `id UUID PRIMARY KEY`
- `message_id FK messages.id`
- `storage_key`
- `original_name`
- `mime_type`
- `size_bytes`
- `width NULL`
- `height NULL`
- `duration_ms NULL`
- `created_at`

## `message_receipts`

- `message_id FK messages.id`
- `user_id FK users.id`
- `delivered_at NULL`
- `read_at NULL`
- Composite primary key: `(message_id, user_id)`

Use this table only where per-message receipt state is required. For large groups, a per-member `last_read_seq` is more efficient.

## Additional Tables

- `contact_requests`
- `contacts`
- `user_blocks`
- `message_reactions`
- `message_edits`
- `message_translations`
- `message_pins`
- `user_devices`
- `notifications`
- `user_reports`
- `audit_logs`
- `outbox_events`

## Important Indexes

- `messages (conversation_id, seq DESC)`
- Unique `messages (conversation_id, seq)`
- Unique `messages (sender_id, client_message_id)`
- `conversation_members (user_id, left_at, conversation_id)`
- `message_receipts (user_id, read_at, message_id)`
- `contact_requests (receiver_id, status, created_at)`
- `outbox_events (published_at, created_at)`

Add foreign keys and suitable deletion rules. Consider partial indexes for active memberships and pending outbox events. Verify indexes with real query plans.

---

# API Design

Use `/api/v1` for versioned JSON endpoints. Inertia pages can reuse the same Laravel application services.

## Authentication

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/login` | Login |
| POST | `/register` | Register |
| POST | `/logout` | Logout |
| POST | `/forgot-password` | Request password reset |
| POST | `/reset-password` | Reset password |
| GET | `/api/v1/me` | Current user |
| GET | `/api/v1/devices` | List devices |
| DELETE | `/api/v1/devices/{id}` | Revoke device |

For a browser-first Inertia app, prefer Laravel session authentication with secure cookies and CSRF protection. If API tokens are needed for other clients, use a suitable token strategy separately.

## Conversations and Messages

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/v1/conversations` | List inbox |
| POST | `/api/v1/conversations/direct` | Open/create direct chat |
| POST | `/api/v1/conversations/groups` | Create group |
| GET | `/api/v1/conversations/{id}` | Conversation details |
| GET | `/api/v1/conversations/{id}/messages?before_seq=...` | Fetch older messages |
| POST | `/api/v1/conversations/{id}/messages` | Send message |
| GET | `/api/v1/conversations/{id}/scheduled-messages` | List the current user's pending scheduled messages |
| POST | `/api/v1/conversations/{id}/scheduled-messages` | Schedule a text message (up to 30 days ahead) |
| DELETE | `/api/v1/scheduled-messages/{id}` | Cancel a pending scheduled message |
| PATCH | `/api/v1/messages/{id}` | Edit message |
| DELETE | `/api/v1/messages/{id}` | Delete message |
| POST | `/api/v1/messages/{id}/reactions` | Add reaction |
| DELETE | `/api/v1/messages/{id}/reactions/{emoji}` | Remove reaction |
| POST | `/api/v1/conversations/{id}/read` | Advance read cursor |
| POST | `/api/v1/conversations/{id}/members` | Add member |
| DELETE | `/api/v1/conversations/{id}/members/{userId}` | Remove member |
| POST | `/api/v1/uploads/presign` | Request upload URL |
| POST | `/api/v1/messages/{id}/translate` | Translate a message into a supported language |
| GET | `/api/v1/search/messages?q=...` | Search authorized messages |

Every endpoint must enforce membership and resource authorization. Validate payload sizes, rate limits, and message types on the server.

---

# WebSocket Protocol

Use a versioned event envelope. Clients must not be allowed to choose arbitrary recipients or publish internal event types.

## Client → Gateway

- `connection.authenticate`
- `conversation.subscribe`
- `conversation.unsubscribe`
- `typing.start`
- `typing.stop`
- `receipt.delivered`
- `receipt.read`
- `ping` (if an application-level heartbeat is needed)

Message creation should use Laravel HTTPS in this architecture. The gateway can handle authorized ephemeral events such as typing, but should not bypass Laravel's validation and persistence with a separate message-writing path.

## Gateway → Client

- `connection.ready`
- `message.created`
- `message.updated`
- `message.deleted`
- `message.reaction.updated`
- `receipt.updated`
- `typing.updated`
- `presence.updated`
- `conversation.updated`
- `sync.required`

## Event Envelope Example

```json
{
  "version": 1,
  "event_id": "event-uuid",
  "type": "message.created",
  "conversation_id": "conversation-uuid",
  "seq": 105,
  "occurred_at": "2026-10-29T00:30:00Z",
  "data": {
    "message_id": "message-uuid",
    "sender_id": "user-uuid",
    "body": "Hello!"
  }
}
```

- `event_id` is a stable identifier for deduplication.
- `seq` orders messages within one conversation.
- Edits to older messages must not be treated as newly created messages.
- Clients recover missing messages using the last confirmed sequence.
- Use exponential reconnect backoff with jitter.
- Reauthorize subscriptions after reconnect and membership changes.

---

# Recommended Packages

## Laravel / PHP

- `laravel/sanctum` — API tokens where needed; use sessions for browser auth.
- `spatie/laravel-permission` — optional roles and permissions.
- `laravel/horizon` — monitoring Redis-backed Laravel queues.
- `spatie/laravel-medialibrary` — optional media lifecycle support.
- `pestphp/pest` — backend tests.
- `laravel/pint` — formatting.
- `laravel/pulse` — optional application performance visibility.

Do not install every package by default. Laravel Policies may be sufficient without a permission package.

## Vue / TypeScript

- Vue 3
- Inertia.js Vue 3 adapter
- Tailwind CSS
- shadcn-vue
- Pinia
- VueUse
- `@tanstack/vue-virtual`
- Zod
- `lucide-vue-next`
- Vitest
- Vue Test Utils
- Playwright

Optional: `@tiptap/vue-3` if rich-text editing is required.

## Go

- `github.com/coder/websocket` — WebSocket transport candidate.
- `github.com/redis/go-redis/v9` — Redis client.
- `github.com/jackc/pgx/v5` — PostgreSQL driver.
- One broker client for the selected durable broker.
- `log/slog` or `go.uber.org/zap` — structured logging.
- `github.com/prometheus/client_golang` — Prometheus metrics.
- `golang.org/x/time/rate` — rate-limiting primitives.
- `go test`, `go vet`, and `golangci-lint` — quality checks.

---

# Security Requirements

## Authentication and Authorization

- Enforce session/credential expiration and revocation.
- Check membership and permissions for every private conversation operation.
- Reauthorize subscriptions after membership is revoked.
- Never trust client-provided sender IDs or recipient lists.
- Use secure, HttpOnly, SameSite cookies for browser sessions.
- Use scoped, short-lived gateway credentials or secure session introspection.
- Never put long-lived credentials in WebSocket URLs.

## Transport and WebSockets

- HTTPS/WSS only in production.
- Explicit WebSocket origin allowlist.
- Connection limits and message-size limits.
- Read/write deadlines and bounded buffers.
- Rate limits for login, messaging, contact requests, and uploads.
- Do not log credentials or private message contents.

## Input and File Safety

- Sanitize rich text and prevent stored XSS.
- Validate MIME type, file signature, and size.
- Generate random storage keys.
- Scan uploaded files where appropriate.
- Enforce download authorization.
- Use safe content-disposition headers.
- Restrict supported file types according to product requirements.

## Data Protection

- Encrypt traffic and protect backups at rest where appropriate.
- Keep database, Redis, and broker on private networks.
- Use least-privilege credentials per service.
- Keep secrets outside source control.
- Audit administrative and moderation actions.
- Define retention, export, account deletion, and backup restoration policies.

## End-to-End Encryption

E2EE is a separate design effort. It requires client-side encryption, key exchange, multi-device key management, recovery planning, and encrypted attachment handling. TLS alone is not E2EE. Server-side search, moderation, notifications, and message previews may need redesign if E2EE is implemented.

---

# Deployment Architecture

Use Docker Compose or an equivalent deployment platform initially. Kubernetes is not required merely because the system contains multiple services.

## Services

- Nginx with TLS and WebSocket upgrade support.
- Laravel web application.
- Laravel queue workers for Laravel-owned jobs.
- Go WebSocket Gateway.
- Go Worker.
- PostgreSQL.
- Redis.
- One durable event broker.
- S3-compatible object storage, managed or self-hosted.
- Monitoring and centralized logging.

## Deployment Checklist

- [ ] Use private networks for databases, Redis, and broker.
- [ ] Configure health checks and restart policies.
- [ ] Run migrations as a controlled deployment step.
- [ ] Configure Nginx WebSocket timeouts and upgrade headers.
- [ ] Configure graceful shutdown and connection draining.
- [ ] Configure backups and periodically test restores.
- [ ] Use separate least-privilege credentials for each service.
- [ ] Store media outside application container filesystems.
- [ ] Add deployment rollback procedures.
- [ ] Document operational runbooks.

## Monitoring Metrics

- Active WebSocket connections.
- Connection failures and reconnect rate.
- Message creation latency.
- Message delivery latency.
- Outbox backlog and oldest event age.
- Queue retry and failure count.
- PostgreSQL slow queries and connection usage.
- Redis memory and eviction count.
- Upload failures.
- Error rate and p95/p99 latency.

---

# Acceptance Criteria

Before production launch, verify the following:

- [ ] Two users can exchange messages in real time.
- [ ] Repeated requests with the same idempotency key do not duplicate messages.
- [ ] Offline clients can recover missed messages after reconnecting.
- [ ] Out-of-order events are reconciled using sequence numbers.
- [ ] Removed group members cannot continue receiving private group events.
- [ ] Multiple tabs and devices produce correct presence and read states.
- [ ] Slow clients cannot exhaust gateway memory.
- [ ] Broker and worker restarts do not permanently lose committed events.
- [ ] Invalid files and oversized payloads are rejected.
- [ ] Message and upload rate limits are enforced.
- [ ] Search never reveals unauthorized conversation content.
- [ ] Core flows pass automated tests.
- [ ] Load tests meet agreed latency and error-rate targets.
- [ ] Backups can be restored successfully.
- [ ] Monitoring exposes gateway health, queue backlog, and failed events.

---

# Final Recommended Defaults

| Area | Decision |
|---|---|
| Application architecture | Laravel modular monolith |
| Frontend | Vue 3 + TypeScript + Inertia + Tailwind |
| UI components | shadcn-vue |
| Client state | Pinia |
| Primary database | PostgreSQL |
| Browser authentication | Laravel session + secure cookies |
| Realtime | Custom Go WebSocket Gateway |
| Background processing | Go Worker + Laravel queues for Laravel-owned jobs |
| Durable event flow | PostgreSQL transactional outbox + one durable broker |
| Ephemeral state | Redis |
| Media | S3-compatible object storage |
| Deployment | Docker Compose + Nginx |
| Testing | Pest + Vitest + Playwright + Go tests |
| Monitoring | Prometheus + Grafana + structured logs |

**Primary engineering priority:** make message persistence, idempotency, durable event processing, and reconnect recovery reliable before optimizing for connection count. Registered users are not the same as concurrent connections; capacity planning must use measured concurrency, message rate, group sizes, payload sizes, and latency targets.
