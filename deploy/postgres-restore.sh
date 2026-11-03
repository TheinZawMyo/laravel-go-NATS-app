#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?Set DATABASE_URL to the target PostgreSQL database}"
: "${BACKUP_FILE:?Set BACKUP_FILE to a verified custom-format dump}"
if [[ "${CONFIRM_RESTORE:-}" != "restore-into-this-database" ]]; then
    echo "Refusing restore. Set CONFIRM_RESTORE=restore-into-this-database after verifying the target and backup." >&2
    exit 2
fi
[[ -r "$BACKUP_FILE" ]] || { echo "Backup file is not readable: $BACKUP_FILE" >&2; exit 2; }
pg_restore --list "$BACKUP_FILE" >/dev/null
pg_restore --clean --if-exists --no-owner --no-acl --dbname "$DATABASE_URL" "$BACKUP_FILE"
echo "Restore completed. Run the application migration/status and smoke checks before reopening traffic."
