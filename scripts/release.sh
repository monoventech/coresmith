#!/usr/bin/env bash
# Copyright 2026 Monoven Ltd. All rights reserved.
# Licensed under the Elastic License v2 (ELv2).
# See LICENSE file in the project root.

set -Eeuo pipefail

if [ -t 1 ]; then
    RED=$'\033[31m'
    GREEN=$'\033[32m'
    YELLOW=$'\033[33m'
    CYAN=$'\033[36m'
    NC=$'\033[0m'
else
    RED=""
    GREEN=""
    YELLOW=""
    CYAN=""
    NC=""
fi

REMOTE="${CORESMITH_RELEASE_REMOTE:-origin}"
REPO="${CORESMITH_RELEASE_REPO:-}"
VERSION=""
YES=false
UNSIGNED=false
KEY=""
SHIPPED=""
BRANCH=""
START_BRANCH=""
PRE_RELEASE_HEAD=""
PREVIOUS=""
ENTRY=""
SIGNATURE_FILE=""
TMP=""
ARMED=false
TAG_CREATED=false
PUSHED=false
KEEP_TMP=false

log() { printf '%s[release]%s %s\n' "$CYAN" "$NC" "$1"; }
success() { printf '%s[✓]%s %s\n' "$GREEN" "$NC" "$1"; }
warn() { printf '%s[!]%s %s\n' "$YELLOW" "$NC" "$1"; }

fail() {
    printf '%s[✗]%s %s\n' "$RED" "$NC" "$1" >&2
    exit 1
}

usage() {
    local current latest

    current="$(tr -d '[:space:]' 2>/dev/null <VERSION || printf 'unknown')"
    latest="$(git describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null || printf 'none')"

    cat <<USAGE
Cut a Coresmith release: run every gate, bump the version, tag it and publish it.

Usage: ./scripts/release.sh <version> [--yes] [--unsigned]

  ./scripts/release.sh 1.0.2           A stable release from main, merging dev first
  ./scripts/release.sh 1.0.3-beta.1    A pre-release, tagged on dev with no merge
  ./scripts/release.sh 1.0.3           On dev, becomes the next 1.0.3-beta.N
  ./scripts/release.sh 1.0.3-rc.1      A release candidate

  --yes        Release without asking first
  --unsigned   Tag a stable release without a signature while no release key ships

Environment:
  CORESMITH_RELEASE_REMOTE   The remote to push to (default origin)
  CORESMITH_RELEASE_REPO     The GitHub repository, owner/name (default read from the remote)
  CORESMITH_SIGNING_KEY      The OpenPGP key to sign with (default git config user.signingkey)

Current version: ${current}
Latest tag: ${latest}
USAGE

    exit "$1"
}

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
        --yes) YES=true ;;
        --unsigned) UNSIGNED=true ;;
        --help | -h) usage 0 ;;
        -*) fail "Unknown option: $1. Run ./scripts/release.sh to see the options." ;;
        *)
            if [ -n "$VERSION" ]; then
                fail "Pass one version."
            fi

            VERSION="${1#v}"
            ;;
        esac

        shift
    done

    if [ -z "$VERSION" ]; then
        usage 1
    fi
}

prerelease() {
    case "$VERSION" in
    *-*) return 0 ;;
    *) return 1 ;;
    esac
}

next_beta() {
    local highest=0 tag number

    for tag in $(git tag --list "v$1-beta.*"); do
        number="${tag##*-beta.}"

        case "$number" in
        '' | *[!0-9]*) continue ;;
        esac

        if [ "$number" -gt "$highest" ]; then
            highest="$number"
        fi
    done

    printf '%s' "$((highest + 1))"
}

primary_fingerprints() {
    awk -F: '$1 == "pub" { want = 1; next } want && $1 == "fpr" { print $10; want = 0 }'
}

require_gpg() {
    if ! command -v gpg >/dev/null 2>&1; then
        fail "GnuPG (gpg) is required $1: https://gnupg.org"
    fi
}

