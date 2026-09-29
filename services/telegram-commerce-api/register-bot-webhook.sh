#!/usr/bin/env bash
set -Eeuo pipefail

ENV_FILE="${1:-/etc/mirza/telegram-commerce.env}"
[[ -r "$ENV_FILE" ]] || { echo "Cannot read $ENV_FILE" >&2; exit 1; }
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

read -r -p "Public bot webhook URL (https://bot.example.com/telegram_commerce_webhook.php): " BOT_WEBHOOK_URL
[[ "$BOT_WEBHOOK_URL" =~ ^https:// ]] || { echo "An HTTPS URL is required." >&2; exit 1; }

curl --fail-with-body --silent --show-error \
  -X POST "${TELEGRAM_COMMERCE_API_URL}/v1/webhooks" \
  -H "X-API-Key: ${TELEGRAM_COMMERCE_API_KEY}" \
  -H 'Content-Type: application/json' \
  --data "$(python3 -c 'import json,sys; print(json.dumps({"url":sys.argv[1],"secret":sys.argv[2],"events":["order.updated"]}))' "$BOT_WEBHOOK_URL" "$TELEGRAM_COMMERCE_WEBHOOK_SECRET")"
echo

