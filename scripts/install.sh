#!/usr/bin/env bash
#
# Coresmith Installer
# https://coresmith.io
#
# Copyright 2026 Monoven Ltd. All rights reserved.
# Licensed under the Elastic License v2 (ELv2).
#
# Usage:
#   curl -fsSL https://get.coresmith.io/install | sudo bash
#   sudo ./install.sh [OPTIONS]
#
# Options:
#   --domain <domain>          Domain for Coresmith (default: server IP)
#   --email <email>            Email for Let's Encrypt certificates
#   --db <sqlite|pgsql|mysql>  Database driver (default: sqlite)
#   --db-host <host>           Database host (pgsql/mysql)
#   --db-port <port>           Database port (pgsql/mysql)
#   --db-name <name>           Database name (pgsql/mysql)
#   --db-user <user>           Database user (pgsql/mysql)
#   --db-password <pass>       Database password (pgsql/mysql)
#   --version <x.y.z>          Install this release (default: v$CORESMITH_VERSION, the release this installer belongs to)
#   --branch <branch>          Install a branch instead of a release (development and CI)
#   --unattended               Never prompt; use defaults
#   --verbose                  Show command output
#   --help                     Show this message

set -Eeuo pipefail

LAST_STEP=""

readonly CORESMITH_VERSION="0.1.0"
readonly CORESMITH_REPO="${CORESMITH_REPO:-https://github.com/monoventech/coresmith.git}"
readonly CORESMITH_ROOT="/opt/coresmith"
readonly RELEASES_DIR="$CORESMITH_ROOT/releases"
readonly SHARED_DIR="$CORESMITH_ROOT/shared"
readonly INSTALL_DIR="$CORESMITH_ROOT/app"
readonly DATA_DIR="/var/lib/coresmith"
readonly UPDATES_DIR="$DATA_DIR/updates"
readonly ADDRESS_DIR="$DATA_DIR/address"
readonly APPS_DIR="/var/coresmith"
readonly CONFIG_DIR="/etc/coresmith"
readonly CADDY_SITE_DIR="/etc/caddy/coresmith"
readonly CADDY_SITE="$CADDY_SITE_DIR/panel.caddy"
readonly LEGACY_CADDY_SITE="/etc/caddy/panel.caddy"
readonly CADDY_ADMIN_DIR="/run/caddy-admin"
readonly CADDY_ADMIN_SOCKET="$CADDY_ADMIN_DIR/admin.sock"
readonly CADDY_ADMIN_TMPFILES="/etc/tmpfiles.d/coresmith-caddy.conf"
readonly REDIS_CONFIGS="/etc/redis/redis.conf /etc/redis.conf"
readonly SETUP_TOKEN_FILE="/var/lib/coresmith/setup-token"
readonly LOG_FILE="/var/log/coresmith-install.log"
readonly PHP_VERSION="8.5"

if [ -t 1 ]; then
    readonly RED=$'\033[0;31m' GREEN=$'\033[0;32m' YELLOW=$'\033[1;33m'
    readonly BLUE=$'\033[0;34m' CYAN=$'\033[0;36m' NC=$'\033[0m' BOLD=$'\033[1m'
else
    readonly RED='' GREEN='' YELLOW='' BLUE='' CYAN='' NC='' BOLD=''
fi

CORESMITH_BRANCH=""
INSTALL_VERSION=""
RELEASE_DIR=""
RELEASE_VERSION=""
PREVIOUS_RELEASE=""
DOMAIN=""
ACME_EMAIL=""
DB_DRIVER="sqlite"
DB_HOST="127.0.0.1"
DB_PORT=""
DB_NAME="coresmith"
DB_USER="coresmith"
DB_PASSWORD=""
DB_EXPLICITLY_SET=false
UNATTENDED=false
VERBOSE=false

PKG_MANAGER=""
SERVER_IP=""
SSH_PORT="22"
PANEL_HOST=""
PANEL_URL=""
OS_ID=""
OS_NAME=""
OS_VERSION=""

log() { printf '%s[coresmith]%s %s\n' "$CYAN" "$NC" "$1"; }
success() { printf '%s[✓]%s %s\n' "$GREEN" "$NC" "$1"; }
warn() { printf '%s[!]%s %s\n' "$YELLOW" "$NC" "$1"; }
error() { printf '%s[✗]%s %s\n' "$RED" "$NC" "$1" >&2; }
fatal() {
    error "$1"
    exit 1
}

run() {
    LAST_STEP="$*"

    if $VERBOSE; then
        "$@"
    else
        "$@" >>"$LOG_FILE" 2>&1
    fi

    local status=$?
    [ "$status" -eq 0 ] && LAST_STEP=""
    return "$status"
}

on_failure() {
    local status=$? step="${LAST_STEP:-$BASH_COMMAND}"
    error "The installer stopped (exit ${status}) at: ${step}"
    error "Details are in ${LOG_FILE}. Fix the cause, then run the installer again; it is safe to re-run."
}

usage() {
    sed -n '3,26p' "$0" | sed 's/^# \{0,1\}//' | sed "s/\\\$CORESMITH_VERSION/${CORESMITH_VERSION}/"
    exit 0
}

# ── Arguments ────────────────────────────────────────────────────────────────

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
        --domain)
            DOMAIN="${2:-}"
            shift 2
            ;;
        --email)
            ACME_EMAIL="${2:-}"
            shift 2
            ;;
        --db)
            DB_DRIVER="${2:-}"
            DB_EXPLICITLY_SET=true
            shift 2
            ;;
        --db-host)
            DB_HOST="${2:-}"
            shift 2
            ;;
        --db-port)
            DB_PORT="${2:-}"
            shift 2
            ;;
        --db-name)
            DB_NAME="${2:-}"
            shift 2
            ;;
        --db-user)
            DB_USER="${2:-}"
            shift 2
            ;;
        --db-password)
            DB_PASSWORD="${2:-}"
            shift 2
            ;;
        --version)
            INSTALL_VERSION="${2:-}"
            shift 2
            ;;
        --branch)
            CORESMITH_BRANCH="${2:-}"
            shift 2
            ;;
        --unattended)
            UNATTENDED=true
            shift
            ;;
        --verbose)
            VERBOSE=true
            shift
            ;;
        --help | -h) usage ;;
        *)
            fatal "Unknown option: $1. Run with --help to see the supported options."
            ;;
        esac
    done

    if [ -n "$INSTALL_VERSION" ] && [ -n "$CORESMITH_BRANCH" ]; then
        fatal "Pass --version or --branch, not both."
    fi

    if [ -n "$INSTALL_VERSION" ] && ! [[ "$INSTALL_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
        fatal "\"$INSTALL_VERSION\" is not a version like 1.2.3."
    fi

    case "$DB_DRIVER" in
    sqlite | pgsql | mysql) ;;
    *) fatal "Invalid database driver: $DB_DRIVER. Must be sqlite, pgsql, or mysql." ;;
    esac

    if [ -n "$DOMAIN" ] && ! [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$ ]]; then
        fatal "\"$DOMAIN\" is not a valid domain name."
    fi

    if [ -z "$DB_PORT" ]; then
        case "$DB_DRIVER" in
        pgsql) DB_PORT=5432 ;;
        mysql) DB_PORT=3306 ;;
        esac
    fi
}

