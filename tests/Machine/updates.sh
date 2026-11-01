#!/usr/bin/env bash
# Copyright 2026 Monoven Ltd. All rights reserved.
# Licensed under the Elastic License v2 (ELv2).
# See LICENSE file in the project root.

set -Eeuo pipefail

REPOSITORY="${1:-}"
BRANCH="installer-under-test"
ROOT="/opt/coresmith"
APP="$ROOT/app"
SHARED="$ROOT/shared"
UPDATES="/var/lib/coresmith/updates"
DATABASE="/var/lib/coresmith/database.sqlite"
FEED="/srv/feed"
KEYS="/srv/release-keys"
WORK=""
KEY=""
HOST=""
FEED_PID=""
SCENARIO="setting up"

usage() {
    cat <<USAGE
Install, update, roll back, recover and uninstall a real Coresmith panel, then report each result.

Usage: sudo bash tests/Machine/updates.sh <bare repository>

The panel must already be installed from that repository's ${BRANCH} branch, as the installer workflow does.
USAGE

    exit 1
}

ok() { printf 'ok %s\n' "$1"; }

fail() {
    printf 'not ok %s: %s\n' "$SCENARIO" "$1" >&2
    exit 1
}

diagnose() {
    local id

    printf '\n--- What the update wrote ---\n'
    id="$(jq -r '.id // empty' "$UPDATES/status.json" 2>/dev/null || true)"
    cat "$UPDATES/status.json" 2>/dev/null || true
    printf '\n'

    if [ -n "$id" ]; then
        tail -n 120 "$UPDATES/runs/$id.log" 2>/dev/null || true
    fi

    printf '\n--- journalctl -u coresmith-update ---\n'
    journalctl -u coresmith-update -n 80 --no-pager 2>/dev/null || true
    printf '\n--- laravel.log ---\n'
    tail -n 100 "$SHARED/storage/logs/laravel.log" 2>/dev/null || true
}

on_exit() {
    local status=$?

    if [ "$status" -ne 0 ]; then
        printf 'not ok %s (exit %s)\n' "$SCENARIO" "$status" >&2
        diagnose >&2
    fi

    if [ -n "$FEED_PID" ]; then
        kill "$FEED_PID" >/dev/null 2>&1 || true
    fi

    if [ -n "$KEY" ]; then
        gpgconf --kill all >/dev/null 2>&1 || true
    fi

    if [ -n "$WORK" ]; then
        rm -rf "$WORK"
    fi
}

as_panel() {
    (cd "$APP" && sudo -u coresmith -H "$@")
}

state() {
    jq -r "$1" "$UPDATES/status.json"
}

release() {
    readlink "$APP"
}

answers() {
    curl -sk -o /dev/null -w '%{http_code}' "https://$HOST$1"
}

migrations() {
    sqlite3 "$DATABASE" 'select count(*) from migrations'
}

wait_for() {
    local seconds="$1" waited=0

    shift

    until "$@"; do
        if [ "$waited" -ge "$seconds" ]; then
            return 1
        fi

        sleep 5
        waited=$((waited + 5))
    done
}

tag_release() {
    local version="$1" signing="$2" breaks="${3:-}" tree="$WORK/tree"

    rm -rf "$tree"
    git clone --quiet --branch "$BRANCH" "$REPOSITORY" "$tree"
    printf '%s\n' "$version" >"$tree/VERSION"

    if [ "$breaks" = "break" ]; then
        cat >"$tree/database/migrations/2099_01_01_000000_machine_test_breaks.php" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('machine test');
    }
};
PHP
    fi

    git -C "$tree" add --all
    git -C "$tree" -c user.name='Coresmith machine test' -c user.email='machine@example.invalid' -c commit.gpgSign=false commit --quiet -m "chore(release): v$version"

    if [ "$signing" = "signed" ]; then
        git -C "$tree" -c gpg.format=openpgp -c user.name='Coresmith machine test' -c user.email='machine@example.invalid' tag -s -u "$KEY" "v$version" -m "Release v$version"
    else
        git -C "$tree" -c tag.gpgSign=false -c user.name='Coresmith machine test' -c user.email='machine@example.invalid' tag -a "v$version" -m "Release v$version"
    fi

    git -C "$tree" push --quiet origin "refs/tags/v$version"
}

feed() {
    mkdir -p "$FEED/v1"
    printf '{"schema":1,"latest":"%s","minimum":"0.0.1","withdrawn":[],"changelog_url":"https://github.com/monoven/coresmith/releases/tag/v%s","summary":"A machine test release.","released_at":"%s","channel":"stable"}\n' \
        "$1" "$1" "$(date +%Y-%m-%d)" >"$FEED/v1/version"
}

