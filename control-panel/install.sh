#!/usr/bin/env bash
set -Eeuo pipefail

PANEL_ROOT="${MIRZA_PANEL_ROOT:-/opt/mirza-control-panel}"
PANEL_SOURCE="$PANEL_ROOT/source"
PANEL_DATA="$PANEL_ROOT/data"
PANEL_BOT_SOURCE="$PANEL_ROOT/bot-source"
PANEL_ENV="$PANEL_ROOT/.env"
PANEL_COMPOSE="$PANEL_ROOT/compose.yml"
AGENT_STATE="/var/lib/mirza-panel-agent"
AGENT_CONFIG="/etc/mirza-panel-agent.json"
AGENT_SERVICE="/etc/systemd/system/mirza-panel-agent.service"
MANAGER="/usr/local/bin/mirza"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BUNDLED_MANAGER="$SCRIPT_DIR/mirza-manager.sh"
BOT_SOURCE_INPUT=""
BOT_SOURCE_RESOLVED=""
SOURCE_TEMP_DIR=""

MODE="install"
PANEL_DOMAIN=""
PANEL_USERNAME="admin"
PANEL_PASSWORD=""
PANEL_PORT=""
ASSUME_YES="0"

log() { printf '  [Mirza Control] %s\n' "$*"; }
fail() { printf '  [Mirza Control] ERROR: %s\n' "$*" >&2; exit 1; }

valid_domain() {
    [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$ ]]
}

valid_username() {
    [[ "$1" =~ ^[A-Za-z0-9_.-]{3,64}$ ]]
}

docker_compose() {
    if docker compose version >/dev/null 2>&1; then
        docker compose "$@"
    elif command -v docker-compose >/dev/null 2>&1; then
        docker-compose "$@"
    else
        return 127
    fi
}

allocate_port() {
    local port
    for ((port=18888; port<=18949; port++)); do
        if ! ss -ltnH 2>/dev/null | awk '{print $4}' | grep -Eq "(^|:)${port}$"; then
            printf '%s' "$port"
            return 0
        fi
    done
    return 1
}

parse_args() {
    if [[ "${1:-}" =~ ^(install|status|reset-password|remove)$ ]]; then
        MODE="$1"
        shift
    fi
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --domain) [ "$#" -ge 2 ] || fail "Missing value for --domain"; PANEL_DOMAIN="$2"; shift 2 ;;
            --username) [ "$#" -ge 2 ] || fail "Missing value for --username"; PANEL_USERNAME="$2"; shift 2 ;;
            --password) [ "$#" -ge 2 ] || fail "Missing value for --password"; PANEL_PASSWORD="$2"; shift 2 ;;
            --port) [ "$#" -ge 2 ] || fail "Missing value for --port"; PANEL_PORT="$2"; shift 2 ;;
            --bot-source) [ "$#" -ge 2 ] || fail "Missing value for --bot-source"; BOT_SOURCE_INPUT="$2"; shift 2 ;;
            --yes) ASSUME_YES="1"; shift ;;
            *) fail "Unknown option: $1" ;;
        esac
    done
}

ensure_root() {
    [ "$(id -u)" -eq 0 ] || fail "Run this installer as root."
}

ensure_prerequisites() {
    local missing=0 tool
    if [ ! -x "$MANAGER" ] && [ -f "$BUNDLED_MANAGER" ]; then
        grep -q 'process_arguments' "$BUNDLED_MANAGER" || fail "The bundled Mirza manager is invalid."
        install -m 0755 "$BUNDLED_MANAGER" /root/install.sh
        ln -sfn /root/install.sh "$MANAGER"
    fi
    for tool in docker python3 rsync openssl systemctl ss curl unzip; do
        command -v "$tool" >/dev/null 2>&1 || missing=1
    done
    if [ "$missing" -eq 1 ]; then
        log "Installing required host packages..."
        apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io python3 rsync openssl iproute2 ca-certificates curl unzip
    fi
    systemctl enable --now docker >/dev/null 2>&1 || fail "Docker could not be started."
    if ! docker compose version >/dev/null 2>&1 && ! command -v docker-compose >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y docker-compose-v2 >/dev/null 2>&1 \
            || DEBIAN_FRONTEND=noninteractive apt-get install -y docker-compose-plugin >/dev/null 2>&1 \
            || DEBIAN_FRONTEND=noninteractive apt-get install -y docker-compose >/dev/null 2>&1 \
            || fail "Docker Compose could not be installed."
    fi
    docker_compose version >/dev/null 2>&1 || fail "Docker Compose is not available."
    [ -x "$MANAGER" ] || fail "Mirza manager is missing. Run the main install.sh first."
    if [ ! -f "/opt/mirza/gateway/mode" ]; then
        log "Initializing the isolated Docker runtime..."
        "$MANAGER" docker-init || fail "Mirza Docker gateway could not be initialized."
    fi
}

