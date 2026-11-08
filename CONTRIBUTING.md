# Contributing to Coresmith

## License & file headers

Coresmith is licensed under the Elastic License v2 (ELv2). Every new source file (PHP, JS/TS, Svelte, shell) must include the following header at the top:

    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.

For PHP files, prefer line comments after the opening `<?php` tag, then `declare(strict_types=1);` on its own line:

    <?php
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.

    declare(strict_types=1);

For Svelte/JS/TS files, use `//` line comments at the very top.

Existing starter files do NOT need to be retroactively rewritten; apply headers only to new files going forward.

## Dev quickstart

    composer install
    bun install
    php artisan migrate:all --fresh
    composer dev

## Code style

- No comments in code. The only comments are the ELv2 header on new files and directives a tool requires.
- PHP: `./vendor/bin/pint`. `pint.json` is tuned to accept what VS Code's Intelephense formatter writes on save, so keep the two in agreement rather than reverting to the plain Laravel preset.
- Write `!$foo`, never `! $foo`.
- JS/Svelte: `bun run lint` and `bun run format` rewrite files; `bun run lint:check` and `bun run format:check` only report.
- Type-check Svelte with `bun run types:check`. It must finish with 0 errors and 0 warnings.
- Tailwind classes use their canonical v4 form (`px-4.5`, not `px-[18px]`). `bun run lint:tailwind` reports any that are not, and `bun run lint:tailwind:fix` rewrites them with Tailwind's own canonicalisation, the same check VS Code's Tailwind extension shows as `suggestCanonicalClasses`.
- `composer ci:check` runs lint, format and type checks plus Pest in one go. The full list is under "Before you commit".

## Tests

Every change ships with tests at the levels it touches:

- **Pest** for services, jobs, policies, command builders and validation. Run `php artisan test` or `composer test`. It uses an in-memory SQLite database; CI also runs it on PostgreSQL and MySQL, and you can too by pointing the `DB_*` variables at a database the suite may wipe, for example `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=coresmith_test DB_USERNAME=coresmith DB_PASSWORD=secret php artisan test` (or `DB_CONNECTION=pgsql` on port 5432).
- **Playwright** for anything a user can see or click, on chromium, firefox, webkit, mobile-chrome and mobile-safari. Run `bun run test:e2e`.

A feature is done when both exist and both pass on every browser project. Server-side tests call routes directly, so they cannot see a page that never rendered, a control that was never linked or a form that silently failed.

### Browser test conventions

- `bun run e2e:refresh` reseeds the isolated E2E database before a run, and `bun run e2e:install-refresh` prepares the installer project's database.
- The suite uses `.env.e2e` and `database/e2e.sqlite`, never development data.
- Playwright runs with `workers: 1`. Parallel workers writing to one SQLite file cause contention that looks like browser-specific flakiness.
- Don't log in inside a test unless the test is about logging in; use the seeded storage states. Signing out invalidates the shared session for every later test, and repeated logins hit Fortify's rate limit.
- Assert accessibility with axe on every significant page, in light and dark. Serious and critical violations fail the build.
- A test that can't undo what it changed needs one fixture per browser project, selected by `testInfo.project.name`. The five projects share one database, so a shared fixture is used up by the first project and the other four fail in an order that reads as flake.
- A skip names a platform reason (for example, Safari only tabs to links when macOS full keyboard access is on), never a convenience one, and the reason must be verified on the browsers being skipped.

## Before you commit

All of these must pass:

`./vendor/bin/pint --test` · `bun run lint:check` · `bun run lint:sh` · `bun run format:check` · `bun run lint:tailwind` · `bun run types:check` · `bun run build` · `php artisan test` · `bun run test:e2e`

CI runs the same list. `bun run ci` runs it all on your machine: every check above, Pest on SQLite, PostgreSQL and MySQL, and the whole browser suite in parallel shards. It checks what HEAD would push, so commit first. It expects PostgreSQL on port 5432 and MySQL on port 3310 (`CI_PG_PORT`, `CI_MYSQL_PORT`, `CI_DB_DATABASE`, `CI_DB_USERNAME` and `CI_DB_PASSWORD` change that), and `CI_SHARDS` sets how many browser shards run at once. `bun run ci browser` runs only that stage, likewise `static` and `pest`.

## Design

