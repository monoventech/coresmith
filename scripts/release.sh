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
ACTION="prepare"
VERSION=""
YES=false
UNSIGNED=false
SKIP_GATES=false
OMIT=""
OMITTED=""
INCLUDED=""
PICKED=""
KEY=""
SHIPPED=""
START_BRANCH=""
RELEASE_BRANCH=""
ENTRY=""
INSTALLER=""
SIGNATURE_FILE=""
TMP=""
ARMED=false
SWITCHED=false
BRANCH_CREATED=false
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
Cut a Coresmith release. A stable release reaches main through a pull request, then publishing tags it.

Usage: ./scripts/release.sh <version> [--omit <commit>]... [--skip-gates] [--yes] [--unsigned]
       ./scripts/release.sh publish <version> [--yes] [--unsigned]

  ./scripts/release.sh 1.0.2                  Open a pull request into main with every commit dev has that main lacks
  ./scripts/release.sh 1.0.2 --omit 3f2a9c1   The same, leaving that commit on dev for a later release
  ./scripts/release.sh publish 1.0.2          Once the pull request is merged, tag it and publish the GitHub release
  ./scripts/release.sh 1.0.3-beta.1           A pre-release: tag dev as it is, with no pull request

  --omit <commit>  Leave one of dev's commits out of the release; repeat it for more
  --skip-gates     Skip the gates when the release is exactly dev and dev has just passed bun run ci
  --yes            Release without asking first
  --unsigned       Tag without a signature while no release key ships

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
    if [ "${1:-}" = "publish" ]; then
        ACTION="publish"
        shift
    fi

    while [ $# -gt 0 ]; do
        case "$1" in
        --yes) YES=true ;;
        --unsigned) UNSIGNED=true ;;
        --skip-gates) SKIP_GATES=true ;;
        --omit)
            if [ $# -lt 2 ] || [ -z "$2" ]; then
                fail "--omit needs a commit."
            fi

            OMIT="${OMIT}$2"$'\n'
            shift
            ;;
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

plural() {
    if [ "$1" -eq 1 ]; then
        printf '1 %s' "$2"
    else
        printf '%s %ss' "$1" "$2"
    fi
}

print_command() {
    printf ' '
    printf ' %q' "$@"
    printf '\n'
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
    local ref="$1" file fingerprints

    while IFS= read -r file; do
        case "$file" in
        *.asc) ;;
        *) continue ;;
        esac

        require_gpg "to read resources/release-keys"
        fingerprints="$(git show "${ref}:${file}" | gpg --with-colons --show-keys 2>/dev/null | primary_fingerprints || true)"

        if [ -z "$fingerprints" ]; then
            fail "${file} isn't a public key gpg can read."
        fi

        SHIPPED="${SHIPPED}${fingerprints}"$'\n'
    done < <(git ls-tree --name-only "$ref" resources/release-keys/ 2>/dev/null || true)
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
    SHIPPED=""
    read_shipped_keys "$1"

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

    log "$1"

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

valid_version() {
    local semver='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?$'

    [[ "$1" =~ $semver ]] && [ "${#1}" -le 63 ]
}

require_valid_version() {
    if valid_version "$VERSION"; then
        return 0
    fi

    if [ "${#VERSION}" -gt 63 ]; then
        fail "Version '${VERSION}' is longer than 63 characters, so installs would refuse its tag. Use a shorter one."
    fi

    fail "Version '${VERSION}' is not valid semver. Use 1.0.2 or 1.0.2-beta.1."
}

same_as_remote() {
    local mine theirs

    mine="$(git rev-parse -q --verify "refs/heads/$1" || true)"
    theirs="$(git rev-parse -q --verify "refs/remotes/${REMOTE}/$1" || true)"

    [ -n "$mine" ] && [ "$mine" = "$theirs" ]
}