# ── Pre-flight ───────────────────────────────────────────────────────────────

preflight() {
    log "Running pre-flight checks..."

    [ "$(id -u)" -eq 0 ] || fatal "This installer must be run as root. Use: sudo bash install.sh"
    [ "$(uname)" = "Linux" ] || fatal "Coresmith only supports Linux. Detected: $(uname)"
    command -v systemctl >/dev/null 2>&1 || fatal "systemd is required but was not found."
    [ -f /etc/os-release ] || fatal "Cannot detect the operating system: /etc/os-release is missing."

    # shellcheck disable=SC1091
    . /etc/os-release
    OS_ID="${ID:-unknown}"
    OS_NAME="${NAME:-$OS_ID}"
    OS_VERSION="${VERSION_ID:-unknown}"

    case "$OS_ID" in
    ubuntu)
        [[ "$OS_VERSION" =~ ^(22\.04|24\.04)$ ]] || warn "Ubuntu $OS_VERSION is not one of the supported releases (22.04, 24.04). Continuing anyway."
        PKG_MANAGER="apt"
        ;;
    debian)
        [[ "$OS_VERSION" =~ ^(11|12)$ ]] || warn "Debian $OS_VERSION is not one of the supported releases (11, 12). Continuing anyway."
        PKG_MANAGER="apt"
        ;;
    rocky | almalinux)
        [[ "$OS_VERSION" =~ ^(8|9) ]] || warn "$OS_NAME $OS_VERSION is not one of the supported releases (8.x, 9.x). Continuing anyway."
        PKG_MANAGER="dnf"
        ;;
    fedora) PKG_MANAGER="dnf" ;;
    amzn)
        if [ "$OS_VERSION" = "2" ]; then PKG_MANAGER="yum"; else PKG_MANAGER="dnf"; fi
        ;;
    *) fatal "Unsupported OS: $OS_ID. Supported: Ubuntu, Debian, Rocky, AlmaLinux, Fedora, Amazon Linux." ;;
    esac

    success "$OS_NAME $OS_VERSION (package manager: $PKG_MANAGER)"

    SERVER_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit } }')"
    [ -n "$SERVER_IP" ] || SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [ -n "$SERVER_IP" ] || fatal "Could not determine this server's IP address."
    success "Server IP: $SERVER_IP"

    local public_ip
    public_ip="$(curl -fsS -4 --max-time 5 https://ifconfig.me 2>/dev/null || curl -fsS -4 --max-time 5 https://api.ipify.org 2>/dev/null || true)"
    if [ -n "$public_ip" ] && [ "$public_ip" != "$SERVER_IP" ]; then
        log "Reachable from the internet as ${public_ip}, through NAT. Without --domain Coresmith answers on ${SERVER_IP}; point a domain at ${public_ip} to use it from outside."
    fi

    if command -v ss >/dev/null 2>&1; then
        local detected
        detected="$(ss -tlnp 2>/dev/null | awk '/sshd/ {print $4}' | sed 's/.*://' | head -1)"
        [ -n "$detected" ] && SSH_PORT="$detected"
    fi
    success "SSH port: $SSH_PORT"

    local ram_mb
    ram_mb="$(free -m | awk '/^Mem:/ {print $2}')"
    if [ "${ram_mb:-0}" -lt 900 ]; then
        warn "Only ${ram_mb}MB of RAM. Coresmith will run, but slowly. 2GB or more is recommended."
    else
        success "RAM: ${ram_mb}MB"
    fi

    local disk_gb
    disk_gb="$(df -P / | awk 'NR==2 {printf "%.0f", $4/1048576}')"
    [ "${disk_gb:-0}" -ge 5 ] || fatal "Only ${disk_gb}GB of disk space is free. Coresmith needs at least 5GB, and 10GB or more is recommended."
    if [ "${disk_gb:-0}" -lt 10 ]; then
        warn "Only ${disk_gb}GB of disk space is free. Coresmith recommends at least 10GB."
    else
        success "Disk: ${disk_gb}GB free"
    fi
}

# ── Prompts ──────────────────────────────────────────────────────────────────

banner() {
    printf '\n%s%s╔══════════════════════════════════════╗%s\n' "$BOLD" "$BLUE" "$NC"
    printf '%s%s║          Coresmith Installer         ║%s\n' "$BOLD" "$BLUE" "$NC"
    printf '%s%s║                v%-21s║%s\n' "$BOLD" "$BLUE" "$CORESMITH_VERSION" "$NC"
    printf '%s%s╚══════════════════════════════════════╝%s\n\n' "$BOLD" "$BLUE" "$NC"
}

interactive_prompts() {
    $UNATTENDED && return 0
    [ -t 0 ] || return 0

    banner

    if [ -z "$DOMAIN" ]; then
        printf 'Domain for Coresmith, or Enter to use %s%s%s: ' "$BOLD" "$SERVER_IP" "$NC"
        read -r DOMAIN
    fi

    if [ -n "$DOMAIN" ] && [ -z "$ACME_EMAIL" ]; then
        printf "Email for Let's Encrypt certificates: "
        read -r ACME_EMAIL
    fi

    if ! $DB_EXPLICITLY_SET; then
        printf '\n%sChoose a database:%s\n' "$BOLD" "$NC"
        printf '%s⚠  This cannot be changed after installation.%s\n\n' "$YELLOW" "$NC"
        printf '  %s1) SQLite%s (recommended): no daemon, no credentials, one file.\n' "$GREEN" "$NC"
        printf '  2) PostgreSQL: installed and configured for you.\n'
        printf '  3) MySQL: installed and configured for you.\n\n'
        printf '  Select [1/2/3] (default 1): '
        read -r choice
        case "${choice:-1}" in
        1) DB_DRIVER="sqlite" ;;
        2) DB_DRIVER="pgsql" ;;
        3) DB_DRIVER="mysql" ;;
        *)
            DB_DRIVER="sqlite"
            warn "Unrecognised choice. Using SQLite."
            ;;
        esac
        [ "$DB_DRIVER" = "pgsql" ] && DB_PORT="${DB_PORT:-5432}"
        [ "$DB_DRIVER" = "mysql" ] && DB_PORT="${DB_PORT:-3306}"
    fi
}

