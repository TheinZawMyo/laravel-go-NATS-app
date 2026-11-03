#!/usr/bin/env bash
set -euo pipefail

: "${DATABASE_URL:?Set DATABASE_URL to the PostgreSQL connection URI}"
: "${BACKUP_DIR:?Set BACKUP_DIR to a protected backup directory}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
destination="$BACKUP_DIR/chat-app-$timestamp.dump"
temporary="$destination.partial"
trap 'rm -f "$temporary"' EXIT

pg_dump --format=custom --no-owner --no-acl "$DATABASE_URL" --file "$temporary"
pg_restore --list "$temporary" >/dev/null
mv "$temporary" "$destination"
chmod 600 "$destination"
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$destination" > "$destination.sha256"
else
    shasum -a 256 "$destination" > "$destination.sha256"
fi
chmod 600 "$destination.sha256"
echo "Created and verified $destination"