validate() {
    require_valid_version

    if [ "$ACTION" = "publish" ] && prerelease; then
        fail "A pre-release is published as it is cut. Run ./scripts/release.sh ${VERSION}."
    fi

    if { [ "$ACTION" = "publish" ] || prerelease; } && { [ -n "$OMIT" ] || $SKIP_GATES; }; then
        fail "--omit and --skip-gates are for preparing a stable release."
    fi

    if [ "$ACTION" = "prepare" ] && [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        fail "The working tree has changes. Commit them first, then release."
    fi

    if ! command -v gh >/dev/null 2>&1; then
        fail "GitHub CLI (gh) is required: https://cli.github.com"
    fi

    if ! gh auth status >/dev/null 2>&1; then
        fail "gh is not signed in. Run gh auth login, then release."
    fi

    if [ "$ACTION" = "prepare" ]; then
        START_BRANCH="$(git rev-parse --abbrev-ref HEAD)"

        if [ "$START_BRANCH" = "HEAD" ]; then
            fail "HEAD is detached. Check out a branch, then release."
        fi
    fi

    if ! git fetch --quiet --tags "$REMOTE"; then
        fail "Couldn't fetch from ${REMOTE}. Check it is reachable, then release."
    fi

    if git rev-parse -q --verify "refs/tags/v${VERSION}" >/dev/null; then
        fail "Tag v${VERSION} already exists."
    fi

    RELEASE_BRANCH="release/v${VERSION}"

    if [ "$ACTION" = "prepare" ]; then
        if git rev-parse -q --verify "refs/heads/dev" >/dev/null && ! same_as_remote dev; then
            fail "Local dev differs from ${REMOTE}/dev. Pull or push it, then release."
        fi

        if ! prerelease && { git rev-parse -q --verify "refs/heads/${RELEASE_BRANCH}" >/dev/null || git ls-remote --exit-code --heads "$REMOTE" "$RELEASE_BRANCH" >/dev/null 2>&1; }; then
            fail "${RELEASE_BRANCH} already exists. Merge or close its pull request and delete the branch, then release."
        fi
    fi

    resolve_repository
}

shipped_commits() {
    git log --format=%B "${REMOTE}/main" | grep -oE 'cherry picked from commit [0-9a-f]{40}' | awk '{ print $5 }' || true
}

collect_commits() {
    local candidates="" shipped commit omitted full

    shipped="$(shipped_commits)"

    while IFS= read -r commit; do
        if [ -n "$commit" ] && ! printf '%s\n' "$shipped" | grep -qxF "$commit"; then
            candidates="${candidates}${commit}"$'\n'
        fi
    done < <(git rev-list --reverse --no-merges --cherry-pick --right-only "${REMOTE}/main...${REMOTE}/dev")

    candidates="${candidates%$'\n'}"

    if [ -z "$candidates" ]; then
        fail "Nothing to release: everything on ${REMOTE}/dev is already on main."
    fi

    while IFS= read -r omitted; do
        if [ -z "$omitted" ]; then
            continue
        fi

        full="$(git rev-parse -q --verify "${omitted}^{commit}" 2>/dev/null || true)"

        if [ -z "$full" ] || ! printf '%s\n' "$candidates" | grep -qxF "$full"; then
            fail "${omitted} isn't one of the commits ${REMOTE}/dev would release."
        fi

        OMITTED="${OMITTED}${full}"$'\n'
    done <<<"$OMIT"

    while IFS= read -r commit; do
        if ! printf '%s' "$OMITTED" | grep -qxF "$commit"; then
            INCLUDED="${INCLUDED}${commit}"$'\n'
        fi
    done <<<"$candidates"

    if [ -z "$INCLUDED" ]; then
        fail "Nothing is left to release once those commits are left out."
    fi
}

pick() {
    local commit="$1" conflicts file

    if git cherry-pick -x --empty=drop "$commit" >/dev/null 2>&1; then
        return 0
    fi

    conflicts="$(git diff --name-only --diff-filter=U)"

    if [ -z "$conflicts" ] && git diff --cached --quiet && git rev-parse -q --verify CHERRY_PICK_HEAD >/dev/null; then
        git cherry-pick --skip >/dev/null 2>&1 || true

        return 0
    fi

    if [ -n "$conflicts" ] && ! printf '%s\n' "$conflicts" | grep -qvxE 'VERSION|scripts/install\.sh'; then
        while IFS= read -r file; do
            git checkout --theirs -- "$file"
            git add -- "$file"
        done <<<"$conflicts"

        if GIT_EDITOR=true git cherry-pick --continue >/dev/null 2>&1; then
            return 0
        fi
    fi

    git cherry-pick --abort >/dev/null 2>&1 || true

    if [ -n "$OMITTED" ]; then
        fail "$(git log -1 --format='%h %s' "$commit") doesn't apply on main without the commits left out of this release. Leave it out too, or keep what it builds on."
    fi

    fail "$(git log -1 --format='%h %s' "$commit") doesn't apply on main. Merge main into dev with a pull request, then release."
}

pick_commits() {
    local commit before picked=0 left

    log "Picking dev's commits onto main..."

    while IFS= read -r commit; do
        if [ -z "$commit" ]; then
            continue
        fi

        before="$(git rev-parse HEAD)"
        pick "$commit"

        if [ "$(git rev-parse HEAD)" != "$before" ]; then
            PICKED="${PICKED}${commit}"$'\n'
            picked=$((picked + 1))
        fi
    done <<<"$INCLUDED"

    if [ "$picked" -eq 0 ]; then
        fail "Nothing to release: the changes in ${REMOTE}/dev's new commits are already on main."
    fi

    left="$(printf '%s' "$OMITTED" | grep -c . || true)"

    if [ "$left" -gt 0 ]; then
        success "Picked $(plural "$picked" commit) from dev and left out ${left}."
    else
        success "Picked $(plural "$picked" commit) from dev."
    fi
}

installer_without_version() {
    git show "$1:scripts/install.sh" | grep -v '^readonly CORESMITH_VERSION=' || true
}

require_exactly_dev() {
    if [ -n "$OMITTED" ] ||
        ! git diff --quiet "${REMOTE}/dev" HEAD -- . ':(exclude)VERSION' ':(exclude)CHANGELOG.md' ':(exclude)scripts/install.sh' ||
        [ "$(installer_without_version "${REMOTE}/dev")" != "$(installer_without_version HEAD)" ]; then
        fail "--skip-gates needs the release to be exactly ${REMOTE}/dev, and this one isn't. Run the gates."
    fi

    warn "Skipping the gates: v${VERSION} is exactly ${REMOTE}/dev, so dev's bun run ci covers it."
}

gate() {
    log "Running $*"

    if ! "$@"; then
        fail "$* failed. Fix it on dev, then release."
    fi
}

gates() {
    gate bun run ci
    success "Every gate passed"
}

corpus_gate() {
    local hash

    hash="$(bun tests/Corpus/codeHash.ts)"

    if [ ! -f "storage/corpus/fixed-pass-${hash}.json" ]; then
        fail "The fixed set of real repositories hasn't passed on this detection and deploy code (${hash}). Run bun tests/Corpus/run.ts against the Lima servers, then bun tests/Corpus/report.ts <results> --gate."
    fi

    success "The fixed set of real repositories passed on this code (${hash})"
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
    local installer="${TMP}/install.sh.new"

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
    local commit subject previous added="" fixed="" changed="" rewritten="${TMP}/CHANGELOG.md"

    ENTRY="## v${VERSION} ($(date +%Y-%m-%d))"
    previous="$(git describe --tags --abbrev=0 --match 'v[0-9]*' --exclude '*-*' HEAD 2>/dev/null || true)"

    if [ -z "$previous" ]; then
        ENTRY="${ENTRY}"$'\n\n'"Initial release."
    else
        while IFS= read -r commit; do
            if [ -z "$commit" ]; then
                continue
            fi

            subject="$(git log -1 --format=%s "$commit")"

            case "$subject" in
            'chore(release):'*) continue ;;
            feat*) added="- ${subject}"$'\n'"${added}" ;;
            fix*) fixed="- ${subject}"$'\n'"${fixed}" ;;
            *) changed="- ${subject}"$'\n'"${changed}" ;;
            esac
        done <<<"$PICKED"

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