refuse_link() {
    local path

    for path in "$@"; do
        if [ -L "$path" ]; then
            fatal "${path} is a symbolic link, so the installer stopped rather than follow it as root. Put the real file or directory back, then run the installer again."
        fi
    done
}

refuse_linked_layout() {
    refuse_link "$RELEASES_DIR" "$SHARED_DIR" "$SHARED_DIR/.env" "$UPDATES_DIR" "$ADDRESS_DIR" "$DATA_DIR/database.sqlite" "$SETUP_TOKEN_FILE"
}

trust_repository() {
    git config --system --add safe.directory "$CORESMITH_REPO" 2>/dev/null || true
    if [ -d "$CORESMITH_REPO/.git" ]; then
        git config --system --add safe.directory "$CORESMITH_REPO/.git" 2>/dev/null || true
    fi
}

require_release_ref() {
    local ref="${CORESMITH_BRANCH:-v${INSTALL_VERSION:-$CORESMITH_VERSION}}" found=0

    trust_repository
    (cd / && sudo -u coresmith env GIT_TERMINAL_PROMPT=0 git ls-remote --exit-code "$CORESMITH_REPO" "refs/heads/${ref}" "refs/tags/${ref}") >/dev/null 2>&1 || found=$?

    case "$found" in
    0) ;;
    2) fatal "${CORESMITH_REPO} has no ${ref}, so nothing was changed. Pass --version <x.y.z> for a release it has, or --branch <name> for a branch." ;;
    *) fatal "Could not reach ${CORESMITH_REPO}, so nothing was changed. Check the repository is reachable, then run the installer again." ;;
    esac
}

valid_release_name() {
    local pattern='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?-[0-9a-f]{7}$'

    [[ "$1" =~ $pattern ]]
}

name_release() {
    local version commit name

    version="$(sudo -u coresmith cat "$1/VERSION" 2>/dev/null | tr -d '[:space:]' || true)"
    commit="$(sudo -u coresmith git -C "$1" rev-parse HEAD 2>/dev/null || true)"
    commit="${commit:-0000000}"
    name="${version:-0.0.0}-${commit:0:7}"

    valid_release_name "$name" || return 1
    RELEASE_DIR="$RELEASES_DIR/$name"
    RELEASE_VERSION="${version:-0.0.0}"
}

write_release_sentinel() {
    local dir="$1" tag="${2:-}" commit tag_json="null"

    commit="$(sudo -u coresmith git -C "$dir" rev-parse HEAD 2>/dev/null || true)"
    [ -z "$tag" ] || tag_json="\"${tag}\""

    sudo -u coresmith tee "$dir/.coresmith-release.json" >/dev/null <<JSON
{"schema":1,"version":"${RELEASE_VERSION:-0.0.0}","commit":"${commit}","tag":${tag_json},"signature":"unchecked","fingerprint":null,"built_at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)","built_by":"installer"}
JSON
}

switch_release() {
    local tmp="$CORESMITH_ROOT/.app-$$"

    PREVIOUS_RELEASE="$(readlink "$INSTALL_DIR" 2>/dev/null || true)"
    ln -sfn "$RELEASE_DIR" "$tmp"
    mv -Tf "$tmp" "$INSTALL_DIR"
    chown -h coresmith:coresmith "$INSTALL_DIR"
}

seal_and_switch_release() {
    local tag=""

    [ -n "$CORESMITH_BRANCH" ] || tag="v${INSTALL_VERSION:-$CORESMITH_VERSION}"
    write_release_sentinel "$RELEASE_DIR" "$tag"
    switch_release
}

prune_releases() {
    local entry name current="${RELEASE_DIR##*/}" previous="${PREVIOUS_RELEASE##*/}"

    if [ -z "$previous" ] || [ "$previous" = "$current" ]; then
        return 0
    fi

    for entry in "$RELEASES_DIR"/* "$RELEASES_DIR"/.[!.]*; do
        name="${entry##*/}"

        if [ -L "$entry" ] || [ ! -d "$entry" ] || [ "$name" = "$current" ] || [ "$name" = "$previous" ]; then
            continue
        fi

        rm -rf "$entry"
        log "Removed old release ${name}"
    done
}

adopt_legacy_layout() {
    if [ ! -d "$INSTALL_DIR" ] || [ -L "$INSTALL_DIR" ]; then
        return 0
    fi

    local item

    require_release_ref
    name_release "$INSTALL_DIR" ||
        fatal "The install in ${INSTALL_DIR} has a VERSION Coresmith can't name a release after. Put a version like 1.2.3 in ${INSTALL_DIR}/VERSION, then run the installer again."

    refuse_link "$RELEASES_DIR" "$SHARED_DIR"
    mkdir -p "$RELEASES_DIR" "$SHARED_DIR"
    chown coresmith:coresmith "$RELEASES_DIR" "$SHARED_DIR"

    if [ ! -f "$SHARED_DIR/.env" ] && [ -f "$INSTALL_DIR/.env" ]; then
        mv "$INSTALL_DIR/.env" "$SHARED_DIR/.env"
    fi

    if [ ! -d "$SHARED_DIR/storage" ] && [ -d "$INSTALL_DIR/storage" ]; then
        mv "$INSTALL_DIR/storage" "$SHARED_DIR/storage"
    fi

    rm -rf "$RELEASE_DIR"
    mv "$INSTALL_DIR" "$RELEASE_DIR"

    for item in storage .env; do
        if [ -e "$RELEASE_DIR/$item" ] && [ ! -L "$RELEASE_DIR/$item" ]; then
            mv "$RELEASE_DIR/$item" "$RELEASE_DIR/$item.legacy-$$"
        fi
    done

    ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
    ln -sfn "$SHARED_DIR/.env" "$RELEASE_DIR/.env"
    chown -h coresmith:coresmith "$RELEASE_DIR/storage" "$RELEASE_DIR/.env"

    write_release_sentinel "$RELEASE_DIR" ""
    switch_release

    log "Moved the existing install into ${RELEASE_DIR}; its settings and data are kept."
}

