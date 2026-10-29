#!/usr/bin/env bash
# One-time setup of Topline on a fresh Hostinger VPS (Ubuntu 24.04).
#
#   sudo bash deploy/setup.sh toplinehub.co.ke you@example.com
#
# Installs PostgreSQL, Python, Node.js and Caddy; copies the app to /opt/topline/app;
# creates the database, the live settings file and the services; builds the website;
# turns on HTTPS and the firewall. Safe to run again: it keeps existing settings and data.
set -euo pipefail

DOMAIN=${1:-}
EMAIL=${2:-}
if [[ -z "$DOMAIN" || -z "$EMAIL" ]]; then
  echo "Usage: sudo bash deploy/setup.sh <your-domain> <your-email>"; exit 1
fi
[[ $EUID -eq 0 ]] || { echo "Run with sudo (or as root)."; exit 1; }
. /etc/os-release
[[ "$ID" == "ubuntu" && "${VERSION_ID%%.*}" -ge 24 ]] || { echo "Needs Ubuntu 24.04 or newer (this is $PRETTY_NAME)."; exit 1; }

SRC=$(cd "$(dirname "$0")/.." && pwd)
APP=/opt/topline/app
DATA=/var/lib/topline
step () { echo; echo "==> $*"; }

step "Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q python3 python3-venv postgresql rsync ufw curl ca-certificates gnupg unzip

if ! command -v node >/dev/null || [[ $(node -p 'process.versions.node.split(".")[0]') -lt 22 ]]; then
  step "Installing Node.js 22"
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y -q nodejs
fi

if ! command -v caddy >/dev/null; then
  step "Installing Caddy (web server with automatic HTTPS)"
  curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt > /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -q
  apt-get install -y -q caddy
fi

step "Creating the topline user and folders"
id topline >/dev/null 2>&1 || useradd --system --create-home --home-dir /opt/topline --shell /usr/sbin/nologin topline
mkdir -p "$APP" "$DATA/uploads" /var/backups/topline
if [[ "$SRC" != "$APP" ]]; then
  rsync -a --delete \
    --exclude node_modules --exclude dist --exclude 'dist.*' --exclude .env --exclude .env.local --exclude .env.production.local \
    --exclude backend/.env --exclude backend/.venv --exclude backend/instance \
    --exclude __pycache__ --exclude .pytest_cache \
    "$SRC/" "$APP/"
fi
chmod +x "$APP/deploy/"*.sh
chown -R topline:topline /opt/topline "$DATA" /var/backups/topline
chmod 755 /opt/topline "$APP"          # Caddy must be able to read the website files
chmod 750 "$DATA" /var/backups/topline  # photos and backups: the app only

step "Creating the database"
sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='topline'" | grep -q 1 \
  || sudo -u postgres createuser topline
sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='topline'" | grep -q 1 \
  || sudo -u postgres createdb --owner=topline topline

step "Writing live settings"
ENV=$APP/backend/.env
if [[ -f $ENV ]]; then
  echo "Keeping existing $ENV"
else
  sed -e "s|__DOMAIN__|$DOMAIN|g" \
      -e "s|__SECRET_KEY__|$(python3 -c 'import secrets; print(secrets.token_hex(32))')|" \
      -e "s|__JWT_SECRET__|$(python3 -c 'import secrets; print(secrets.token_hex(32))')|" \
      "$APP/deploy/env.production.example" > "$ENV"
  chown topline:topline "$ENV"; chmod 600 "$ENV"
fi
printf "VITE_API_URL=/api\nVITE_ROOT_DOMAIN=%s\nVITE_STORE_LINKS=subdomain\n" "$DOMAIN" > "$APP/.env.production.local"
chown topline:topline "$APP/.env.production.local"

step "Installing the API"
as_app () { sudo -u topline -H bash -c "cd '$APP' && $*"; }
[[ -x $APP/backend/.venv/bin/python ]] || as_app "python3 -m venv backend/.venv"
as_app "backend/.venv/bin/pip install -q --upgrade pip && backend/.venv/bin/pip install -q -r backend/requirements.txt"
as_app "cd backend && .venv/bin/flask --app wsgi init-db"
as_app "cd backend && .venv/bin/flask --app wsgi db upgrade"

step "Building the website"
as_app "npm ci --no-audit --no-fund --loglevel=error && npx vite build --logLevel warn"

step "Starting services"
WORKERS=$(( $(nproc) * 2 + 1 )); if (( WORKERS > 9 )); then WORKERS=9; fi
for unit in "$APP"/deploy/systemd/*; do
  sed "s|__WORKERS__|$WORKERS|" "$unit" > "/etc/systemd/system/$(basename "$unit")"
done
systemctl daemon-reload
systemctl enable --now topline-api.service topline-billing.timer topline-payments.timer topline-backup.timer
systemctl restart topline-api.service

sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__EMAIL__|$EMAIL|g" "$APP/deploy/Caddyfile" > /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
systemctl enable caddy >/dev/null 2>&1
systemctl reload caddy || systemctl restart caddy

step "Turning on the firewall (SSH, HTTP, HTTPS only)"
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443 >/dev/null
ufw --force enable >/dev/null

step "Checking"
for i in $(seq 1 15); do curl -fs http://127.0.0.1:8000/api/health >/dev/null && break; sleep 1; done
curl -fs http://127.0.0.1:8000/api/health && echo
IP=$(curl -fs4 https://ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')

cat <<DONE

Topline is installed.

1. DNS (Hostinger hPanel > Domains > $DOMAIN > DNS / Nameservers), point these at $IP:
     A   @   $IP
     A   *   $IP
     A   www $IP   (or CNAME www -> $DOMAIN)
2. Make yourself an admin:
     sudo -u topline -H bash -c "cd $APP/backend && .venv/bin/flask --app wsgi create-admin 'Felix' 0712000000"
3. Open https://$DOMAIN once DNS has updated (minutes to a few hours).
   Login codes: SMS is simulated until you add Africa's Talking keys, so read them with:
     sudo journalctl -u topline-api | grep "Topline code" | tail -3

Settings: $ENV   (then: sudo systemctl restart topline-api)
Logs:     sudo journalctl -u topline-api -f      sudo journalctl -u caddy -f
DONE