push_ref() {
    log "Pushing $1 to ${REMOTE}..."

    if ! git push --quiet "$REMOTE" "$2"; then
        fail "${REMOTE} refused the push."
    fi

    PUSHED=true
    success "Pushed $1"
}

open_pull_request() {
    local body="${TMP}/pull-request.md" url commit

    {
        printf '%s\n' "$ENTRY" | sed '1d' | sed '/./,$!d'

        if [ -n "$OMITTED" ]; then
            printf '\n### Left out for a later release\n'

            while IFS= read -r commit; do
                if [ -n "$commit" ]; then
                    printf -- '- %s\n' "$(git log -1 --format='%h %s' "$commit")"
                fi
            done <<<"$OMITTED"
        fi

        printf '\nMerge it with any method: the next release reads the history on main, including the cherry-pick notes a squash keeps, to tell what has shipped. Then run ./scripts/release.sh publish %s.\n' "$VERSION"
    } >"$body"

    set -- gh pr create --repo "$REPO" --base main --head "$RELEASE_BRANCH" --title "Release v${VERSION}" --body-file "$body"

    if ! url="$("$@")"; then
        KEEP_TMP=true
        warn "${RELEASE_BRANCH} is pushed, but the pull request wasn't opened. Open it with:"
        print_command "$@"
        exit 1
    fi

    success "Opened ${url}"
    log "Review it, merge it, then run ./scripts/release.sh publish ${VERSION}."
}