adopt_existing_install() {
    [ -f "$SHARED_DIR/.env" ] || return 0

    log "Existing installation found; keeping its settings unless this run overrides them."

    if [ -z "$DOMAIN" ]; then
        local url
        url="$(env_value APP_URL)"
        url="${url#*://}"
        DOMAIN="${url%%/*}"
    fi

    [ -n "$ACME_EMAIL" ] || ACME_EMAIL="$(env_value ACME_EMAIL)"

    if ! $DB_EXPLICITLY_SET; then
        DB_DRIVER="$(env_value DB_CONNECTION)"
        DB_DRIVER="${DB_DRIVER:-sqlite}"
        DB_EXPLICITLY_SET=true

        if [ "$DB_DRIVER" != "sqlite" ]; then
            DB_HOST="$(env_value DB_HOST)"
            DB_PORT="$(env_value DB_PORT)"
            DB_NAME="$(env_value DB_DATABASE)"
            DB_USER="$(env_value DB_USERNAME)"
        fi
    fi

    if [ "$DB_DRIVER" != "sqlite" ] && [ -z "$DB_PASSWORD" ]; then
        DB_PASSWORD="$(env_value DB_PASSWORD)"
    fi
}

ensure_database_password() {
    if [ "$DB_DRIVER" != "sqlite" ] && [ -z "$DB_PASSWORD" ]; then
        DB_PASSWORD="$(openssl rand -hex 16)"
        log "Database password generated and written to .env."
    fi
}

# ── Phase 1: packages ────────────────────────────────────────────────────────

install_system_packages() {
    log "Phase 1/8: installing system packages..."

    case "$PKG_MANAGER" in
    apt)
        export DEBIAN_FRONTEND=noninteractive
        run apt-get update -qq
        run apt-get install -y -qq ca-certificates curl gnupg lsb-release software-properties-common

        if [ "$OS_ID" = "ubuntu" ]; then
            run add-apt-repository -y ppa:ondrej/php
        elif [ "$OS_ID" = "debian" ]; then
            curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
            run dpkg -i /tmp/debsuryorg-archive-keyring.deb
            echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
                >/etc/apt/sources.list.d/sury-php.list
        fi

        run apt-get update -qq
        run apt-get install -y -qq \
            "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mbstring" \
            "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
            "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-gd" "php${PHP_VERSION}-sqlite3" \
            "php${PHP_VERSION}-redis" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-readline" \
            "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-mysql"

        run apt-get install -y -qq git curl wget unzip zip jq sqlite3 redis-server acl fail2ban
        curl -fsSL https://deb.nodesource.com/setup_24.x | run bash -
        run apt-get install -y -qq nodejs
        ;;
    dnf | yum)
        run "$PKG_MANAGER" install -y epel-release || true
        if [ "$PKG_MANAGER" = "dnf" ]; then
            # shellcheck disable=SC1083  # %{rhel} is an rpm macro, not brace expansion
            run dnf install -y "https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %{rhel}).rpm" || true
            run dnf module reset php -y || true
            run dnf module enable "php:remi-${PHP_VERSION}" -y || true
        fi
        run "$PKG_MANAGER" install -y \
            php-fpm php-cli php-mbstring php-xml php-curl php-zip php-bcmath \
            php-gd php-pdo php-redis php-intl php-readline php-pgsql php-mysqlnd
        run "$PKG_MANAGER" install -y git curl wget unzip zip jq sqlite redis acl fail2ban
        curl -fsSL https://rpm.nodesource.com/setup_24.x | run bash -
        run "$PKG_MANAGER" install -y nodejs
        ;;
    esac

    if ! command -v composer >/dev/null 2>&1; then
        curl -fsS https://getcomposer.org/installer -o /tmp/composer-setup.php
        run php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
        rm -f /tmp/composer-setup.php
    fi

    if ! command -v bun >/dev/null 2>&1; then
        run env BUN_INSTALL=/usr/local/lib/bun bash -c "curl -fsSL https://bun.sh/install | bash"
        ln -sf /usr/local/lib/bun/bin/bun /usr/local/bin/bun
    fi

    install_database_engine

    success "System packages installed"
}

install_database_engine() {
    case "$DB_DRIVER" in
    sqlite)
        local version major minor
        version="$(sqlite3 --version 2>/dev/null | awk '{print $1}')"
        major="${version%%.*}"
        minor="$(printf '%s' "$version" | cut -d. -f2)"

        if [ -z "$version" ] || [ "${major:-0}" -ne 3 ] || [ "${minor:-0}" -lt 45 ]; then
            log "Installing SQLite 3.45 or newer..."
            case "$PKG_MANAGER" in
            apt) run apt-get install -y -qq sqlite3 libsqlite3-dev ;;
            dnf | yum) run "$PKG_MANAGER" install -y sqlite sqlite-devel ;;
            esac
            version="$(sqlite3 --version | awk '{print $1}')"
        fi
        success "SQLite $version"
        ;;
    pgsql)
        if ! command -v psql >/dev/null 2>&1; then
            log "Installing PostgreSQL..."
            case "$PKG_MANAGER" in
            apt) run apt-get install -y -qq postgresql postgresql-client ;;
            dnf | yum)
                run "$PKG_MANAGER" install -y postgresql-server postgresql
                run postgresql-setup --initdb || true
                ;;
            esac
        fi
        run systemctl enable --now postgresql

        [ -n "$DB_PASSWORD" ] || fatal "PostgreSQL was selected but no password was supplied or generated."
        sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 ||
            sudo -u postgres psql -c "CREATE ROLE \"${DB_USER}\" WITH LOGIN PASSWORD '${DB_PASSWORD}';"
        sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 ||
            sudo -u postgres psql -c "CREATE DATABASE \"${DB_NAME}\" OWNER \"${DB_USER}\";"
        success "PostgreSQL database ${DB_NAME} ready"
        ;;
    mysql)
        if ! command -v mysql >/dev/null 2>&1; then
            log "Installing MySQL..."
            case "$PKG_MANAGER" in
            apt) run apt-get install -y -qq mysql-server mysql-client ;;
            dnf | yum) run "$PKG_MANAGER" install -y mysql-server mysql ;;
            esac
        fi
        run systemctl enable --now mysqld || run systemctl enable --now mysql || true

        [ -n "$DB_PASSWORD" ] || fatal "MySQL was selected but no password was supplied or generated."
        mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        mysql -u root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
        mysql -u root -e "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, TRIGGER, LOCK TABLES, EXECUTE, CREATE TEMPORARY TABLES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
        mysql -u root -e "FLUSH PRIVILEGES;"
        success "MySQL database ${DB_NAME} ready"
        ;;
    esac
}

# ── Phase 2: Caddy ───────────────────────────────────────────────────────────

caddy_new_enough() {
    local major minor

    read -r major minor <<<"$(caddy version 2>/dev/null | head -1 | sed -E 's/^v?([0-9]+)\.([0-9]+).*/\1 \2/')"
    [[ "$major" =~ ^[0-9]+$ && "$minor" =~ ^[0-9]+$ ]] || return 1
    [ "$major" -gt 2 ] || { [ "$major" -eq 2 ] && [ "$minor" -ge 8 ]; }
}

