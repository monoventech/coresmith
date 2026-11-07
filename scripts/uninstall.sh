#!/usr/bin/env bash
# Copyright 2026 Monoven Ltd. All rights reserved.
# Licensed under the Elastic License v2 (ELv2).
# See LICENSE file in the project root.

set -Eeuo pipefail

CORESMITH_ROOT="/opt/coresmith"
SHARED_DIR="$CORESMITH_ROOT/shared"
DATA_DIR="/var/lib/coresmith"
APPS_DIR="/var/coresmith"
CONFIG_DIR="/etc/coresmith"
SYSTEMD_DIR="/etc/systemd/system"
CADDY_DROPIN="/etc/systemd/system/caddy.service.d/coresmith.conf"
CADDYFILE="/etc/caddy/Caddyfile"
PANEL_SITE="/etc/caddy/panel.caddy"
CADDY_CONFIG="/etc/caddy/caddy-config.json"
FPM_POOLS="/etc/php/*/fpm/pool.d /etc/php-fpm.d /etc/php/fpm/pool.d"
FPM_POOL="coresmith-panel.conf"
FAIL2BAN_JAIL="/etc/fail2ban/jail.d/coresmith.conf"
FAIL2BAN_FILTER="/etc/fail2ban/filter.d/coresmith-login.conf"
LOGS="/var/log/coresmith-*.log"
SAVE_ROOT="/root"
DEFAULT_REPOSITORY="https://github.com/monoventech/coresmith.git"
UNITS="coresmith-update.path coresmith-update.service coresmith-horizon.service coresmith-reverb.service coresmith-scheduler.timer coresmith-scheduler.service"

YES=false
PURGE=false
SAVED=""
APPS=""
REPOSITORY=""
DB_CONNECTION=""
DB_DATABASE=""

if [ -t 1 ]; then
    RED=$'\033[0;31m'
    GREEN=$'\033[0;32m'
    YELLOW=$'\033[1;33m'
    CYAN=$'\033[0;36m'
    NC=$'\033[0m'
else
    RED=""
    GREEN=""
    YELLOW=""
    CYAN=""
    NC=""
fi

log() { printf '%s[coresmith]%s %s\n' "$CYAN" "$NC" "$1"; }
success() { printf '%s[✓]%s %s\n' "$GREEN" "$NC" "$1"; }
warn() { printf '%s[!]%s %s\n' "$YELLOW" "$NC" "$1"; }

fatal() {
    printf '%s[✗]%s %s\n' "$RED" "$NC" "$1" >&2
    exit 1
}

usage() {
    cat <<USAGE
Remove Coresmith from this server.

Usage: sudo bash uninstall.sh [--yes] [--purge]

  --yes     Remove it without asking first
  --purge   Don't keep a copy of the panel's settings, backups and database
  --help    Show this message

Without --purge, the panel's .env, its backups and its SQLite database are saved under ${SAVE_ROOT} first.
Apps deployed on this machine keep running.
USAGE

    exit 0
}

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
        --yes) YES=true ;;
        --purge) PURGE=true ;;
        --help | -h) usage ;;
        *) fatal "Unknown option: $1. Run with --help to see the supported options." ;;
        esac

        shift
    done
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        fatal "This uninstaller must be run as root. Use: sudo bash uninstall.sh"
    fi
}

env_value() {
    local file="$SHARED_DIR/.env"

    [ -f "$file" ] || return 0

    KEY="$1" awk '
        BEGIN { key = ENVIRON["KEY"] }
        index($0, key "=") == 1 { value = substr($0, length(key) + 2); gsub(/^"|"$/, "", value); print value; exit }
    ' "$file"
}

read_install() {
    local app

    REPOSITORY="$(env_value CORESMITH_REPOSITORY)"
    DB_CONNECTION="$(env_value DB_CONNECTION)"
    DB_DATABASE="$(env_value DB_DATABASE)"

    for app in "$APPS_DIR"/apps/*; do
        if [ -e "$app" ]; then
            APPS="${APPS:+$APPS, }${app##*/}"
        fi
    done
}

