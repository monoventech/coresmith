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

Existing starter files do NOT need to be retroactively rewritten — apply headers only to new files going forward.

## Dev quickstart

    composer install
    bun install
    php artisan migrate:all --fresh
    composer dev

## Code style

- PHP: `vendor/bin/pint` (Laravel Pint).
- JS/Svelte: `bun run lint` and `bun run format`.
- Type-check Svelte: `bun run types:check`.
- Run all checks: `composer ci:check`.

## Tests

- Pest unit + feature tests under `tests/`.
- Run: `php artisan test` or `composer test`.
- No PR is complete without tests at every relevant layer (unit, feature, and Playwright E2E for user-facing flows once the E2E harness lands in a later sub-project).

## Branching

- `dev` — active development, may break.
- `main` — production-deploy branch for Coresmith Cloud and the latest tagged release for self-hosted users.

## Commits

Follow Conventional Commits style (`feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `test:`).