install_caddy() {
    log "Phase 2/8: installing Caddy..."

    if command -v caddy >/dev/null 2>&1 && caddy_new_enough; then
        success "Caddy already present: $(caddy version | head -1)"
        return 0
    fi

    case "$PKG_MANAGER" in
    apt)
        run apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' |
            gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
            >/etc/apt/sources.list.d/caddy-stable.list
        printf 'Package: caddy\nPin: origin dl.cloudsmith.io\nPin-Priority: 1001\n' >/etc/apt/preferences.d/caddy
        run apt-get update -qq
        run apt-get install -y -qq caddy
        ;;
    dnf | yum)
        run dnf install -y 'dnf-command(copr)' || true
        run dnf copr enable @caddy/caddy -y || true
        run "$PKG_MANAGER" install -y caddy
        ;;
    esac

    command -v caddy >/dev/null 2>&1 || fatal "Caddy did not install. Check $LOG_FILE."
    caddy_new_enough || fatal "Caddy $(caddy version | head -1) is too old: Coresmith needs 2.8 or newer. Check $LOG_FILE for where apt found it."
    success "Caddy installed: $(caddy version | head -1)"
}

# ── Phase 3: user and directories ────────────────────────────────────────────

create_user_and_dirs() {
    log "Phase 3/8: creating the coresmith user and directories..."

    id coresmith >/dev/null 2>&1 ||
        useradd --system --shell /bin/bash --home-dir /opt/coresmith --create-home coresmith

    refuse_link "$RELEASES_DIR" "$SHARED_DIR" "$UPDATES_DIR" "$ADDRESS_DIR"
    mkdir -p "$DATA_DIR" "$UPDATES_DIR" "$ADDRESS_DIR" "$RELEASES_DIR" "$SHARED_DIR" "$APPS_DIR"/{apps,logs,backups,scripts} \
        "$CONFIG_DIR"/env "$CONFIG_DIR"/deploy-keys /etc/caddy /var/log/caddy

    chown -R coresmith:coresmith "$DATA_DIR" "$APPS_DIR"
    chown coresmith:coresmith "$CORESMITH_ROOT" "$RELEASES_DIR" "$SHARED_DIR" "$UPDATES_DIR" "$ADDRESS_DIR"
    chmod 700 "$DATA_DIR"
    chmod 700 "$UPDATES_DIR" "$ADDRESS_DIR"
    chmod 755 "$APPS_DIR"

    # Application environment files and deploy keys are root-only.
    chown root:root "$CONFIG_DIR/env" "$CONFIG_DIR/deploy-keys"
    chmod 700 "$CONFIG_DIR/env" "$CONFIG_DIR/deploy-keys"


    success "User and directories created"
}

# ── Phase 4: application ─────────────────────────────────────────────────────

install_coresmith_app() {
    log "Phase 4/8: installing the Coresmith application..."

    # Git refuses to clone a directory owned by another user unless it is marked safe.
    trust_repository

    local ref="${CORESMITH_BRANCH:-v${INSTALL_VERSION:-$CORESMITH_VERSION}}" incoming

    incoming="$RELEASES_DIR/.incoming-$$"
    rm -rf "$incoming"
    cd /
    sudo -u coresmith git clone --quiet --depth 1 --branch "$ref" "$CORESMITH_REPO" "$incoming" ||
        fatal "Could not fetch ${ref} from ${CORESMITH_REPO}. Check the repository is reachable and has that release; to install a branch, pass --branch <name>."

    name_release "$incoming" || {
        rm -rf "$incoming"
        fatal "The VERSION file in ${ref} isn't a version like 1.2.3, so Coresmith can't name the release. Install a release with --version <x.y.z>, or fix VERSION on that branch, then run the installer again."
    }

    if [ -f "$RELEASE_DIR/.coresmith-release.json" ]; then
        rm -rf "$incoming"
    else
        rm -rf "$RELEASE_DIR"
        mv "$incoming" "$RELEASE_DIR"
    fi

    if [ ! -d "$SHARED_DIR/storage" ]; then
        mv "$RELEASE_DIR/storage" "$SHARED_DIR/storage"
    else
        rm -rf "$RELEASE_DIR/storage"
    fi

    ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
    ln -sfn "$SHARED_DIR/.env" "$RELEASE_DIR/.env"
    chown -h coresmith:coresmith "$RELEASE_DIR/storage" "$RELEASE_DIR/.env"
    chown -R coresmith:coresmith "$SHARED_DIR/storage"

    cd "$RELEASE_DIR"
    run sudo -u coresmith composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
    run sudo -u coresmith php artisan package:discover --ansi
    run sudo -u coresmith php artisan vendor:publish --tag=laravel-assets --ansi --force
    run sudo -u coresmith bun install --frozen-lockfile

    success "Application installed"
}

# ── Phase 5: environment ─────────────────────────────────────────────────────

env_value() {
    local file="$SHARED_DIR/.env"

    [ -f "$file" ] || return 0

    KEY="$1" awk '
        BEGIN { key = ENVIRON["KEY"] }
        index($0, key "=") == 1 { value = substr($0, length(key) + 2); gsub(/^"|"$/, "", value); print value; exit }
    ' "$file"
}

vault_key_is_valid() {
    local key

    key="$(env_value CORESMITH_VAULT_KEY)"
    [ -n "$key" ] && [ "$(printf '%s' "$key" | base64 -d 2>/dev/null | wc -c | tr -d ' ')" = "32" ]
}

ensure_env_secret() {
    [ -n "$(env_value "$1")" ] || update_env "$1" "$2"
}

update_env() {
    local key="$1" value="$2" file="$SHARED_DIR/.env" tmp

    refuse_link "$file"
    tmp="$(mktemp "$SHARED_DIR/.env.XXXXXX")"

    # Values reach awk through the environment, so none of it is ever read as a pattern.
    KEY="$key" VALUE="$value" awk '
        BEGIN { key = ENVIRON["KEY"]; value = ENVIRON["VALUE"]; replaced = 0 }
        index($0, key "=") == 1 { print key "=" value; replaced = 1; next }
        { print }
        END { if (!replaced) print key "=" value }
    ' "$file" >"$tmp" || {
        rm -f "$tmp"
        return 1
    }

    mv -f "$tmp" "$file"
}

