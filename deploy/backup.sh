#!/usr/bin/env bash
# Daily backup of the database and uploaded photos to /var/backups/topline (kept 14 days).
# Runs as the "topline" user from topline-backup.timer, and before every update.
# Restore a database backup:
#   sudo systemctl stop topline-api
#   sudo -u topline pg_restore --clean --if-exists -d topline /var/backups/topline/db_<date>.dump
#   sudo systemctl start topline-api
set -euo pipefail
DEST=/var/backups/topline
STAMP=$(date +%Y-%m-%d_%H%M)
mkdir -p "$DEST"
pg_dump --format=custom --dbname=topline --file="$DEST/db_$STAMP.dump"
tar -czf "$DEST/uploads_$STAMP.tgz" -C /var/lib/topline uploads
find "$DEST" -type f -mtime +14 -delete
echo "Backed up to $DEST/db_$STAMP.dump and uploads_$STAMP.tgz"