resolve_bot_source() {
    local candidate extracted archive
    if [ -n "$BOT_SOURCE_INPUT" ]; then
        candidate=$(readlink -f -- "$BOT_SOURCE_INPUT" 2>/dev/null) || fail "Invalid --bot-source path."
    elif [ -f "$SCRIPT_DIR/bot-template/index.php" ] && [ -f "$SCRIPT_DIR/bot-template/table.php" ]; then
        candidate="$SCRIPT_DIR/bot-template"
    elif [ -f "$REPO_ROOT/index.php" ] && [ -f "$REPO_ROOT/table.php" ]; then
        candidate="$REPO_ROOT"
    elif [ -f "$PANEL_BOT_SOURCE/index.php" ] && [ -f "$PANEL_BOT_SOURCE/table.php" ]; then
        candidate="$PANEL_BOT_SOURCE"
    else
        SOURCE_TEMP_DIR=$(mktemp -d /tmp/mirza-panel-bot-source.XXXXXX) || fail "Could not create a temporary source directory."
        archive="$SOURCE_TEMP_DIR/source.zip"
        log "Downloading the default bot template..."
        curl -fL --retry 3 --connect-timeout 15 --max-time 240 \
            "https://github.com/TheRealMr404/TheRealBot/archive/refs/heads/main.zip" -o "$archive" \
            || fail "The default bot source could not be downloaded."
        unzip -q "$archive" -d "$SOURCE_TEMP_DIR/extracted" || fail "The bot source archive is invalid."
        extracted=$(find "$SOURCE_TEMP_DIR/extracted" -mindepth 1 -maxdepth 1 -type d | head -1)
        candidate="$extracted"
    fi
    [ -f "$candidate/index.php" ] && [ -f "$candidate/table.php" ] \
        || fail "The selected bot source must contain index.php and table.php."
    BOT_SOURCE_RESOLVED=$(readlink -f -- "$candidate")
}

write_agent_config() {
    install -d -m 0700 "$AGENT_STATE" "$AGENT_STATE/jobs"
    install -d -m 0755 /usr/local/lib
    install -m 0755 "$PANEL_SOURCE/host/mirza-panel-agent.py" /usr/local/lib/mirza-panel-agent.py
    cat > "$AGENT_CONFIG" <<EOF
{
  "db_path": "$PANEL_DATA/panel.sqlite",
  "socket_path": "/run/mirza-panel/control.sock",
  "instances_root": "/opt/mirza/instances",
  "backups_root": "/opt/mirza/backups",
  "state_root": "$AGENT_STATE",
  "manager_path": "$MANAGER",
  "bot_source_dir": "$PANEL_BOT_SOURCE"
}
EOF
    chmod 0600 "$AGENT_CONFIG"

    cat > "$AGENT_SERVICE" <<'EOF'
[Unit]
Description=Mirza Control restricted host agent
After=docker.service network-online.target
Wants=network-online.target
Requires=docker.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/lib/mirza-panel-agent.py
Restart=on-failure
RestartSec=3
User=root
Group=root
UMask=0007
NoNewPrivileges=true
PrivateTmp=true
CapabilityBoundingSet=CAP_CHOWN CAP_DAC_OVERRIDE CAP_FOWNER CAP_SETGID CAP_SETUID CAP_NET_ADMIN CAP_NET_BIND_SERVICE CAP_SYS_ADMIN
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable mirza-panel-agent.service >/dev/null
}

write_compose() {
    docker network inspect mirza-control-edge >/dev/null 2>&1 || docker network create mirza-control-edge >/dev/null
    cat > "$PANEL_ENV" <<EOF
COMPOSE_PROJECT_NAME=mirza_control
PANEL_DOMAIN=$PANEL_DOMAIN
APP_PORT=$PANEL_PORT
TZ=Asia/Tehran
EOF
    chmod 0600 "$PANEL_ENV"
    cat > "$PANEL_COMPOSE" <<'EOF'
services:
  panel:
    build:
      context: ./source
      dockerfile: Dockerfile
    image: mirza-control-panel:local
    container_name: mirza-control-panel
    restart: unless-stopped
    environment:
      TZ: ${TZ}
    volumes:
      - ./data:/var/lib/mirza-panel
      - /run/mirza-panel:/run/mirza-panel
    ports:
      - "127.0.0.1:${APP_PORT}:80"
    networks:
      - edge
    logging:
      options:
        max-size: "10m"
        max-file: "3"
networks:
  edge:
    external: true
    name: mirza-control-edge
EOF
}

