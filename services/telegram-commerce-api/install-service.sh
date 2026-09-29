#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Run this installer as root." >&2
  exit 1
fi

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="/opt/mirza-telegram-commerce"
ENV_DIR="/etc/mirza"
ENV_FILE="$ENV_DIR/telegram-commerce.env"
PYTHON_BIN="${PYTHON_BIN:-python3}"

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y python3 python3-venv python3-pip curl ca-certificates rsync
install -d -m 0750 -o root -g www-data "$INSTALL_DIR" "$ENV_DIR"
rsync -a --delete --exclude '.env' --exclude '.venv' --exclude 'data' --exclude '__pycache__' "$SOURCE_DIR/" "$INSTALL_DIR/"

"$PYTHON_BIN" -m venv "$INSTALL_DIR/.venv"
"$INSTALL_DIR/.venv/bin/pip" install --disable-pip-version-check --no-cache-dir -r "$INSTALL_DIR/requirements.txt"
install -d -m 0750 -o www-data -g www-data "$INSTALL_DIR/data"

if [[ ! -f "$ENV_FILE" ]]; then
  API_KEY="mztc_$($PYTHON_BIN -c 'import secrets; print(secrets.token_urlsafe(36))')"
  ENCRYPTION_KEY="$($INSTALL_DIR/.venv/bin/python -c 'from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())')"
  WEBHOOK_SECRET="$($PYTHON_BIN -c 'import secrets; print(secrets.token_urlsafe(40))')"
  cat >"$ENV_FILE" <<EOF
APP_ENV=production
DATABASE_URL=sqlite:///$INSTALL_DIR/data/commerce.db
API_BOOTSTRAP_KEY=$API_KEY
API_BOOTSTRAP_SCOPES=products:read,orders:read,orders:write,payments:write,webhooks:manage,admin
DATA_ENCRYPTION_KEY=$ENCRYPTION_KEY
PUBLIC_BASE_URL=http://127.0.0.1:8088
WORKER_POLL_SECONDS=2
WEBHOOK_TIMEOUT_SECONDS=10
WEBHOOK_BLOCK_PRIVATE_NETWORKS=true
DEFAULT_PROVIDER=fragment
TELEGRAM_COMMERCE_API_URL=http://127.0.0.1:8088
TELEGRAM_COMMERCE_API_KEY=$API_KEY
TELEGRAM_COMMERCE_WEBHOOK_SECRET=$WEBHOOK_SECRET
FRAGMENT_STEL_SSID=
FRAGMENT_STEL_DT=
FRAGMENT_STEL_TOKEN=
FRAGMENT_STEL_TON_TOKEN=
FRAGMENT_WALLET_SEED=
FRAGMENT_TON_API_KEY=
FRAGMENT_WALLET_VERSION=V5R1
TELEGRAM_BOT_TOKEN=
EOF
fi
chown root:www-data "$ENV_FILE"
chmod 0640 "$ENV_FILE"

cat >/etc/systemd/system/mirza-telegram-commerce-api.service <<EOF
[Unit]
Description=Mirza Telegram Commerce API
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$INSTALL_DIR
EnvironmentFile=$ENV_FILE
ExecStart=$INSTALL_DIR/.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8088 --proxy-headers
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$INSTALL_DIR/data

[Install]
WantedBy=multi-user.target
EOF

cat >/etc/systemd/system/mirza-telegram-commerce-worker.service <<EOF
[Unit]
Description=Mirza Telegram Commerce Worker
After=network-online.target mirza-telegram-commerce-api.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$INSTALL_DIR
EnvironmentFile=$ENV_FILE
ExecStart=$INSTALL_DIR/.venv/bin/python -m app.worker
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$INSTALL_DIR/data

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now mirza-telegram-commerce-api.service mirza-telegram-commerce-worker.service

for _ in {1..20}; do
  curl -fsS http://127.0.0.1:8088/healthz >/dev/null && break
  sleep 1
done
curl -fsS http://127.0.0.1:8088/healthz >/dev/null || {
  journalctl -u mirza-telegram-commerce-api.service -n 50 --no-pager
  exit 1
}

echo "Telegram Commerce API installed."
echo "Secure configuration: $ENV_FILE"
echo "Local API: http://127.0.0.1:8088"
echo "Edit Fragment credentials in the secure configuration, then restart both services."