prepare() {
    collect_commits
    confirm "Opening a pull request into main for v${VERSION} on ${REPO}."
    TMP="$(mktemp -d)"
    ARMED=true
    SWITCHED=true
    git checkout --quiet --no-track -b "$RELEASE_BRANCH" "${REMOTE}/main"
    BRANCH_CREATED=true
    pick_commits
    resolve_signing HEAD
    build
    bump
    changelog
    git add VERSION scripts/install.sh CHANGELOG.md
    git commit --quiet -m "chore(release): v${VERSION}"

    if $SKIP_GATES; then
        require_exactly_dev
    else
        gates
    fi

    corpus_gate
    push_ref "$RELEASE_BRANCH" "refs/heads/${RELEASE_BRANCH}"
    git checkout --quiet "$START_BRANCH"
    SWITCHED=false
    git branch --quiet -D "$RELEASE_BRANCH"
    open_pull_request
}

tag_release() {
    if [ -n "$KEY" ]; then
        git -c gpg.format=openpgp tag -s -u "$KEY" -m "Release v${VERSION}" "v${VERSION}" "$1"
        TAG_CREATED=true

        if ! git -c gpg.format=openpgp verify-tag "v${VERSION}" >/dev/null 2>&1; then
            fail "The signature on v${VERSION} didn't verify."
        fi

        SIGNATURE_FILE="${TMP}/install.sh.asc"
        gpg --batch --yes --armor --detach-sign --local-user "$KEY" --output "$SIGNATURE_FILE" "$INSTALLER"
    else
        git -c tag.gpgSign=false tag -a -m "Release v${VERSION}" "v${VERSION}" "$1"
        TAG_CREATED=true
    fi
}

github_release() {
    local notes="${TMP}/notes.md" kind="--latest"

    if prerelease; then
        kind="--prerelease"
        printf 'Pre-release v%s.\n' "$VERSION" >"$notes"
    else
        printf '%s\n' "$ENTRY" | sed '1d' | sed '/./,$!d' >"$notes"
    fi

    set -- gh release create "v${VERSION}" --repo "$REPO" --title "v${VERSION}" --notes-file "$notes" --verify-tag "$kind" "$INSTALLER"

    if [ -n "$SIGNATURE_FILE" ]; then
        set -- "$@" "$SIGNATURE_FILE"
    fi

    if ! "$@"; then
        KEEP_TMP=true
        warn "v${VERSION} is pushed, but the GitHub release wasn't created. Finish it with:"
        print_command "$@"
        exit 1
    fi

    success "Published https://github.com/${REPO}/releases/tag/v${VERSION}"
}