secure_redis() {
    local config="" candidate password tmp

    password="$(env_value REDIS_PASSWORD)"

    for candidate in $REDIS_CONFIGS; do
        if [ -f "$candidate" ]; then
            config="$candidate"
            break
        fi
    done

    [ -n "$config" ] || fatal "Couldn't find Redis's configuration to give it a password."

    refuse_link "$config"
    tmp="$(mktemp "${config}.XXXXXX")"

    PASSWORD="$password" awk '
        /^[[:space:]]*#?[[:space:]]*requirepass[[:space:]]/ { next }
        { print }
        END { print "requirepass " ENVIRON["PASSWORD"] }
    ' "$config" >"$tmp"

    cat "$tmp" >"$config"
    rm -f "$tmp"

    run systemctl restart redis-server || run systemctl restart redis

    for _ in 1 2 3 4 5 6 7 8 9 10; do
        if [ "$(REDISCLI_AUTH="$password" redis-cli -p 6379 ping 2>/dev/null)" = "PONG" ]; then
            return 0
        fi

        sleep 1
    done

    fatal "Redis didn't accept Coresmith's new password. See journalctl -u redis-server or -u redis."
}

configure_environment() {
    log "Phase 5/8: configuring the environment..."

    cd "$RELEASE_DIR"
    [ -f "$SHARED_DIR/.env" ] || sudo -u coresmith cp "$RELEASE_DIR/.env.example" "$SHARED_DIR/.env"

    PANEL_HOST="${DOMAIN:-$SERVER_IP}"
    PANEL_URL="https://${PANEL_HOST}"

    update_env APP_NAME "Coresmith"
    update_env APP_ENV "production"
    update_env APP_DEBUG "false"
    update_env APP_URL "$PANEL_URL"
    update_env CORESMITH_CADDY_SITE "$CADDY_SITE"
    update_env CORESMITH_CADDY_ADMIN_SOCKET "$CADDY_ADMIN_SOCKET"
    update_env APP_TIMEZONE "UTC"

    update_env DB_CONNECTION "$DB_DRIVER"
    case "$DB_DRIVER" in
    sqlite)
        update_env DB_DATABASE "$DATA_DIR/database.sqlite"
        refuse_link "$DATA_DIR/database.sqlite"
        touch "$DATA_DIR/database.sqlite"
        chown coresmith:coresmith "$DATA_DIR/database.sqlite"
        chmod 600 "$DATA_DIR/database.sqlite"
        ;;
    pgsql | mysql)
        update_env DB_HOST "$DB_HOST"
        update_env DB_PORT "$DB_PORT"
        update_env DB_DATABASE "$DB_NAME"
        update_env DB_USERNAME "$DB_USER"
        update_env DB_PASSWORD "$DB_PASSWORD"
        ;;
    esac

    update_env REDIS_HOST "127.0.0.1"
    update_env REDIS_PORT "6379"
    ensure_env_secret REDIS_PASSWORD "$(openssl rand -hex 32)"
    secure_redis
    update_env QUEUE_CONNECTION "redis"
    update_env CACHE_STORE "redis"
    update_env SESSION_DRIVER "redis"
    update_env DB_QUEUE_CONNECTION "$DB_DRIVER"
    update_env DB_CACHE_CONNECTION "$DB_DRIVER"

    vault_key_is_valid || update_env CORESMITH_VAULT_KEY "$(openssl rand -base64 32)"
    update_env CORESMITH_SETUP_TOKEN_PATH "$SETUP_TOKEN_FILE"
    update_env CORESMITH_UPDATES_ENABLED "true"
    update_env CORESMITH_REPOSITORY "$CORESMITH_REPO"

    # A self-hosted panel is not a signup page. The owner account comes from the
    # setup token; everyone else is invited into a team.
    update_env CORESMITH_ALLOW_REGISTRATION "false"

    update_env BROADCAST_CONNECTION "reverb"
    update_env REVERB_APP_ID "coresmith"
    ensure_env_secret REVERB_APP_KEY "$(openssl rand -hex 16)"
    ensure_env_secret REVERB_APP_SECRET "$(openssl rand -hex 32)"
    update_env REVERB_HOST "127.0.0.1"
    update_env REVERB_PORT "6001"
    update_env REVERB_SCHEME "http"
    update_env VITE_REVERB_APP_KEY "$(env_value REVERB_APP_KEY)"
    update_env VITE_REVERB_HOST "$PANEL_HOST"
    update_env VITE_REVERB_PORT "443"
    update_env VITE_REVERB_SCHEME "https"

    [ -n "$ACME_EMAIL" ] && update_env ACME_EMAIL "$ACME_EMAIL"

    refuse_link "$SHARED_DIR/.env"
    chown coresmith:coresmith "$SHARED_DIR/.env"
    chmod 600 "$SHARED_DIR/.env"

    [ -n "$(env_value APP_KEY)" ] || sudo -u coresmith php artisan key:generate --force --no-interaction >/dev/null

    # The frontend bundle carries the Reverb host, so it is built after the env exists.
    run sudo -u coresmith bun run build

    sudo -u coresmith php artisan migrate --force --no-interaction >/dev/null
    run sudo -u coresmith php artisan config:cache
    run sudo -u coresmith php artisan route:cache
    run sudo -u coresmith php artisan view:cache

    rm -rf "$RELEASE_DIR/node_modules"
    seal_and_switch_release

    success "Environment configured"
}

# ── Phase 6: Caddy configuration ─────────────────────────────────────────────

configure_caddy() {
    log "Phase 6/8: configuring Caddy for Coresmith..."

    local fpm_sock="/run/php/coresmith-panel-fpm.sock"
    mkdir -p /run/php

    printf 'd %s 0750 caddy coresmith -\n' "$CADDY_ADMIN_DIR" >"$CADDY_ADMIN_TMPFILES"
    chmod 0644 "$CADDY_ADMIN_TMPFILES"
    run systemd-tmpfiles --create "$CADDY_ADMIN_TMPFILES"

    {
        printf '{\n'
        printf '    admin unix/%s|0222\n' "$CADDY_ADMIN_SOCKET"
        [ -n "$ACME_EMAIL" ] && printf '    email %s\n' "$ACME_EMAIL"
        printf '    on_demand_tls {\n        permission http http://127.0.0.1:8484/api/caddy/ask\n    }\n'
        printf '    log {\n        output file /var/log/caddy/access.log\n        format json\n    }\n'
        printf '}\n\n'
        printf 'import %s\n' "$CADDY_SITE"
    } >/etc/caddy/Caddyfile


    local pool_dir=""
    for dir in "/etc/php/${PHP_VERSION}/fpm/pool.d" /etc/php-fpm.d /etc/php/fpm/pool.d; do
        [ -d "$dir" ] && {
            pool_dir="$dir"
            break
        }
    done
    [ -n "$pool_dir" ] || {
        pool_dir=/etc/php-fpm.d
        mkdir -p "$pool_dir"
    }

    cat >"$pool_dir/coresmith-panel.conf" <<FPMCONF
[coresmith-panel]
user = coresmith
group = coresmith
listen = ${fpm_sock}
listen.owner = caddy
listen.group = caddy
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
pm.max_requests = 500
chdir = ${INSTALL_DIR}/public

php_admin_value[error_log] = /var/log/coresmith-fpm-error.log
php_admin_flag[log_errors] = on

; The panel drives servers over SSH in pure PHP, so it never needs to spawn processes.
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,dl,putenv
php_admin_value[open_basedir] = ${RELEASES_DIR}:${SHARED_DIR}:${DATA_DIR}:/tmp
FPMCONF

    mkdir -p "$CADDY_SITE_DIR"
    chown coresmith:caddy "$CADDY_SITE_DIR"
    chmod 2750 "$CADDY_SITE_DIR"
    sed -e "s|__ADDRESSES__|${PANEL_HOST}|" -e '/^__REDIRECTS__$/d' "$RELEASE_DIR/resources/caddy/panel.caddy" >"$CADDY_SITE"
    chown coresmith:caddy "$CADDY_SITE"
    chmod 0640 "$CADDY_SITE"
    rm -f "$LEGACY_CADDY_SITE"

    setfacl -P -m u:caddy:x "$CORESMITH_ROOT" "$RELEASES_DIR" "$RELEASE_DIR"
    setfacl -R -P -m u:caddy:rX "$RELEASE_DIR/public"

    mkdir -p /var/log/caddy
    chown -R caddy:caddy /var/log/caddy

    { sudo -u caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile; } >>"$LOG_FILE" 2>&1 ||
        fatal "The generated Caddy configuration is invalid. See ${LOG_FILE} for Caddy's explanation."

    success "Caddy configured for ${PANEL_HOST}"
}

