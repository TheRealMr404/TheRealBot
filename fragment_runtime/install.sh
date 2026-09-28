#!/usr/bin/env bash
set -Eeuo pipefail

BOT_DIR=""
APP_ONLY=0
VENV_DIR="${MIRZA_FRAGMENT_VENV:-/opt/mirza/fragment-venv}"
PY_PACKAGE="fragment-api-py==12.1.0"

log() {
    printf '[fragment-runtime] %s\n' "$*"
}

fail() {
    printf '[fragment-runtime] ERROR: %s\n' "$*" >&2
    exit 1
}

retry() {
    local attempts="$1"
    shift
    local current=1
    until "$@"; do
        if [ "$current" -ge "$attempts" ]; then
            return 1
        fi
        log "Command failed; retrying ($current/$attempts)..."
        current=$((current + 1))
        sleep 3
    done
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --bot-dir)
            [ "$#" -ge 2 ] || fail "--bot-dir requires a value"
            BOT_DIR="$2"
            shift 2
            ;;
        --app-only)
            APP_ONLY=1
            shift
            ;;
        *)
            fail "Unknown argument: $1"
            ;;
    esac
done

[ -n "$BOT_DIR" ] || BOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
BOT_DIR="$(readlink -f -- "$BOT_DIR" 2>/dev/null || true)"
RUNTIME_DIR="$BOT_DIR/fragment_runtime"

[ -n "$BOT_DIR" ] && [ -f "$RUNTIME_DIR/fragment_worker.py" ] && [ -f "$RUNTIME_DIR/fragment_auth.py" ] \
    || fail "Fragment runtime files were not found under the selected bot directory"

apt_install() {
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

refresh_apt() {
    retry 3 apt-get update -o Acquire::Retries=3
}

python_supported() {
    "$1" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 10) else 1)' \
        >/dev/null 2>&1
}

select_python() {
    local candidate
    for candidate in python3.13 python3.12 python3.11 python3.10 python3; do
        if command -v "$candidate" >/dev/null 2>&1 && python_supported "$candidate"; then
            command -v "$candidate"
            return 0
        fi
    done
    return 1
}

install_python() {
    local selected=""
    selected="$(select_python 2>/dev/null || true)"
    if [ -n "$selected" ]; then
        printf '%s\n' "$selected"
        return 0
    fi

    if [ ! -r /etc/os-release ]; then
        fail "Python 3.10 or newer is required and the operating system could not be detected"
    fi
    # shellcheck disable=SC1091
    . /etc/os-release
    if [ "${ID:-}" != "ubuntu" ]; then
        fail "Python 3.10 or newer is required; install it and rerun this command"
    fi

    log "Installing Python 3.11..." >&2
    apt_install software-properties-common >&2
    add-apt-repository -y ppa:deadsnakes/ppa >&2
    refresh_apt >&2
    apt_install python3.11 python3.11-venv >&2
    command -v python3.11
}

ensure_venv_module() {
    local python_bin="$1" version package
    "$python_bin" -m venv --help >/dev/null 2>&1 && return 0
    version="$("$python_bin" -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')"
    package="python${version}-venv"
    log "Installing the Python venv module ($package)..."
    apt_install "$package" || fail "The venv module for Python $version could not be installed"
    "$python_bin" -m venv --help >/dev/null 2>&1 \
        || fail "Python $version is installed without a working venv module"
}

if [ "$APP_ONLY" -eq 0 ]; then
    [ "$(id -u)" -eq 0 ] || fail "Run this installer as root"
    log "Refreshing operating-system packages..."
    refresh_apt
    apt_install ca-certificates python3 python3-venv
    PYTHON_BIN="$(install_python)"
    ensure_venv_module "$PYTHON_BIN"
else
    PYTHON_BIN="$(select_python 2>/dev/null || true)"
    [ -n "$PYTHON_BIN" ] || fail "Python 3.10 or newer is not available in the container"
fi

if [ -x "$VENV_DIR/bin/python" ] && ! python_supported "$VENV_DIR/bin/python"; then
    log "Replacing the outdated Python environment..."
    rm -rf -- "$VENV_DIR"
fi

if [ ! -x "$VENV_DIR/bin/python" ]; then
    log "Creating the isolated Python environment..."
    mkdir -p "$(dirname "$VENV_DIR")"
    "$PYTHON_BIN" -m venv "$VENV_DIR"
fi

log "Installing the Fragment Python package..."
retry 3 "$VENV_DIR/bin/python" -m pip install \
    --disable-pip-version-check --no-cache-dir --retries 5 --timeout 60 \
    --upgrade pip setuptools wheel
retry 3 "$VENV_DIR/bin/python" -m pip install \
    --disable-pip-version-check --no-cache-dir --retries 5 --timeout 60 \
    "$PY_PACKAGE"

# Remove the retired Node OAuth package from installations upgraded from an
# earlier build. Authentication and session checks now share the Python runtime.
rm -rf -- "$RUNTIME_DIR/node_modules"

log "Verifying the installed runtime..."
"$VENV_DIR/bin/python" -c 'from FragmentAPI import FragmentClient'
"$VENV_DIR/bin/python" -c 'import pathlib, sys; [compile(pathlib.Path(item).read_text(encoding="utf-8"), item, "exec") for item in sys.argv[1:]]' \
    "$RUNTIME_DIR/fragment_auth.py" "$RUNTIME_DIR/fragment_worker.py"

if id www-data >/dev/null 2>&1; then
    chown -R www-data:www-data "$RUNTIME_DIR"
fi

log "Fragment dependencies installed successfully (Python $("$VENV_DIR/bin/python" --version 2>&1))."