sync_sources() {
    [ -f "$SCRIPT_DIR/Dockerfile" ] || fail "Control-panel source files are incomplete."
    [ -n "$BOT_SOURCE_RESOLVED" ] || fail "Bot source root was not resolved."
    install -d -m 0750 "$PANEL_ROOT" "$PANEL_SOURCE" "$PANEL_BOT_SOURCE"
    install -d -m 0770 -o 33 -g 33 "$PANEL_DATA"
    if [ -f "$PANEL_DATA/panel.sqlite" ]; then
        cp -a "$PANEL_DATA/panel.sqlite" "$PANEL_DATA/panel.sqlite.preinstall-$(date +%Y%m%d_%H%M%S)"
    fi
    rsync -a --delete \
        --exclude='data/' \
        --exclude='bot-template/' \
        --exclude='mirza-manager.sh' \
        "$SCRIPT_DIR/" "$PANEL_SOURCE/"
    if [ "$BOT_SOURCE_RESOLVED" != "$(readlink -f -- "$PANEL_BOT_SOURCE" 2>/dev/null || true)" ]; then
        rsync -a --delete \
            --exclude='.git/' \
            --exclude='config.php' \
            --exclude='error_log' \
            --exclude='control-panel/' \
            --exclude='.codex-tmp-*/' \
            "$BOT_SOURCE_RESOLVED/" "$PANEL_BOT_SOURCE/"
    fi
    chown -R root:root "$PANEL_SOURCE" "$PANEL_BOT_SOURCE"
    find "$PANEL_SOURCE" "$PANEL_BOT_SOURCE" -type d -exec chmod 0755 {} \;
    find "$PANEL_SOURCE" "$PANEL_BOT_SOURCE" -type f -exec chmod 0644 {} \;
    chmod 0755 "$PANEL_SOURCE/install.sh" "$PANEL_SOURCE/host/mirza-panel-agent.py"
}

refresh_gateway() {
    if ! "$MANAGER" gateway-refresh >/var/log/mirza-panel-gateway.log 2>&1; then
        log "The panel is installed, but HTTPS routing is not ready. Check DNS and /var/log/mirza-panel-gateway.log."
        return 1
    fi
}

