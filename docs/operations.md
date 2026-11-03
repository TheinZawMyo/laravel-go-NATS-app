# Production operations

This is the deployment checklist and first-response runbook for the initial
single-region deployment. Keep database, Redis, and NATS on private networks;
terminate public TLS at Nginx or an equivalent trusted ingress. The example
proxy configuration is in [`deploy/nginx/chat-app.conf`](../deploy/nginx/chat-app.conf).

## Deployment and rollback

1. Build immutable web, gateway, and worker artifacts from the same commit.
2. Confirm a recent PostgreSQL backup and that Redis and NATS are healthy.
3. Deploy the web artifact in maintenance mode or behind the load balancer.
4. Run `php artisan migrate --force` once as a controlled deployment step.
5. Deploy the gateway and worker; verify `/healthz`, `/readyz`, and `/metrics`.
6. Warm Laravel configuration/routes/views, then restore web traffic.
7. Verify login, direct message persistence, live delivery, reconnect/history
   recovery, authorized attachment download, and admin report queue access.
8. Watch application errors, p95 latency, gateway connections, outbox backlog,
   oldest event age, NATS stream health, Redis evictions, and database capacity.

For rollback, redeploy the prior immutable application artifacts. Do not
automatically roll back database migrations: migrations should be backward
compatible with the previous release. If data repair is required, stop writes,
take a fresh backup, restore into an isolated database first, validate it, then
perform the approved cutover. Never test restore against the live database.

## PostgreSQL backup and restore

Use `deploy/postgres-backup.sh` with a least-privilege backup role and a
protected `BACKUP_DIR`. The script produces a custom-format dump, validates its
catalog, and writes a SHA-256 checksum. Encrypt backups at rest and copy them to
storage outside the database host; define retention to match the service's data
retention and legal requirements. A checksum is an integrity check, not
encryption.

Restore only to an isolated target first. Verify the checksum, provision the
target database, then run:

```sh
DATABASE_URL='postgres://…/restore_target' \
BACKUP_FILE='/secure-backups/chat-app-YYYYMMDDTHHMMSSZ.dump' \
CONFIRM_RESTORE=restore-into-this-database \
./deploy/postgres-restore.sh
```

Run `php artisan migrate:status`, exercise representative account/conversation
queries, and compare expected row counts before any production cutover. Restore
tests should be scheduled and recorded; creating a backup alone does not prove
recoverability.

## Incident runbooks

### Messages persist but do not arrive live

- Check worker `/readyz`, NATS connectivity, and the JetStream `CHAT_EVENTS`
  stream's retention/capacity.
- Inspect unpublished `outbox_events` rows and `last_error`; confirm worker and
  gateway event subject prefixes match.
- Retry exhausted events only after correcting the cause, preserving event IDs.
- Clients should recover committed messages from Laravel history after reconnect.

### Gateway reconnect storm or elevated socket failures

- Check gateway `/readyz`, connection counts, per-user caps, CPU/memory, and
  Nginx WebSocket upgrade headers/timeouts.
- Confirm browser origin allowlist, token secret agreement, and clock sync.
- During deploy, drain connections with the gateway's graceful shutdown and
  monitor reconnect rate before shifting additional traffic.

### Upload or attachment incident

- Disable uploads at the edge or application configuration if abuse is active.
- Check malware scanner health and `MEDIA_SCAN_FAIL_CLOSED`; inspect upload
  validation failures without logging file contents or signed URLs.
- Confirm object storage access policies and authorized download checks.
- Do not expose the bucket publicly to work around application failures.

### Suspected account or authorization compromise

- Revoke the user's devices/sessions and suspend the account through moderation.
- Preserve relevant audit logs and request/event correlation IDs.
- Review admin audit logs and access logs; do not copy private message bodies
  into incident tickets.
- Rotate affected credentials/secrets, then redeploy all services that consume
  them and verify health/readiness.

## Release readiness records

Before production launch, record owners and results for load targets, security
review, error tracking, database restore drill, and alert routing. This repo
provides health and Prometheus endpoints, but an operator must still configure
and validate external monitoring, alert thresholds, retention, and on-call
delivery for the chosen hosting platform.