write_as_panel() {
    sudo -u coresmith sh -c 'umask 077 && printf "%s" "$1" >"$2.tmp" && mv -f "$2.tmp" "$2"' sh "$1" "$2"
}

set_up() {
    [ -n "$REPOSITORY" ] || usage
    [ "$(id -u)" -eq 0 ] || fail "run this as root: sudo bash tests/Machine/updates.sh $REPOSITORY"
    [ -L "$APP" ] || fail "$APP isn't a release symlink. Install the panel from $REPOSITORY first."

    WORK="$(mktemp -d /tmp/csm.XXXXXX)"
    export GNUPGHOME="$WORK/g"
    mkdir -m 700 "$GNUPGHOME"

    gpg --batch --quiet --passphrase '' --quick-gen-key 'Coresmith machine test <machine@example.invalid>' ed25519 sign never 2>/dev/null
    KEY="$(gpg --with-colons --list-keys machine@example.invalid | awk -F: '$1 == "fpr" && !found { print $10; found = 1 }')"
    [ -n "$KEY" ] || fail "gpg made no signing key"

    mkdir -p "$KEYS"
    gpg --armor --export "$KEY" >"$KEYS/machine.asc"
    chmod 755 "$KEYS"
    chmod 644 "$KEYS/machine.asc"

    printf 'CORESMITH_RELEASE_KEYS_PATH=%s\nCORESMITH_UPDATE_CHECK_URL=http://127.0.0.1:8099/v1/version\n' "$KEYS" >>"$SHARED/.env"
    as_panel php artisan config:cache >/dev/null

    HOST="$(awk -F/ '/^APP_URL=/ { print $3 }' "$SHARED/.env")"
    [ -n "$HOST" ] || fail "APP_URL has no host"

    feed 0.1.0
    python3 -m http.server 8099 --bind 127.0.0.1 --directory "$FEED" >/dev/null 2>&1 &
    FEED_PID=$!
    wait_for 30 curl -fs -o /dev/null http://127.0.0.1:8099/v1/version || fail "the feed server didn't start"

    ok "the machine test is set up"
}

a_signed_update_is_installed() {
    local previous horizon output probe

    SCENARIO="a signed update is installed"
    tag_release 0.1.1 signed
    feed 0.1.1

    output="$(as_panel php artisan coresmith:check-update)"

    case "$output" in
    *"Coresmith v0.1.1 is available."*) ;;
    *) fail "check-update said: $output" ;;
    esac

    previous="$(release)"
    horizon="$(systemctl show -p MainPID --value coresmith-horizon)"

    as_panel php artisan coresmith:update --force || fail "the update exited $?"

    case "$(release)" in
    "$ROOT/releases/0.1.1-"*) ;;
    *) fail "the panel runs $(release)" ;;
    esac

    probe="$(curl -s http://127.0.0.1:8484/api/up/release | jq -r '.release // empty')"
    [ "$probe" = "$(basename "$(release)")" ] || fail "the running panel says it is ${probe:-nothing}"
    [ "$(answers /up)" = "200" ] || fail "/up answered $(answers /up)"
    [ "$(state .state)" = "succeeded" ] || fail "the status is $(state .state)"
    [ "$(state .signature)" = "verified" ] || fail "the signature is $(state .signature)"
    systemctl is-active --quiet coresmith-horizon || fail "Horizon isn't running"
    [ "$(systemctl show -p MainPID --value coresmith-horizon)" != "$horizon" ] || fail "Horizon wasn't restarted"
    [ -d "$previous" ] || fail "the previous release $previous is gone"
    [ ! -e "$SHARED/storage/framework/down" ] || fail "the panel is still in maintenance"

    ok "a signed update is installed"
}

a_broken_release_is_rolled_back() {
    local count running

    SCENARIO="a broken release is rolled back"
    count="$(migrations)"
    running="$(release)"
    tag_release 0.1.2 signed break
    feed 0.1.2

    if as_panel php artisan coresmith:update --force; then
        fail "the broken update exited 0"
    fi

    [ "$(release)" = "$running" ] || fail "the panel moved to $(release)"
    [ "$(migrations)" = "$count" ] || fail "the migrations went from $count to $(migrations)"
    [ "$(answers /up)" = "200" ] || fail "/up answered $(answers /up)"
    [ ! -e "$SHARED/storage/framework/down" ] || fail "the panel is still in maintenance"
    [ "$(state .state)" = "rolled_back" ] || fail "the status is $(state .state)"
    [ "$(state .failed_step)" = "migrate" ] || fail "the failed step is $(state .failed_step)"
    [ "$(sqlite3 "$DATABASE" "select count(*) from activity_log where description = 'panel_update_rolled_back'")" = "1" ] || fail "the rollback isn't in the activity log"

    ok "a broken release is rolled back"
}