# ── Phase 7: systemd ─────────────────────────────────────────────────────────

create_systemd_services() {
    log "Phase 7/8: creating systemd services..."

    mkdir -p /etc/systemd/system/caddy.service.d
    cat >/etc/systemd/system/caddy.service.d/coresmith.conf <<'UNIT'
[Service]
ExecStartPost=/bin/bash -c 'sleep 2 && grep -qs "\"apps\"" /etc/caddy/caddy-config.json && curl -fsS --unix-socket /run/caddy-admin/admin.sock -X POST http://localhost/load -H "Content-Type: application/json" -d @/etc/caddy/caddy-config.json || true'
UNIT

    cat >/etc/systemd/system/coresmith-horizon.service <<UNIT
[Unit]
Description=Coresmith Horizon Queue Worker
After=network.target redis.service

[Service]
Type=simple
User=coresmith
Group=coresmith
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=5
StandardOutput=append:/var/log/coresmith-horizon.log
StandardError=append:/var/log/coresmith-horizon.log

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=${SHARED_DIR}/storage ${DATA_DIR} ${APPS_DIR} ${CONFIG_DIR}
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true

[Install]
WantedBy=multi-user.target
UNIT

    cat >/etc/systemd/system/coresmith-reverb.service <<UNIT
[Unit]
Description=Coresmith Reverb WebSocket Server
After=network.target

[Service]
Type=simple
User=coresmith
Group=coresmith
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=6001
Restart=always
RestartSec=5
StandardOutput=append:/var/log/coresmith-reverb.log
StandardError=append:/var/log/coresmith-reverb.log

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=${SHARED_DIR}/storage ${DATA_DIR}
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true

[Install]
WantedBy=multi-user.target
UNIT

    cat >/etc/systemd/system/coresmith-scheduler.service <<UNIT
[Unit]
Description=Coresmith Scheduler

[Service]
Type=oneshot
User=coresmith
Group=coresmith
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/php artisan schedule:run --no-interaction
UNIT

    cat >/etc/systemd/system/coresmith-scheduler.timer <<'UNIT'
[Unit]
Description=Run the Coresmith scheduler every minute

[Timer]
OnCalendar=*:0/1
Persistent=true

[Install]
WantedBy=timers.target
UNIT

    cat >/etc/systemd/system/coresmith-update.service <<UNIT
[Unit]
Description=Coresmith update, started from Settings
After=network-online.target redis-server.service
Wants=network-online.target

[Service]
Type=oneshot
User=coresmith
Group=coresmith
WorkingDirectory=${INSTALL_DIR}
ExecStartPre=-/bin/mv -f ${UPDATES_DIR}/request.json ${UPDATES_DIR}/request.taken.json
ExecStart=/usr/bin/php artisan coresmith:update --requested --no-interaction
TimeoutStartSec=2h
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
UNIT

    cat >/etc/systemd/system/coresmith-update.path <<UNIT
[Unit]
Description=Start a Coresmith update when one is requested

[Path]
PathExists=${UPDATES_DIR}/request.json
Unit=coresmith-update.service

[Install]
WantedBy=multi-user.target
UNIT

    cat >/etc/systemd/system/coresmith-address.service <<UNIT
[Unit]
Description=Coresmith address change, started from Settings
After=network-online.target redis-server.service caddy.service
Wants=network-online.target

[Service]
Type=oneshot
User=coresmith
Group=coresmith
WorkingDirectory=${INSTALL_DIR}
ExecStartPre=-/bin/mv -f ${ADDRESS_DIR}/request.json ${ADDRESS_DIR}/request.taken.json
ExecStart=/usr/bin/php artisan coresmith:address --no-interaction
TimeoutStartSec=10min
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${CADDY_SITE_DIR}
UNIT

    cat >/etc/systemd/system/coresmith-address.path <<UNIT
[Unit]
Description=Change Coresmith's address when one is requested

[Path]
PathExists=${ADDRESS_DIR}/request.json
Unit=coresmith-address.service

[Install]
WantedBy=multi-user.target
UNIT

    systemctl daemon-reload
    success "Systemd services created"
}

# ── Phase 8: start, harden, finish ───────────────────────────────────────────

configure_firewall() {
    if command -v ufw >/dev/null 2>&1; then
        if ufw status 2>/dev/null | grep -q '^Status: active'; then
            run ufw allow 80/tcp || true
            run ufw allow 443/tcp || true
            success "Firewall (ufw) was already on: opened 80 and 443 and left its SSH rules as they were"
        else
            run ufw allow "${SSH_PORT}/tcp" || true
            run ufw allow 80/tcp || true
            run ufw allow 443/tcp || true
            run ufw --force enable || true
            success "Firewall configured (ufw), SSH kept open on ${SSH_PORT}"
        fi
    elif command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        run firewall-cmd --permanent --add-service=http || true
        run firewall-cmd --permanent --add-service=https || true
        run firewall-cmd --reload || true
        success "Firewall (firewalld) was already on: opened 80 and 443 and left its SSH rules as they were"
    else
        warn "No firewall is running. Open 80 and 443, and keep ${SSH_PORT} open, before exposing this host."
    fi
}