give_storage_back() {
    local path

    if [ ! -d "$SHARED_DIR/storage" ] || ! id coresmith >/dev/null 2>&1; then
        return 0
    fi

    find "$SHARED_DIR/storage" -uid 0 -print0 2>/dev/null | while IFS= read -r -d '' path; do
        chown -h coresmith:coresmith "$path" || true
    done || true
}

confirm() {
    local answer=""

    if $YES; then
        return 0
    fi

    if [ -n "$APPS" ]; then
        printf 'This removes the Coresmith panel from this server: its services, its Caddy site, PHP-FPM pool and fail2ban jail, %s, %s and its logs.\n' "$CORESMITH_ROOT" "$DATA_DIR"
        printf 'Apps deployed here (%s) keep running, with %s, %s and the coresmith user.\n' "$APPS" "$APPS_DIR" "$CONFIG_DIR"
    else
        printf 'This removes the Coresmith panel from this server: its services, its Caddy site, PHP-FPM pool and fail2ban jail, %s, %s, its logs and the coresmith user.\n' "$CORESMITH_ROOT" "$DATA_DIR"
    fi

    if ! $PURGE; then
        printf 'A copy of its .env, backups and database is saved under %s first.\n' "$SAVE_ROOT"
    fi

    printf 'Type uninstall to remove Coresmith from this server: '
    read -r answer || true

    if [ "$answer" != "uninstall" ]; then
        give_storage_back
        fatal "Nothing was removed."
    fi
}

save_copy() {
    if $PURGE; then
        return 0
    fi

    SAVED="$SAVE_ROOT/coresmith-uninstalled-$(date +%Y%m%d-%H%M%S)"
    mkdir -p "$SAVED"
    chmod 700 "$SAVED"

    if [ -f "$SHARED_DIR/.env" ]; then
        cp -p "$SHARED_DIR/.env" "$SAVED/.env"
    fi

    if [ -d "$SHARED_DIR/storage/app/private/backups" ]; then
        cp -Rp "$SHARED_DIR/storage/app/private/backups" "$SAVED/backups"
    fi

    if [ -f "$DATA_DIR/database.sqlite" ]; then
        if command -v sqlite3 >/dev/null 2>&1; then
            sqlite3 "$DATA_DIR/database.sqlite" ".backup '$SAVED/database.sqlite'"
        else
            cp -p "$DATA_DIR/database.sqlite" "$SAVED/database.sqlite"
        fi
    fi

    success "Saved the panel's settings, backups and database to $SAVED"
}

stop_units() {
    local unit

    log "Stopping the panel's services..."

    for unit in $UNITS; do
        systemctl stop "$unit" >/dev/null 2>&1 || true
        systemctl disable "$unit" >/dev/null 2>&1 || true
    done
}

remove_units() {
    local unit

    for unit in $UNITS; do
        rm -f "$SYSTEMD_DIR/$unit"
    done

    if [ -z "$APPS" ]; then
        rm -f "$CADDY_DROPIN"
        rmdir "${CADDY_DROPIN%/*}" >/dev/null 2>&1 || true
    fi

    systemctl daemon-reload >/dev/null 2>&1 || true
    success "Removed the panel's services"
}

remove_caddy_site() {
    rm -f "$PANEL_SITE"

    if [ -z "$APPS" ]; then
        rm -f "$CADDY_CONFIG"
    fi

    if [ -f "$CADDYFILE" ] && grep -qF "import $PANEL_SITE" "$CADDYFILE"; then
        if [ -n "$SAVED" ]; then
            cp -p "$CADDYFILE" "$SAVED/Caddyfile"
        fi

        printf '{\n    admin localhost:2019\n}\n' >"$CADDYFILE"
    fi

    if [ -n "$APPS" ]; then
        systemctl restart caddy >/dev/null 2>&1 || warn "Caddy didn't restart. Run systemctl restart caddy so the apps' sites load."
    else
        systemctl reload caddy >/dev/null 2>&1 || systemctl restart caddy >/dev/null 2>&1 || true
    fi

    success "Removed the panel's Caddy site"
}