an_unsigned_release_is_refused() {
    local running

    SCENARIO="an unsigned release is refused before anything changes"
    running="$(release)"
    tag_release 0.1.3 unsigned
    feed 0.1.3

    if as_panel php artisan coresmith:update --force; then
        fail "the unsigned update exited 0"
    fi

    [ "$(state .state)" = "aborted" ] || fail "the status is $(state .state)"
    [ "$(state .failed_step)" = "verify" ] || fail "the failed step is $(state .failed_step)"
    [ "$(release)" = "$running" ] || fail "the panel moved to $(release)"

    if compgen -G "$ROOT/releases/0.1.3-*" >/dev/null; then
        fail "the unsigned release was left in $ROOT/releases"
    fi

    ok "an unsigned release is refused before anything changes"
}

succeeded_on() {
    [ "$(state .state)" = "succeeded" ] && [ "$(state .to.version)" = "$1" ]
}

the_panel_request_starts_the_service() {
    local now

    SCENARIO="the panel's request starts the update service"
    tag_release 0.1.4 signed
    feed 0.1.4
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    write_as_panel "{\"schema\":1,\"to\":\"0.1.4\",\"trigger\":\"panel\",\"requested_by\":null,\"requested_at\":\"$now\"}" "$UPDATES/request.json"

    wait_for 900 succeeded_on 0.1.4 || fail "the requested update didn't finish: $(state .state)"
    [ "$(systemctl show coresmith-update.service --property=Result --value)" = "success" ] || fail "coresmith-update.service ended with $(systemctl show coresmith-update.service --property=Result --value)"
    [ ! -e "$UPDATES/request.json" ] || fail "request.json is still there"
    [ ! -e "$UPDATES/request.taken.json" ] || fail "request.taken.json is still there"

    ok "the panel's request starts the update service"
}

recovered() {
    [ "$(state .state)" = "aborted" ] && [ "$(answers /up)" = "200" ]
}

an_interrupted_update_recovers() {
    local now id

    SCENARIO="an interrupted update recovers on its own"
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    id="$(printf '01machine%017d' "$(date +%s)")"

    write_as_panel "{\"schema\":1,\"id\":\"$id\",\"state\":\"running\",\"trigger\":\"cli\",\"requested_by\":null,\"from\":{\"version\":\"0.1.4\",\"release\":\"$(basename "$(release)")\"},\"to\":{\"version\":\"0.1.5\",\"release\":null},\"step\":\"backup\",\"failed_step\":null,\"message\":null,\"signature\":\"verified\",\"markers\":{\"built\":null,\"maintenance\":true,\"backup\":null,\"migrating\":false,\"switched\":false,\"healthy\":false},\"started_at\":\"$now\",\"updated_at\":\"$now\",\"finished_at\":null}" "$UPDATES/status.json"
    as_panel php artisan down >/dev/null

    wait_for 180 recovered || fail "the panel didn't recover: the status is $(state .state) and /up answers $(answers /up)"

    ok "an interrupted update recovers on its own"
}

the_uninstaller_leaves_the_machine_clean() {
    SCENARIO="the uninstaller leaves the machine clean"

    (cd "$APP" && printf '%s\n' "$HOST" | php artisan coresmith:uninstall) || fail "the uninstaller exited $?"

    [ -z "$(systemctl list-unit-files 'coresmith-*' --no-legend 2>/dev/null)" ] || fail "units are left: $(systemctl list-unit-files 'coresmith-*' --no-legend)"

    for directory in "$ROOT" /var/lib/coresmith /etc/coresmith; do
        [ ! -e "$directory" ] || fail "$directory is still there"
    done

    if id coresmith >/dev/null 2>&1; then
        fail "the coresmith user is still there"
    fi

    systemctl is-active --quiet caddy || fail "Caddy isn't running"
    compgen -G '/root/coresmith-uninstalled-*/.env' >/dev/null || fail "no copy of the panel's .env was kept"

    ok "the uninstaller leaves the machine clean"
}

main() {
    trap on_exit EXIT
    set_up
    a_signed_update_is_installed
    a_broken_release_is_rolled_back
    an_unsigned_release_is_refused
    the_panel_request_starts_the_service
    an_interrupted_update_recovers
    the_uninstaller_leaves_the_machine_clean
}

main "$@"
