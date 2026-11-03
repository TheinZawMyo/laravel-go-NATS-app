# Chat App

Messenger/Telegram-style chat application built as a Laravel modular monolith with a Vue 3 + Inertia frontend, a Go WebSocket gateway, and a Go worker.

See [CHAT_APP_IMPLEMENTATION_PLAN.md](CHAT_APP_IMPLEMENTATION_PLAN.md) for the full roadmap, [docs/architecture.md](docs/architecture.md) for the service layout, and [docs/websocket-protocol.md](docs/websocket-protocol.md) for the realtime contract.

## Stack

| Area | Choice |
|---|---|
| Backend | Laravel 13 (PHP 8.4), Fortify, Inertia 3 |
| Frontend | Vue 3, TypeScript, Tailwind CSS 4, shadcn-vue, Pinia |
| Database | PostgreSQL 17 |
| Cache / sessions / queues | Redis 7 |
| Durable broker | NATS JetStream |
| Realtime | Go gateway (`apps/gateway`) |
| Background jobs | Go worker (`apps/worker`) |
| Media | MinIO (S3-compatible) |
| Tests | Pest, Vitest, Playwright, `go test` |

## Requirements

- PHP 8.4 with `pdo_pgsql`, `redis`, `pcntl`
- Composer 2
- Node 22+
- Go 1.24+
- PostgreSQL 17, Redis 7, NATS with JetStream
- Optional: MinIO for media storage

## Local setup

### 1. Start infrastructure

PostgreSQL and Redis are expected on their default ports:

```bash
brew services start postgresql@17
brew services start redis
make db-create
```

Start NATS with JetStream in its own terminal:

```bash
make nats-dev
```

Start MinIO when working on media features:

```bash
make minio-dev
```

Set `DB_USERNAME` in `apps/web/.env` to your local PostgreSQL role, and update `DATABASE_URL` in the Go `.env` files to match.

### 2. Install dependencies

```bash
make setup
```

This creates `.env` files from the examples, installs PHP/npm/Go dependencies, and generates the application key.

The sample `.env` files use `postgres` as the database user. Adjust them to your local role.

### 3. Migrate

```bash
make migrate
```

### 4. Run the services

In separate terminals:

```bash
make web-dev      # http://localhost:8000
make gateway-dev  # http://localhost:8081
make worker-dev   # http://localhost:8082
make queue        # Laravel queue worker
```

The app is available at http://localhost:8000. Mail is written to the Laravel log by default.

Realtime needs **both** Go services: the gateway holds the WebSocket connections, and the worker publishes committed outbox events to NATS. If the worker is stopped, messages are still saved and shown after a refresh, but nothing arrives live. If the gateway is stopped, the connection status indicator stays in `reconnecting` and messages only arrive on refresh.

The gateway only accepts WebSocket upgrades from origins listed in `ALLOWED_ORIGINS` (`apps/gateway/.env`); add the origin you browse from.

## Media and attachments

Attachments never pass through the Laravel container as a stream: the client asks
`POST /api/v1/uploads/presign` for a signed single-use upload URL, `PUT`s the
bytes to that URL, and then attaches the resulting upload to a message. The
signed receiver re-validates size, MIME type, and file signature, scans the
bytes, and only then writes them to the `media` disk under a random storage key.
Downloads go through
`GET /api/v1/messages/{message}/attachments/{attachment}`, which enforces
conversation membership.

Relevant `.env` values in `apps/web/.env.example`:

| Variable | Default | Purpose |
|---|---|---|
| `MEDIA_DISK` | `media` | Disk used for attachment bytes; set to `s3` for MinIO/S3 |
| `MEDIA_MAX_BYTES` | `26214400` | Hard upload limit, enforced in both presign and store steps |
| `MEDIA_UPLOAD_TTL_MINUTES` | `30` | Lifetime of an unused signed upload URL |
| `MEDIA_PENDING_RETENTION_HOURS` | `24` | Age at which abandoned uploads are cleaned up |
| `MEDIA_DELETED_MESSAGE_GRACE_DAYS` | `7` | Retention for attachments of deleted messages |
| `MEDIA_SCAN_ENABLED` | `false` | Enable clamd scanning |
| `MEDIA_SCAN_HOST` / `MEDIA_SCAN_PORT` | `127.0.0.1` / `3310` | clamd endpoint |
| `MEDIA_SCAN_FAIL_CLOSED` | `false` | Reject uploads when the scanner is unreachable |
| `MEDIA_FFMPEG_ENABLED` | `true` | Extract video stills and audio/video duration |

Notes:

- Scanning happens before the write, so an infected upload never reaches the
  disk. With `MEDIA_SCAN_ENABLED=false` every file is accepted unverified (the
  `null` scanner); set it to `true` in production. The clamd reply grammar is
  covered by unit tests, but the scanner has not been exercised against a live
  `clamd` here — after pointing it at a real daemon, upload one known-good file
  and one EICAR test file to confirm both branches.
- `MEDIA_FFMPEG_ENABLED=true` requires `ffmpeg` and `ffprobe` on `PATH`
  (override with `MEDIA_FFMPEG_BINARY` / `MEDIA_FFPROBE_BINARY`). Missing
  binaries degrade gracefully: the file is kept without a thumbnail or duration.