- `MONOVEN_DESIGN.md` (the studio Signature) and `brand.md` (Coresmith's product layer) are the design rules. Monoven keeps them private, so they are not in a public checkout; ask a maintainer for them before visual work. Check every new page or component against the Definition-of-Done checklist and the §12 anti-patterns in `MONOVEN_DESIGN.md` before calling it done. Matching the neighbouring file is not the same thing.
- Where Coresmith's palette and the Signature disagree, `brand.md` records which one wins. Don't change those decisions silently.
- `DesignSystemParityTest` fails if the app's tokens, type scale, radius family or display face drift from the Monoven design system.
- Use the shadcn primitives in `resources/js/components/ui/` rather than raw form controls; a native checkbox gets the browser's focus outline, not the Monoven focus ring. Keep to one accent, and give every full-bleed view one hand tell and the Monoven footer mark.
- Screen layouts follow Coresmith's design handoff, so propose a change to a screen's structure in an issue before building it.
- The information architecture is settled. The sidebar has Fleet, Deployments, Releases and Settings, plus "Finish setup" until setup is done. A server has Apps, Databases, Hardening and Activity tabs. An app is one page with no tabs. Settings has General, Team, Git, Notifications and API tokens tabs, and Billing on Coresmith Cloud. Profile and security live under "Your account", reached from the account dialog. Below 640px the sidebar becomes bottom tabs, and below 900px it collapses to a rail.
- Show real state. A feature that can't work yet says so and names what blocks it.

### Copy

- No em dashes. An en dash in a numeric range is fine.
- Sentence case.
- Calls to action are verbs.
- Confirmations are short ("Saved.").
- Errors say what happened and what to do next.
- Empty states explain and offer one action.

## Teams and addresses

- Controllers take the team from `TeamContext` (`$this->currentTeam()` in a controller), never `$user->teams()->first()`. A team named by the address (a `/{slug}` prefix, a team subdomain or a verified custom domain) wins over the user's last team, and a resource from another team is a 404 there.
- Build every link with `route()` or `url()`, in PHP and in Svelte. They carry the `/{slug}` prefix; a hardcoded path silently drops it.
- Git provider OAuth callbacks and webhooks use `TeamUrls::canonical()`, so providers only ever see the panel's own address. Single sign-on is the exception: sessions belong to a host, so its callback returns to the host that started it and each host is listed as a redirect URI.
- Single sign-on never attaches to an existing account on an email match alone. The account's owner signs in with their password, and two-factor if enabled, once to connect it.
- Top-level route segments are reserved from team addresses automatically. Words that are not routes go in `TeamSlug::RESERVED`.

## Deferrals

A "not built" note names what blocks it. If nothing blocks it, build it.

## Branching

- `dev`: active development, may break. Every change reaches it through a pull request, squash-merged, so each change is one commit a release can include or leave out.
- `main`: production-deploy branch for Coresmith Cloud and the latest tagged release for self-hosted users. Nothing is committed to it directly; it moves only when a release pull request is merged.

## Cutting a release

A stable release is a pull request into `main`, then a tag. Maintainers run `scripts/release.sh` from a clean checkout.

1. `./scripts/release.sh 1.0.2` makes `release/v1.0.2` from `main` and cherry-picks every commit `dev` has that `main` doesn't. It runs every check in "Before you commit", builds, bumps `VERSION` and the installer's version, writes `CHANGELOG.md`, pushes the branch and opens a pull request into `main`. Add `--omit <commit>`, once per commit, to keep changes that aren't ready for production on `dev` for a later release; the pull request lists them.
2. Review the pull request and merge it with any method. The next release reads the history on `main` to tell what has shipped, including the cherry-pick notes a squash keeps while the repository's squash commits list each commit's message.
3. `./scripts/release.sh publish 1.0.2` tags the commit the merge put on `main`, signs the installer and publishes a GitHub release with it attached.

`./scripts/release.sh 1.0.3-beta.1` cuts a pre-release instead: it tags a version bump on top of `dev` as it is, with no pull request, and changes neither `dev` nor `main`.

If anything fails before a push, the script puts everything back and says nothing was pushed. `--skip-gates` skips the checks when the release is exactly `dev`, with nothing left out, and `dev` has just passed `bun run ci`. Pass `--yes` to skip the confirmation. `CORESMITH_RELEASE_REMOTE` picks the remote (default `origin`) and `CORESMITH_RELEASE_REPO` the GitHub repository as `owner/name` (default read from the remote's address).

Signing:

- A stable release needs an OpenPGP signing key, or `--unsigned`, both to open its pull request and to publish it. The key is `CORESMITH_SIGNING_KEY`, else `git config user.signingkey` when `gpg.format` is unset or `openpgp`. An SSH signing key doesn't count, because updaters can only check OpenPGP signatures. Updaters report an unsigned release as unchecked.
- The release key's public half is committed to `resources/release-keys/` once. From then on every release must be signed by one of those keys, and `--unsigned` is refused, because every install that has the keys rejects an unsigned release.

Releasing needs the GitHub CLI signed in, so run `gh auth login` first. If the pull request or the GitHub release can't be created after a push, the script prints the `gh` command that finishes it.

## Commits

Follow Conventional Commits style (`feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `test:`).