install_panel() {
    local existing_domain="" existing_port=""
    if [ -f "$PANEL_ENV" ]; then
        existing_domain=$(sed -n 's/^PANEL_DOMAIN=//p' "$PANEL_ENV" | tail -1)
        existing_port=$(sed -n 's/^APP_PORT=//p' "$PANEL_ENV" | tail -1)
    fi
    [ -n "$PANEL_DOMAIN" ] || PANEL_DOMAIN="$existing_domain"
    if [ -z "$PANEL_DOMAIN" ]; then
        printf '  Management panel domain: '
        read -r PANEL_DOMAIN
    fi
    PANEL_DOMAIN="${PANEL_DOMAIN,,}"
    valid_domain "$PANEL_DOMAIN" || fail "Invalid panel domain."
    valid_username "$PANEL_USERNAME" || fail "Invalid admin username."
    ensure_prerequisites
    resolve_bot_source
    if [ -z "$PANEL_PASSWORD" ]; then
        PANEL_PASSWORD="M$(openssl rand -hex 10)9"
    fi
    [ "${#PANEL_PASSWORD}" -ge 12 ] || fail "The admin password must contain at least 12 characters."
    [ -n "$PANEL_PORT" ] || PANEL_PORT="$existing_port"
    if [ -z "$PANEL_PORT" ]; then
        PANEL_PORT=$(allocate_port) || fail "No free panel port was found."
    fi
    [[ "$PANEL_PORT" =~ ^[0-9]{4,5}$ ]] || fail "Invalid application port."
    if grep -Rqx "DOMAIN=$PANEL_DOMAIN" /opt/mirza/instances/*/.env 2>/dev/null; then
        fail "This domain is already assigned to a customer bot."
    fi
    log "Copying the management panel and the current bot source..."
    sync_sources
    write_agent_config
    write_compose

    log "Building the isolated web panel..."
    docker_compose --env-file "$PANEL_ENV" -f "$PANEL_COMPOSE" up -d --build
    systemctl restart mirza-panel-agent.service

    local ready=0
    for _ in {1..40}; do
        if docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' mirza-control-panel 2>/dev/null | grep -Eq 'healthy|running'; then
            ready=1
            break
        fi
        sleep 2
    done
    [ "$ready" -eq 1 ] || fail "The management panel container did not become ready."

    if ! printf '%s' "$PANEL_PASSWORD" | docker_compose --env-file "$PANEL_ENV" -f "$PANEL_COMPOSE" exec -T panel php /var/www/app/cli/init.php "$PANEL_USERNAME" | grep -q ADMIN_READY; then
        fail "The administrator account could not be initialized."
    fi
    chown -R 33:33 "$PANEL_DATA"
    chmod 0770 "$PANEL_DATA"
    find "$PANEL_DATA" -type f -exec chmod 0660 {} \;
    refresh_gateway || true
    if [ -n "$SOURCE_TEMP_DIR" ] && [ -d "$SOURCE_TEMP_DIR" ]; then
        rm -rf "$SOURCE_TEMP_DIR"
    fi

    printf '\n'
    log "Installation completed."
    printf '  URL:      https://%s\n' "$PANEL_DOMAIN"
    printf '  Username: %s\n' "$PANEL_USERNAME"
    printf '  Password: %s\n' "$PANEL_PASSWORD"
    printf '\n  Store this password now; it is not saved in plaintext.\n\n'
}

status_panel() {
    ensure_prerequisites
    printf 'Panel container: '
    docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' mirza-control-panel 2>/dev/null || printf 'not installed\n'
    printf 'Host agent:     '
    systemctl is-active mirza-panel-agent.service 2>/dev/null || true
    if [ -f "$PANEL_ENV" ]; then
        printf 'Domain:         https://%s\n' "$(sed -n 's/^PANEL_DOMAIN=//p' "$PANEL_ENV" | tail -1)"
    fi
}

reset_password() {
    [ -f "$PANEL_COMPOSE" ] || fail "The panel is not installed."
    valid_username "$PANEL_USERNAME" || fail "Invalid admin username."
    if [ -z "$PANEL_PASSWORD" ]; then
        PANEL_PASSWORD="M$(openssl rand -hex 10)9"
    fi
    [ "${#PANEL_PASSWORD}" -ge 12 ] || fail "The admin password must contain at least 12 characters."
    printf '%s' "$PANEL_PASSWORD" | docker_compose --env-file "$PANEL_ENV" -f "$PANEL_COMPOSE" exec -T panel php /var/www/app/cli/init.php "$PANEL_USERNAME" | grep -q ADMIN_READY \
        || fail "Password reset failed."
    log "Password reset completed."
    printf '  Username: %s\n  Password: %s\n' "$PANEL_USERNAME" "$PANEL_PASSWORD"
}

remove_panel() {
    [ -f "$PANEL_COMPOSE" ] || fail "The panel is not installed."
    if [ "$ASSUME_YES" != "1" ]; then
        printf '  Type REMOVE-PANEL to continue: '
        local answer
        read -r answer
        [ "$answer" = "REMOVE-PANEL" ] || fail "Cancelled."
    fi
    install -d -m 0700 /opt/mirza/backups/control-panel
    if [ -f "$PANEL_DATA/panel.sqlite" ]; then
        cp -a "$PANEL_DATA/panel.sqlite" "/opt/mirza/backups/control-panel/panel_$(date +%Y%m%d_%H%M%S).sqlite"
    fi
    docker_compose --env-file "$PANEL_ENV" -f "$PANEL_COMPOSE" down --remove-orphans || true
    systemctl disable --now mirza-panel-agent.service >/dev/null 2>&1 || true
    rm -f "$AGENT_SERVICE" "$AGENT_CONFIG" /usr/local/lib/mirza-panel-agent.py
    systemctl daemon-reload
    mv "$PANEL_ENV" "$PANEL_ENV.removed" 2>/dev/null || true
    "$MANAGER" gateway-refresh >/dev/null 2>&1 || true
    log "Web panel removed. Customer bots and panel backups were preserved."
}

parse_args "$@"
ensure_root
case "$MODE" in
    install) install_panel ;;
    status) status_panel ;;
    reset-password) reset_password ;;
    remove) remove_panel ;;
esac
