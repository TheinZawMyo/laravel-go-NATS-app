# ADR 0005: Scan attachments before they are written and expire them after deletion

## Status

Accepted

## Context

Phase 6 introduced user-supplied files. Two problems appeared once attachments were real:

1. Scanning after the write leaves a window where infected bytes are readable, and any crash between the write and the scan leaves them stored with no verdict. A scanner that is down also had no defined behavior: silently accepting or silently rejecting were both possible.
2. Deleting a message is a soft delete, so the rows and the bytes stayed forever. Attachment downloads had to keep working for a message that is no longer visible anywhere in the UI, and orphaned presigned uploads accumulated with no owner to clean them up.

## Decision

1. Bytes are scanned **before** they are written to the disk. `UploadService` buffers the request body, asks the `MalwareScanner` for a verdict, and only writes on `Clean` or `Skipped`. An `Infected` result writes nothing at all, so there is no stored file to leak.
2. The verdict is persisted in `message_attachments.scan_status` (`pending`, `clean`, `skipped`, `infected`, `failed`). Only `clean` and `skipped` are sendable; `infected` and `failed` are terminal, so a file that could not be verified cannot be attached to a message later.
3. The scanner is an interface with three implementations: `NullMalwareScanner` (scanning disabled), `ClamAvMalwareScanner` (clamd `INSTREAM` over a socket with a timeout), and a fake used by tests. Failure semantics are explicit via `chat.media.scan.fail_closed`: `false` records `skipped` and accepts the file as unverified, `true` records `failed` and rejects it. The default is fail-open so a missing clamd does not take down uploads in development.
4. Attachment lifecycle is derived from the message. Deleting a message stamps `deleted_at`, and `media:purge-attachments` deletes the stored files and rows for attachments whose message has been deleted for longer than `MEDIA_DELETED_MESSAGE_GRACE_DAYS`. Downloads and gallery queries filter on both `message_attachments.deleted_at` and the parent message, and return `404` for a purged or deleted attachment.
5. `media:prune-uploads` removes presigned uploads that were never attached to a message after `MEDIA_PENDING_RETENTION_HOURS`, together with their bytes. Both commands are scheduled daily.

## Consequences

- The upload request body is held in memory (one file, bounded by `MEDIA_MAX_BYTES`) rather than streamed, which is the price of never persisting unscanned bytes.
- `MEDIA_SCAN_ENABLED=false` is a development default and means every upload is stored unverified. Production must enable clamd and should consider `MEDIA_SCAN_FAIL_CLOSED=true`.
- Media processing (`ProcessAttachment`) runs after the write, so a missing `ffmpeg`/`ffprobe` only costs a thumbnail or a duration, never the upload. Retries are safe because the job is idempotent per attachment.
- Deletion is reversible within the grace window, but restoring a message after the purge run leaves attachments that no longer exist. The UI treats them as unavailable instead of rendering broken media.
- `scan_status` is exposed on the attachment resource, so the frontend can disable downloads for anything that is not sendable.