remove_fpm_pool() {
    local pool service version

    for pool in $FPM_POOLS; do
        if [ ! -f "$pool/$FPM_POOL" ]; then
            continue
        fi

        rm -f "${pool:?}/$FPM_POOL"
        service="php-fpm"

        case "$pool" in
        */php/[0-9]*/fpm/pool.d)
            version="${pool%/fpm/pool.d}"
            service="php${version##*/}-fpm"
            ;;
        esac

        systemctl restart "$service" >/dev/null 2>&1 || warn "Couldn't restart ${service}. Restart it so the panel's pool stops."
        success "Removed the panel's PHP-FPM pool"
    done
}

remove_fail2ban() {
    if [ ! -f "$FAIL2BAN_JAIL" ] && [ ! -f "$FAIL2BAN_FILTER" ]; then
        return 0
    fi

    rm -f "$FAIL2BAN_JAIL" "$FAIL2BAN_FILTER"
    systemctl restart fail2ban >/dev/null 2>&1 || true
    success "Removed the panel's fail2ban jail"
}

forget_safe_directories() {
    local value repository="${REPOSITORY:-$DEFAULT_REPOSITORY}"

    if ! command -v git >/dev/null 2>&1; then
        return 0
    fi

    for value in "$repository" "$repository/.git" "$CORESMITH_ROOT/app"; do
        git config --system --fixed-value --unset-all safe.directory "$value" >/dev/null 2>&1 || true
    done
}

remove_files() {
    local file

    rm -rf "${CORESMITH_ROOT:?}" "${DATA_DIR:?}"

    for file in $LOGS; do
        rm -f "$file"
    done

    if [ -n "$APPS" ]; then
        warn "Apps are still deployed on this machine under ${APPS_DIR}/apps (${APPS}). They are left running; remove them yourself if you no longer need them."
    else
        rm -rf "${APPS_DIR:?}" "${CONFIG_DIR:?}"
    fi

    success "Removed the panel's files"
}

remove_user() {
    local reason

    if [ -n "$APPS" ] || ! id coresmith >/dev/null 2>&1; then
        return 0
    fi

    if reason="$(userdel coresmith 2>&1)"; then
        success "Removed the coresmith user"
    else
        warn "Couldn't remove the coresmith user: ${reason}."
    fi
}

summary() {
    printf '\n'
    success "Coresmith is removed from this server."

    if [ -n "$SAVED" ]; then
        printf '  A copy of its .env, backups and database is in %s.\n' "$SAVED"
    fi

    case "$DB_CONNECTION" in
    pgsql) printf '  Its PostgreSQL database %s is still on its database server. Drop it if you no longer need it.\n' "$DB_DATABASE" ;;
    mysql) printf '  Its MySQL database %s is still on its database server. Drop it if you no longer need it.\n' "$DB_DATABASE" ;;
    esac

    if [ -n "$APPS" ]; then
        printf '  Kept for the apps still deployed here: %s, %s, %s, %s and the coresmith user.\n' "$APPS_DIR" "$CONFIG_DIR" "$CADDY_CONFIG" "$CADDY_DROPIN"
    fi

    printf '  Caddy, Redis, PHP, Node.js, Bun, Composer and any database server are still installed, as other software may use them.\n'
    printf '  Firewall rules for SSH, 80 and 443 are unchanged.\n'
    printf "  Servers this panel managed keep running. The panel's public key stays in their authorized_keys until you remove it.\n"
}

main() {
    parse_args "$@"
    require_root
    cd /
    read_install
    confirm
    save_copy
    stop_units
    remove_units
    remove_caddy_site
    remove_fpm_pool
    remove_fail2ban
    forget_safe_directories
    remove_files
    remove_user
    summary
}

main "$@"