read_shipped_keys() {
    local file fingerprints

    for file in resources/release-keys/*.asc; do
        if [ ! -f "$file" ]; then
            continue
        fi

        require_gpg "to read resources/release-keys"
        fingerprints="$(gpg --with-colons --show-keys "$file" 2>/dev/null | primary_fingerprints || true)"

        if [ -z "$fingerprints" ]; then
            fail "${file} isn't a public key gpg can read."
        fi

        SHIPPED="${SHIPPED}${fingerprints}"$'\n'
    done
}

signing_key() {
    if [ -n "${CORESMITH_SIGNING_KEY:-}" ]; then
        printf '%s' "$CORESMITH_SIGNING_KEY"

        return 0
    fi

    case "$(git config gpg.format 2>/dev/null || true)" in
    '' | openpgp) git config user.signingkey 2>/dev/null || true ;;
    esac
}

resolve_repository() {
    local url

    if [ -n "$REPO" ]; then
        return 0
    fi

    url="$(git config --get "remote.${REMOTE}.url" || true)"

    case "$url" in
    git@github.com:*) REPO="${url#git@github.com:}" ;;
    https://github.com/*) REPO="${url#https://github.com/}" ;;
    *) fail "Set CORESMITH_RELEASE_REPO to owner/name: ${REMOTE} doesn't point at GitHub." ;;
    esac

    REPO="${REPO%.git}"
}

resolve_signing() {
    local fingerprint

    KEY="$(signing_key)"
    read_shipped_keys

    if $UNSIGNED || [ -z "$KEY" ]; then
        if [ -n "$SHIPPED" ]; then
            fail "This release ships release keys, so every updater requires a signature. Configure the signing key, then release."
        fi
    fi

    if $UNSIGNED; then
        KEY=""
        warn "v${VERSION} will be unsigned. Updaters will report it as unchecked."

        return 0
    fi

    if [ -z "$KEY" ]; then
        if ! prerelease; then
            fail "No signing key is configured. Set one with git config user.signingkey <id>, or pass --unsigned to release v${VERSION} without a signature. Updaters will report it as unchecked."
        fi

        warn "No signing key is configured, so v${VERSION} is tagged without a signature."

        return 0
    fi

    require_gpg "to sign v${VERSION}"

    if [ -n "$SHIPPED" ]; then
        fingerprint="$(gpg --with-colons --list-keys "$KEY" 2>/dev/null | primary_fingerprints | awk 'NR == 1' || true)"

        if [ -z "$fingerprint" ] || ! printf '%s' "$SHIPPED" | grep -qxF "$fingerprint"; then
            fail "Your signing key isn't one of resources/release-keys, so every updater would reject v${VERSION}."
        fi
    fi
}

confirm() {
    local answer=""

    log "Releasing v${VERSION} from ${BRANCH} to ${REPO}."

    if $YES; then
        return 0
    fi

    printf 'Continue? [y/N] '
    read -r answer || true

    case "$answer" in
    y | Y | yes | Yes) ;;
    *)
        printf 'Aborted.\n'
        exit 0
        ;;
    esac
}

validate() {
    local semver='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?$'

    if ! [[ "$VERSION" =~ $semver ]]; then
        fail "Version '${VERSION}' is not valid semver. Use 1.0.2 or 1.0.2-beta.1."
    fi

    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        fail "The working tree has changes. Commit them first, then release."
    fi

    if ! command -v gh >/dev/null 2>&1; then
        fail "GitHub CLI (gh) is required: https://cli.github.com"
    fi

    if ! gh auth status >/dev/null 2>&1; then
        fail "gh is not signed in. Run gh auth login, then release."
    fi

    BRANCH="$(git rev-parse --abbrev-ref HEAD)"
    START_BRANCH="$BRANCH"

    if [ "$BRANCH" = "HEAD" ]; then
        fail "HEAD is detached. Check out main or dev, then release."
    fi

    if ! git fetch --quiet --tags "$REMOTE"; then
        fail "Couldn't fetch from ${REMOTE}. Check it is reachable, then release."
    fi

    case "$BRANCH" in
    main)
        if prerelease; then
            fail "Pre-releases can't be released from main. Switch to dev."
        fi
        ;;
    dev)
        if ! prerelease; then
            VERSION="${VERSION}-beta.$(next_beta "$VERSION")"
            warn "On dev a release is a pre-release, so this one is v${VERSION}."
        fi
        ;;
    *)
        if ! prerelease; then
            fail "Stable releases come from main. On '${BRANCH}', use a pre-release such as ${VERSION}-beta.1."
        fi
        ;;
    esac

    if git rev-parse -q --verify "refs/tags/v${VERSION}" >/dev/null; then
        fail "Tag v${VERSION} already exists."
    fi

    resolve_repository
    resolve_signing
    confirm
}

same_as_remote() {
    local mine theirs

    mine="$(git rev-parse -q --verify "refs/heads/$1" || true)"
    theirs="$(git rev-parse -q --verify "refs/remotes/${REMOTE}/$1" || true)"

    [ -n "$mine" ] && [ "$mine" = "$theirs" ]
}

merge_dev() {
    local branch file conflicts=""

    for branch in dev main; do
        if ! same_as_remote "$branch"; then
            fail "Local ${branch} differs from ${REMOTE}/${branch}. Pull or push it, then release."
        fi
    done

    PRE_RELEASE_HEAD="$(git rev-parse HEAD)"
    ARMED=true

    if [ "$(git rev-list --count "main..${REMOTE}/dev")" -gt 0 ]; then
        log "Merging dev into main..."

        if ! git merge --quiet --no-ff -m "chore(release): merge dev into main for v${VERSION}" "${REMOTE}/dev" >/dev/null 2>&1; then
            conflicts="$(git diff --name-only --diff-filter=U)"

            if [ -z "$conflicts" ] || printf '%s\n' "$conflicts" | grep -qvxE 'VERSION|scripts/install\.sh'; then
                git merge --abort >/dev/null 2>&1 || true
                fail "The merge from dev conflicts. Resolve it on main, then release."
            fi

            for file in $conflicts; do
                git checkout --theirs -- "$file"
                git add -- "$file"
            done

            git commit --quiet --no-edit
        fi

        success "Merged dev into main"
    fi

    PREVIOUS="$(git describe --tags --abbrev=0 --match 'v[0-9]*' --exclude '*-*' 2>/dev/null || true)"

    if [ -n "$PREVIOUS" ] && [ "$(git rev-list --count "${PREVIOUS}..HEAD")" -eq 0 ]; then
        fail "Nothing to release: main has no commits since ${PREVIOUS}."
    fi
}

gate() {
    log "Running $*"

    if ! "$@"; then
        fail "$* failed. Fix it on dev, then release."
    fi
}

gates() {
    gate ./vendor/bin/pint --test
    gate bun run lint:check
    gate bun run lint:sh
    gate bun run format:check
    gate bun run lint:tailwind
    gate bun run types:check
    gate php artisan test
    gate bun run e2e:refresh
    gate bun run e2e:install-refresh
    gate bun run test:e2e
    success "Every gate passed"
}

build() {
    local changed

    gate bun run build

    changed="$(git status --porcelain --untracked-files=no | awk '{ print $2 }' | paste -sd, - | sed 's/,/, /g')"

    if [ -n "$changed" ]; then
        fail "The build changed tracked files: ${changed}. Commit them on dev first."
    fi
}

bump() {
    local installer="${TMP}/install.sh"

    printf '%s\n' "$VERSION" >VERSION
    sed "s/^readonly CORESMITH_VERSION=\".*\"\$/readonly CORESMITH_VERSION=\"${VERSION}\"/" scripts/install.sh >"$installer"
    cat "$installer" >scripts/install.sh

    if [ "$(tr -d '[:space:]' <VERSION)" != "$VERSION" ]; then
        fail "VERSION wasn't updated to ${VERSION}."
    fi

    if ! grep -qxF "readonly CORESMITH_VERSION=\"${VERSION}\"" scripts/install.sh; then
        fail "The installer's version wasn't updated to ${VERSION}."
    fi

    success "Version bumped to ${VERSION}"
}

changelog() {
    local subject added="" fixed="" changed="" rewritten="${TMP}/CHANGELOG.md"

    ENTRY="## v${VERSION} ($(date +%Y-%m-%d))"

    if [ -z "$PREVIOUS" ]; then
        ENTRY="${ENTRY}"$'\n\n'"Initial release."
    else
        while IFS= read -r subject; do
            case "$subject" in
            '' | 'chore(release):'*) continue ;;
            feat*) added="${added}- ${subject}"$'\n' ;;
            fix*) fixed="${fixed}- ${subject}"$'\n' ;;
            *) changed="${changed}- ${subject}"$'\n' ;;
            esac
        done < <(
            git log --no-merges --pretty=format:%s "${PREVIOUS}..HEAD"
            printf '\n'
        )

        if [ -n "$added" ]; then
            ENTRY="${ENTRY}"$'\n\n### Added\n'"${added%$'\n'}"
        fi

        if [ -n "$fixed" ]; then
            ENTRY="${ENTRY}"$'\n\n### Fixed\n'"${fixed%$'\n'}"
        fi

        if [ -n "$changed" ]; then
            ENTRY="${ENTRY}"$'\n\n### Changed\n'"${changed%$'\n'}"
        fi
    fi

    if [ -f CHANGELOG.md ]; then
        {
            printf '# Changelog\n\n%s\n\n' "$ENTRY"
            sed '1{/^# Changelog$/d;}' CHANGELOG.md | sed '/./,$!d'
        } >"$rewritten"
    else
        printf '# Changelog\n\n%s\n' "$ENTRY" >"$rewritten"
    fi

    cat "$rewritten" >CHANGELOG.md
    success "Changelog written"
}

tag_and_push() {
    git add VERSION scripts/install.sh

    if [ -f CHANGELOG.md ]; then
        git add CHANGELOG.md
    fi

    git commit --quiet -m "chore(release): v${VERSION}"

    if [ -n "$KEY" ]; then
        git -c gpg.format=openpgp tag -s -u "$KEY" "v${VERSION}" -m "Release v${VERSION}"
        TAG_CREATED=true

        if ! git -c gpg.format=openpgp verify-tag "v${VERSION}" >/dev/null 2>&1; then
            fail "The signature on v${VERSION} didn't verify."
        fi

        SIGNATURE_FILE="${TMP}/install.sh.asc"
        gpg --batch --yes --armor --detach-sign --local-user "$KEY" --output "$SIGNATURE_FILE" scripts/install.sh
    else
        git -c tag.gpgSign=false tag -a "v${VERSION}" -m "Release v${VERSION}"
        TAG_CREATED=true
    fi

    log "Pushing ${BRANCH} and v${VERSION} to ${REMOTE}..."

    if ! git push --quiet --atomic "$REMOTE" "$BRANCH" "refs/tags/v${VERSION}"; then
        fail "${REMOTE} refused the push."
    fi

    PUSHED=true
    success "Pushed ${BRANCH} and v${VERSION}"
}

github_release() {
    local notes="${TMP}/notes.md" kind="--latest"

    if prerelease; then
        kind="--prerelease"
        printf 'Pre-release v%s.\n' "$VERSION" >"$notes"
    else
        printf '%s\n' "$ENTRY" | sed '1d' | sed '/./,$!d' >"$notes"
    fi

    set -- gh release create "v${VERSION}" --repo "$REPO" --title "v${VERSION}" --notes-file "$notes" --verify-tag "$kind" scripts/install.sh

    if [ -n "$SIGNATURE_FILE" ]; then
        set -- "$@" "$SIGNATURE_FILE"
    fi

    if ! "$@"; then
        KEEP_TMP=true
        warn "v${VERSION} is pushed, but the GitHub release wasn't created. Finish it with:"
        printf '  %s\n' "$*"
        exit 1
    fi

    success "Published https://github.com/${REPO}/releases/tag/v${VERSION}"
}

on_exit() {
    local status=$?

    if [ "$status" -ne 0 ] && $ARMED && ! $PUSHED; then
        if $TAG_CREATED; then
            git tag -d "v${VERSION}" >/dev/null 2>&1 || true
        fi

        git merge --abort >/dev/null 2>&1 || true
        git checkout --quiet "$BRANCH" >/dev/null 2>&1 || true
        git reset --quiet --hard "$PRE_RELEASE_HEAD" >/dev/null 2>&1 || true
        git checkout --quiet "$START_BRANCH" >/dev/null 2>&1 || true
        printf '%s[✗]%s Release v%s stopped. Nothing was pushed.\n' "$RED" "$NC" "$VERSION" >&2
    fi

    if [ -n "$TMP" ] && ! $KEEP_TMP; then
        rm -rf "$TMP"
    fi
}

main() {
    local root

    if ! root="$(git rev-parse --show-toplevel 2>/dev/null)"; then
        fail "Run ./scripts/release.sh inside the Coresmith repository."
    fi

    cd "$root"
    parse_args "$@"
    validate
    TMP="$(mktemp -d)"

    if prerelease; then
        PRE_RELEASE_HEAD="$(git rev-parse HEAD)"
        ARMED=true
    else
        merge_dev
    fi

    gates
    build
    bump

    if ! prerelease; then
        changelog
    fi

    tag_and_push
    github_release
}

trap on_exit EXIT

main "$@"