configure_fail2ban() {
    command -v fail2ban-client >/dev/null 2>&1 || {
        warn "fail2ban is not installed; skipping brute-force protection."
        return 0
    }

    cat >/etc/fail2ban/filter.d/coresmith-login.conf <<'F2BFILTER'
[Definition]
failregex = "request":\{.*"method":"POST".*"uri":"/login".*"status":(401|422).*"client_ip":"<HOST>"
F2BFILTER

    cat >/etc/fail2ban/jail.d/coresmith.conf <<'F2BJAIL'
[coresmith-login]
enabled = true
port = http,https
filter = coresmith-login
logpath = /var/log/caddy/coresmith-panel.log
maxretry = 5
bantime = 3600
findtime = 600
F2BJAIL

    run systemctl enable --now fail2ban || true
    run systemctl restart fail2ban || true
    success "fail2ban is watching Coresmith sign-ins"
}

wait_for_service() {
    local service="$1" previous="${2:-}" waited=0

    until { systemctl is-active --quiet "$service" && [ "$(systemctl show -p MainPID --value "$service")" != "$previous" ]; } || [ "$waited" -ge 60 ]; do
        sleep 1
        waited=$((waited + 1))
    done

    if ! systemctl is-active --quiet "$service"; then
        error "$service did not start. Inspect it with: journalctl -u $service -n 50"
        return 1
    fi

    if [ -n "$previous" ] && [ "$(systemctl show -p MainPID --value "$service")" = "$previous" ]; then
        log "$service is finishing its current jobs and moves to the new version once they are done"
    else
        success "$service is running"
    fi
}

finalize() {
    log "Phase 8/8: starting services..."

    run systemctl enable --now redis-server || run systemctl enable --now redis || true
    run systemctl restart "php${PHP_VERSION}-fpm" || run systemctl restart php-fpm || true
    run systemctl enable caddy
    run systemctl restart caddy
    run systemctl enable coresmith-horizon coresmith-reverb

    local horizon_pid=""

    if systemctl is-active --quiet coresmith-horizon; then
        horizon_pid="$(systemctl show -p MainPID --value coresmith-horizon)"
        run sudo -u coresmith php "$INSTALL_DIR/artisan" horizon:terminate
    else
        run systemctl start coresmith-horizon
    fi

    run systemctl restart coresmith-reverb
    run systemctl enable --now coresmith-scheduler.timer
    run systemctl enable --now coresmith-update.path
    run systemctl enable --now coresmith-address.path

    configure_firewall
    configure_fail2ban

    local healthy=true
    wait_for_service caddy || healthy=false
    wait_for_service coresmith-horizon "$horizon_pid" || healthy=false
    wait_for_service coresmith-reverb || healthy=false

    if $healthy; then
        prune_releases
    fi

    local setup_token="" setup_output="" already_set_up=false

    refuse_link "$SETUP_TOKEN_FILE"
    install -o coresmith -g coresmith -m 600 /dev/null "$SETUP_TOKEN_FILE"
    setup_output="$(cd "$INSTALL_DIR" && sudo -u coresmith php artisan coresmith:setup-token --force 2>&1)" || true

    if printf '%s\n' "$setup_output" | grep -q 'already has an account'; then
        already_set_up=true
        rm -f "$SETUP_TOKEN_FILE"
    elif printf '%s\n' "$setup_output" | tail -1 | grep -qE '^[A-Za-z0-9]{64}$'; then
        setup_token="$(printf '%s\n' "$setup_output" | tail -1)"
    else
        printf '%s\n' "$setup_output" >>"$LOG_FILE"
        warn "Could not issue a setup token. Run this on the server, then open ${PANEL_URL}/install?token= followed by the token it prints:"
        warn "  cd ${INSTALL_DIR} && sudo -u coresmith php artisan coresmith:setup-token --force"
    fi

    print_summary "$setup_token" "$healthy" "$already_set_up"
}

print_summary() {
    local setup_token="$1" healthy="$2" already_set_up="${3:-false}"

    printf '\n%s%s╔══════════════════════════════════════════════════╗%s\n' "$BOLD" "$GREEN" "$NC"
    printf '%s%s║        Coresmith installed successfully!         ║%s\n' "$BOLD" "$GREEN" "$NC"
    printf '%s%s╚══════════════════════════════════════════════════╝%s\n\n' "$BOLD" "$GREEN" "$NC"

    printf '  %sAddress:%s      %s\n' "$BOLD" "$NC" "$PANEL_URL"
    printf '  %sDatabase:%s     %s\n' "$BOLD" "$NC" "$DB_DRIVER"
    printf '  %sInstalled in:%s %s\n' "$BOLD" "$NC" "$RELEASE_DIR"
    printf '  %sData in:%s      %s\n\n' "$BOLD" "$NC" "$DATA_DIR"

    if [ -n "$setup_token" ]; then
        printf '  %s%s⚠  Finish setup now:%s\n\n' "$BOLD" "$YELLOW" "$NC"
        printf '  %s%s/install?token=%s%s\n\n' "$BOLD" "$PANEL_URL" "$setup_token" "$NC"
        printf '  %sThis link works once. After the owner account exists it is%s\n' "$YELLOW" "$NC"
        printf '  %scleared from the server and can never be used again.%s\n\n' "$YELLOW" "$NC"
    elif [ "$already_set_up" = "true" ]; then
        printf '  Coresmith is already set up. Sign in at %s%s%s\n\n' "$BOLD" "$PANEL_URL" "$NC"
    fi

    printf '  %sManaging this instance:%s\n' "$CYAN" "$NC"
    printf '    Status:  systemctl status caddy coresmith-horizon coresmith-reverb\n'
    printf '    Logs:    tail -f /var/log/coresmith-horizon.log\n'
    printf '    Health:  cd %s && sudo -u coresmith php artisan coresmith:health\n' "$INSTALL_DIR"
    printf '    Update:  cd %s && sudo -u coresmith php artisan coresmith:update\n\n' "$INSTALL_DIR"

    if [ "$healthy" != "true" ]; then
        warn "Some services did not start. Coresmith may be unreachable until they do."
    fi
}

# ── Main ─────────────────────────────────────────────────────────────────────

main() {
    parse_args "$@"
    preflight
    refuse_linked_layout
    adopt_legacy_layout
    adopt_existing_install
    interactive_prompts
    ensure_database_password

    mkdir -p "$(dirname "$LOG_FILE")"
    exec > >(tee -a "$LOG_FILE") 2>&1
    trap on_failure ERR

    install_system_packages
    install_caddy
    create_user_and_dirs
    install_coresmith_app
    configure_environment
    configure_caddy
    create_systemd_services
    finalize
}

main "$@"
