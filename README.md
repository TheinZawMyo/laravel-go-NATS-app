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
