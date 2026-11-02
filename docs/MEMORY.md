# Project Memory

This file summarizes the major completed tasks in the repository and current follow-up items. See [`CHAT_APP_IMPLEMENTATION_PLAN.md`](../CHAT_APP_IMPLEMENTATION_PLAN.md) for the detailed roadmap and [`CHAT_DESIGN_PLAN.md`](../CHAT_DESIGN_PLAN.md) for the UI design record.

## Completed tasks

- **Foundation and authentication** — Laravel/Vue foundations and authentication work are recorded in commit `a81fc5d` (2026-10-29).
- **Core messaging and initial chat UI** — Conversation and message capabilities and the chat interface were built in commits `a706d98` and `8283b2d` (2026-10-29).
- **WebSocket gateway and realtime hardening** — Implemented the Go gateway, authenticated connections, conversation subscriptions, reconnect and token renewal, connection and frame rate limits, graceful shutdown, and event validation. See the [WebSocket protocol](websocket-protocol.md) and [ADR 0004](adr/0004-websocket-connection-hardening.md).
- **Reliable realtime events** — Added the PostgreSQL transactional outbox and Go worker dispatch. Messages and outbox events are stored in one transaction, and the worker publishes to NATS with retries and backoff.
- **Receipts, presence, and notifications** — Implementation work for this area is recorded in commit `b6e0bf1` (2026-10-31).
- **Groups, contacts, and social features** — Implementation work for this area is recorded in commits `bfa2bcd` and `85c6409` (2026-11-01).
- **Chat UI refresh** — Completed a Telegram Web-inspired two-pane layout, conversation rail, message bubbles, header/composer, and light/dark theme tokens. See [`CHAT_DESIGN_PLAN.md`](../CHAT_DESIGN_PLAN.md).
- **Media and file sharing (Phase 6)** — Presigned uploads with server-side size/MIME/signature validation, malware scanning before any byte is written (`NullMalwareScanner`, `ClamAvMalwareScanner`), `ProcessAttachment` jobs for thumbnails and duration, authorized downloads, abandoned-upload pruning, and deleted-message retention via `media:purge-attachments`. Frontend adds drag-and-drop uploads with progress, media/audio/file previews, a shared media gallery dialog with cursor paging, and `MediaRecorder` voice messages. See [ADR 0005](adr/0005-attachment-scanning-and-retention.md) and the "Media and attachments" section of the [README](../README.md). Phase 6 changes are complete in the working tree, pending a commit.

- **Contact request responses stay answered** — `ContactService::requestsFor()` now lists pending requests only, so accepting or rejecting a request no longer leaves it in the Requests tab when the realtime `contact.request.updated` event triggers a refetch. The people dialog also prefers the contacts store over the `relationship` field baked into an older search response. Covered by `test_answered_requests_leave_the_pending_lists` in `tests/Feature/Api/ContactTest.php` (2026-11-02, pending a commit).

## Current follow-up items

- Inertia SSR (`config/inertia.php` has `ssr.enabled = true`) only serves server HTML while `bootstrap/ssr/` is built and `php artisan inertia:start-ssr` is running; otherwise Laravel silently falls back to a client render. Browser-only state (Notification permission) and clock/timezone-dependent labels (presence, list timestamps, day dividers) must stay behind `useMounted()` or hydration warns; see the "Server-side rendering" section of the [README](../README.md).
- The implementation roadmap still has unfinished work. Reconcile its checklists for core messaging, groups/social features, receipts/presence, and other areas with the implementation before marking them complete.
- Media follow-ups that the automated suite cannot cover: `ClamAvMalwareScanner` is unit tested against recorded clamd replies, but has not been run against a live `clamd`; voice recording and the media gallery have not been verified in a real browser; and the `s3` disk path for attachments is configured but untested against MinIO.
- The project currently uses core NATS. The roadmap notes that durable NATS JetStream retention remains to be completed.

## Maintaining this file

When a major task is completed, add its date, a concise summary, and links to the relevant plan or ADR under “Completed tasks.” Do not describe unfinished work or changes that exist only in the working tree as complete; record their status under “Current follow-up items.”
