#!/usr/bin/env bash
# Install a new version of Topline. Upload and unzip the new topline-os folder anywhere
# on the server (e.g. /root/topline-os), then from inside it run:
#
#   sudo bash deploy/update.sh
#
# Backs up first, keeps your settings, photos and data, applies database changes,
# rebuilds the website without taking it offline, and restarts the API.
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run with sudo (or as root)."; exit 1; }
SRC=$(cd "$(dirname "$0")/.." && pwd)
APP=/opt/topline/app
[[ -f $APP/backend/.env ]] || { echo "Topline isn't installed yet: run deploy/setup.sh first."; exit 1; }
[[ "$SRC" != "$APP" ]] || { echo "Run this from the NEW unzipped folder, not $APP."; exit 1; }
step () { echo; echo "==> $*"; }
as_app () { sudo -u topline -H bash -c "cd '$APP' && $*"; }

step "Backing up"
sudo -u topline "$APP/deploy/backup.sh"

step "Copying the new version"
rsync -a --delete \
  --exclude node_modules --exclude dist --exclude 'dist.*' --exclude .env --exclude .env.local --exclude .env.production.local \
  --exclude backend/.env --exclude backend/.venv --exclude backend/instance \
  --exclude __pycache__ --exclude .pytest_cache \
  "$SRC/" "$APP/"
chmod +x "$APP/deploy/"*.sh
chown -R topline:topline "$APP"

step "Updating the API and database"
as_app "backend/.venv/bin/pip install -q -r backend/requirements.txt"
as_app "cd backend && .venv/bin/flask --app wsgi db upgrade"

step "Building the website"
as_app "npm ci --no-audit --no-fund --loglevel=error && npx vite build --logLevel warn --outDir dist.new --emptyOutDir"
as_app "rm -rf dist.old && { [ ! -d dist ] || mv dist dist.old; } && mv dist.new dist"

step "Restarting"
WORKERS=$(( $(nproc) * 2 + 1 )); if (( WORKERS > 9 )); then WORKERS=9; fi
for unit in "$APP"/deploy/systemd/*; do
  sed "s|__WORKERS__|$WORKERS|" "$unit" > "/etc/systemd/system/$(basename "$unit")"
done
systemctl daemon-reload
systemctl enable --now topline-billing.timer topline-payments.timer topline-backup.timer >/dev/null
systemctl restart topline-api
for i in $(seq 1 15); do curl -fs http://127.0.0.1:8000/api/health >/dev/null && break; sleep 1; done
curl -fs http://127.0.0.1:8000/api/health && echo && echo "Updated. Previous website kept in $APP/dist.old"
