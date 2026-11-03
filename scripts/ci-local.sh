#!/usr/bin/env bash
# Copyright 2026 Monoven Ltd. All rights reserved.
# Licensed under the Elastic License v2 (ELv2).
# See LICENSE file in the project root.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHARDS="${CI_SHARDS:-5}"
WORK="${CI_WORK_DIR:-${TMPDIR:-/tmp}/coresmith-ci}"
PG_PORT="${CI_PG_PORT:-5432}"
MYSQL_PORT="${CI_MYSQL_PORT:-3310}"
DB_NAME="${CI_DB_DATABASE:-coresmith_coord}"
DB_USER="${CI_DB_USERNAME:-coresmith}"
DB_PASS="${CI_DB_PASSWORD:-coresmith}"
FEED_PID=""

cd "$ROOT" || exit 1

say() {
    printf '\n== %s\n' "$1"
}

stop() {
    printf '\nCI failed: %s\n' "$1" >&2
    exit 1
}

gate() {
    local name="$1"
    shift

    if "$@" >"$WORK/logs/$name.log" 2>&1; then
        printf 'passed  %s\n' "$name"
    else
        tail -30 "$WORK/logs/$name.log" >&2
        stop "$name, full log at $WORK/logs/$name.log"
    fi
}

static_checks() {
    say "Static analysis"
    gate pint php vendor/bin/pint --config=pint.json --test app config database routes tests
    gate lint bun run lint:check
    gate shellcheck bun run lint:sh
    gate prettier bun run format:check
    gate tailwind bun run lint:tailwind
    gate types bun run types:check
    gate build bun run build
    git checkout --quiet -- public/service-worker.js public/manifest.json
}

pest_on() {
    local name="$1"
    shift

    if env "$@" php vendor/bin/pest --parallel >"$WORK/logs/pest-$name.log" 2>&1; then
        printf 'passed  %s  %s\n' "$name" "$(grep '^{"tool"' "$WORK/logs/pest-$name.log" | tail -1 | cut -c1-120)"
    else
        grep '^{"tool"' "$WORK/logs/pest-$name.log" | tail -1 | cut -c1-1500 >&2
        stop "Pest on $name, full log at $WORK/logs/pest-$name.log"
    fi
}

feature_tests() {
    say "Feature tests"
    pest_on sqlite APP_ENV=testing
    pest_on pgsql DB_CONNECTION=pgsql DB_HOST=127.0.0.1 "DB_PORT=$PG_PORT" "DB_DATABASE=$DB_NAME" "DB_USERNAME=$DB_USER" "DB_PASSWORD=$DB_PASS" DB_URL=
    pest_on mysql DB_CONNECTION=mysql DB_HOST=127.0.0.1 "DB_PORT=$MYSQL_PORT" "DB_DATABASE=$DB_NAME" "DB_USERNAME=$DB_USER" "DB_PASSWORD=$DB_PASS" DB_URL=
}

ports_for() {
    local base=$((8200 + $1 * 10))

    export E2E_PORT="$base"
    export E2E_INSTALL_PORT=$((base + 1))
    export E2E_CLOUD_PORT=$((base + 2))
    export E2E_STRIPE_PORT=$((base + 3))
    export CORESMITH_STRIPE_API_BASE="http://127.0.0.1:$E2E_STRIPE_PORT"
}

prepare_shard() {
    local shard="$1" dir="$WORK/shard-$1"

    rm -rf "$dir"
    mkdir -p "$dir/public"
    git ls-files -z | tar --null -T - -cf - | tar -xf - -C "$dir"
    cp -cR vendor "$dir/vendor" 2>/dev/null || cp -R vendor "$dir/vendor"
    ln -s "$ROOT/node_modules" "$dir/node_modules"
    cp -R public/build "$dir/public/build"
    cp .env "$dir/.env"

    (
        cd "$dir" || exit 1
        ports_for "$shard"
        touch database/e2e.sqlite database/e2e-install.sqlite database/e2e-cloud.sqlite
        php artisan migrate:all &&
            bun run e2e:refresh &&
            bun run e2e:install-refresh &&
            bun run e2e:cloud-refresh
    ) >"$WORK/logs/prepare-$shard.log" 2>&1
}

run_shard() {
    local shard="$1" dir="$WORK/shard-$1"

    cd "$dir" || exit 1
    ports_for "$shard"
    ./node_modules/.bin/playwright test "--shard=$shard/$SHARDS" --retries=2 --forbid-only --reporter=line >"$WORK/logs/browser-$shard.log" 2>&1
}

shard_summary() {
    tr '\r' '\n' <"$WORK/logs/browser-$1.log" | grep -E '^ +[0-9]+ (passed|failed|flaky|skipped|did not run)' | tr -s ' ' | tr '\n' ',' | sed 's/,$//'
}

browser_tests() {
    say "Browser tests, $SHARDS shards"

    [ -d public/build ] || gate build bun run build

    local shard failed=0 pids=()

    for shard in $(seq 1 "$SHARDS"); do
        prepare_shard "$shard" &
        pids+=("$!")
    done

    for shard in "${pids[@]}"; do
        wait "$shard" || failed=1
    done

    [ "$failed" -eq 0 ] || stop "preparing the shards, logs at $WORK/logs/prepare-*.log"

    bun tests/E2E/support/release-feed.ts >"$WORK/logs/feed.log" 2>&1 &
    FEED_PID="$!"

    pids=()

    for shard in $(seq 1 "$SHARDS"); do
        run_shard "$shard" &
        pids+=("$!")
    done

    for shard in $(seq 1 "$SHARDS"); do
        if wait "${pids[$((shard - 1))]}"; then
            printf 'passed  shard %s %s\n' "$shard" "$(shard_summary "$shard")"
        else
            failed=1
            printf 'FAILED  shard %s %s\n' "$shard" "$(shard_summary "$shard")"
            tr '\r' '\n' <"$WORK/logs/browser-$shard.log" | grep -E '^ +\[[a-z-]+\] › ' | sort -u | cut -c1-140
        fi
    done

    [ "$failed" -eq 0 ] || stop "browser tests, logs at $WORK/logs/browser-*.log, failures under $WORK/shard-*/test-results"
}

finish() {
    [ -z "$FEED_PID" ] || kill "$FEED_PID" 2>/dev/null
}

trap finish EXIT

[ -z "$(git status --porcelain)" ] || stop "commit your changes first, this checks what HEAD would push"

mkdir -p "$WORK/logs"

STAGES=("$@")
[ "${#STAGES[@]}" -gt 0 ] || STAGES=(static pest browser)

for stage in "${STAGES[@]}"; do
    case "$stage" in
    static) static_checks ;;
    pest) feature_tests ;;
    browser) browser_tests ;;
    *) stop "unknown stage $stage, use static, pest or browser" ;;
    esac
done

printf '\nCI passed at %s in %dm %ds\n' "$(git rev-parse --short HEAD)" $((SECONDS / 60)) $((SECONDS % 60))