publish() {
    local line state commit url merged

    line="$(gh pr list --repo "$REPO" --head "$RELEASE_BRANCH" --state all --limit 1 --json state,mergeCommit,url --jq 'if length == 0 then "" else .[0] | .state + "|" + (.mergeCommit.oid // "") + "|" + .url end' 2>/dev/null || true)"

    if [ -z "$line" ]; then
        fail "There's no pull request for ${RELEASE_BRANCH}. Open one with ./scripts/release.sh ${VERSION}."
    fi

    IFS='|' read -r state commit url <<<"$line"

    case "$state" in
    MERGED) ;;
    OPEN) fail "${url} isn't merged yet. Merge it, then publish." ;;
    *) fail "${url} was closed without merging. Open a new one with ./scripts/release.sh ${VERSION}." ;;
    esac

    if [ -z "$commit" ] || ! git merge-base --is-ancestor "$commit" "${REMOTE}/main" 2>/dev/null; then
        fail "${REMOTE}/main doesn't have the merge of ${url} yet. Try again in a moment."
    fi

    merged="$(git show "${commit}:VERSION" 2>/dev/null | tr -d '[:space:]' || true)"

    if [ "$merged" != "$VERSION" ]; then
        fail "${url} put VERSION ${merged:-nothing} on main, not ${VERSION}."
    fi

    ENTRY="$(git show "${commit}:CHANGELOG.md" 2>/dev/null | awk -v head="## v${VERSION} (" 'index($0, head) == 1 { found = 1; print; next } found && /^## / { exit } found { print }' || true)"

    if [ -z "$ENTRY" ]; then
        fail "CHANGELOG.md on main has no entry for v${VERSION}."
    fi

    confirm "Publishing v${VERSION} from ${url}."
    resolve_signing "$commit"
    TMP="$(mktemp -d)"
    INSTALLER="${TMP}/install.sh"
    git show "${commit}:scripts/install.sh" >"$INSTALLER"
    ARMED=true
    tag_release "$commit"
    push_ref "v${VERSION}" "refs/tags/v${VERSION}"
    github_release
}

cut_prerelease() {
    confirm "Releasing v${VERSION} from ${REMOTE}/dev to ${REPO}."
    TMP="$(mktemp -d)"
    ARMED=true
    SWITCHED=true
    git checkout --quiet --detach "${REMOTE}/dev"
    resolve_signing HEAD
    build
    bump
    git add VERSION scripts/install.sh
    git commit --quiet -m "chore(release): v${VERSION}"
    gates
    INSTALLER="${TMP}/install.sh"
    git show "HEAD:scripts/install.sh" >"$INSTALLER"
    tag_release HEAD
    push_ref "v${VERSION}" "refs/tags/v${VERSION}"
    git checkout --quiet "$START_BRANCH"
    SWITCHED=false
    github_release
}

on_exit() {
    local status=$?

    if [ "$status" -ne 0 ]; then
        if $SWITCHED; then
            git cherry-pick --abort >/dev/null 2>&1 || true
            git reset --quiet --hard >/dev/null 2>&1 || true
            git checkout --quiet "$START_BRANCH" >/dev/null 2>&1 || true
        fi

        if $TAG_CREATED && ! $PUSHED; then
            git tag -d "v${VERSION}" >/dev/null 2>&1 || true
        fi

        if $BRANCH_CREATED && ! $PUSHED; then
            git branch --quiet -D "$RELEASE_BRANCH" >/dev/null 2>&1 || true
        fi

        if $ARMED && ! $PUSHED; then
            printf '%s[✗]%s Release v%s stopped. Nothing was pushed.\n' "$RED" "$NC" "$VERSION" >&2
        fi
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

    if [ "$ACTION" = "publish" ]; then
        publish
    elif prerelease; then
        cut_prerelease
    else
        prepare
    fi
}

trap on_exit EXIT

main "$@"