- Two scheduled commands keep storage honest: `media:prune-uploads` deletes
  presigned uploads that were never attached to a message after
  `MEDIA_PENDING_RETENTION_HOURS`, and `media:purge-attachments` reclaims the
  bytes of attachments whose message was deleted more than
  `MEDIA_DELETED_MESSAGE_GRACE_DAYS` days ago. Both run daily.
- The shared media gallery is reachable from the conversation header and pages
  through attachments newest-first; the composer records voice messages with
  `MediaRecorder` and uploads them as normal audio attachments.

## Search, moderation, and privacy

Search is scoped to the signed-in user and never bypasses conversation
authorization:

- `GET /api/v1/search/conversations?q=` matches conversation titles and the
  names/usernames of other members in conversations the viewer has not left.
- `GET /api/v1/search/messages?q=` joins the viewer's active
  `conversation_members` row, hides deleted messages, and respects
  `history_from_seq`, so members who joined after history was hidden cannot
  find older messages. PostgreSQL uses the generated `messages.search_vector`
  column with `plainto_tsquery('simple', ...)`; SQLite (tests) falls back to an
  escaped `LIKE`.
- `GET /api/v1/users?q=` goes through `PrivacyService`, which hides profiles
  that are limited to contacts.

Search, message sends, contact requests, and reports are rate limited in
`AppServiceProvider` (60 searches/min, 60 messages/min, 30 contact
requests/hour, 10 reports/hour). Reporting is available from a direct
conversation's action menu and from each message bubble. The API is
`POST /api/v1/reports` with either `username` or `message_id`.

Users with `users.is_admin = true` get:

- `GET /api/v1/admin/reports` and
  `POST /api/v1/admin/reports/{report}/resolve` for the review queue.
- `POST /api/v1/admin/users/{username}/moderate` with `suspend`, `ban`, or
  `reactivate`. Suspending or banning revokes the target's devices.
- `GET /api/v1/admin/audit-logs` for the append-only audit trail. Every
  moderation action and account deletion writes a row via `AuditLogger`.
- The `/moderation` Inertia page, linked in the sidebar only for admins.
  Promote an account with `UPDATE users SET is_admin = true WHERE username = ...`
  (or the equivalent model update).

Privacy settings live at `/settings/privacy`: last seen (`everyone`,
`contacts`, `nobody`), profile visibility (`everyone`, `contacts`), and contact
request policy (`everyone`, `contacts`, `nobody`). Presence annotations and
`PublicUserResource` honour the last-seen setting. `GET /api/v1/me/export`
streams a JSON export of the account, privacy settings, contacts, blocks,
conversation metadata, authored messages, reports, and devices — never password
hashes, 2FA secrets, recovery codes, or remember tokens. Account deletion is
performed from `/settings/profile`, which revokes devices and records an audit
entry.

Retention policy:

- Deleted messages keep their rows (tombstones) so clients can render "This
  message was deleted"; bodies are cleared on delete.
- Abandoned uploads are pruned after `MEDIA_PENDING_RETENTION_HOURS` (24h).
- Attachments of deleted messages are purged after
  `MEDIA_DELETED_MESSAGE_GRACE_DAYS` (7 days). Set it to `0` to purge on the
  next daily run.
- Reports, audit logs, and conversation/message history are retained until an
  account is deleted; exports are generated on demand and not stored.

## Server-side rendering

Inertia SSR is enabled in `apps/web/config/inertia.php` (`ssr.enabled = true`).
The first page load is rendered by a Node worker, and the browser then hydrates
that HTML, so a page only gets server HTML while all of this is true: the bundle
was built (`npm run build:ssr` → `bootstrap/ssr/`), the worker is running
(`php artisan inertia:start-ssr`, check with `php artisan inertia:check-ssr`),
and the worker was started from the current bundle. Without a worker Inertia
silently falls back to a plain client render — that is why hydration warnings
appear and disappear between runs, and why a worker started before a rebuild can
render markup that no longer matches the client bundle.

Two rules keep hydration quiet, because the server has no `window`, no
Notification permission, no consumer timezone and a different clock:

- Browser-only state is read in `onMounted`, never during setup. `useMounted()`
  exposes the "first render is still the server render" flag so the markup for
  that first render is identical on both sides.
- Clock- or timezone-dependent text (presence labels, list timestamps, day
  dividers) renders empty until `useMounted()` flips. `useNow()` also starts its
  interval on mount only, so an SSR request never leaves a timer behind.

## Ports

| Service | Port |
|---|---|
| Laravel / Inertia | 8000 |
| Go gateway | 8081 (`/healthz`, `/readyz`, `/metrics`, `/ws`) |
| Go worker health | 8082 (`/healthz`, `/readyz`, `/metrics`) |
| PostgreSQL | 5432 |
| Redis | 6379 |
| NATS | 4222 (monitoring 8222) |
| MinIO | 9000 (console 9001) |
| NATS monitoring | 8222 |

## Repository layout

```text
apps/
  web/       Laravel + Inertia + Vue application
  gateway/   Go WebSocket gateway
  worker/    Go background worker
docs/        Architecture notes and ADRs
go.work
```

## Quality checks

```bash
make test
make lint
cd apps/web && npm run test:unit
cd apps/web && npm run types:check
```

## Environment files

- `apps/web/.env.example` — Laravel, PostgreSQL, Redis, NATS, MinIO, gateway URL
- `apps/gateway/.env.example` — gateway address, credentials, allowed origins
- `apps/worker/.env.example` — worker address, credentials, outbox dispatcher poll/batch settings

Never commit real `.env` files or secrets.
# laravel-go-chat-app
