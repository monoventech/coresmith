# Coresmith

Source-available, self-hostable server management and application deployment platform. Provisions Linux servers over SSH, deploys web apps (PHP, JavaScript/TypeScript, Ruby), and manages databases, SSL, firewalls, and processes — all without Docker.

> **License:** Elastic License v2 (ELv2). See [LICENSE](LICENSE). Self-hosting is free; reselling Coresmith as a hosted service is not.
>
> **Spec:** `CORESMITH_SPEC.md` is the authoritative product spec.

## Dev quickstart

    composer install
    bun install
    php artisan migrate:all --fresh
    composer dev

`composer dev` starts maildev, the Laravel server, the queue listener, and Vite. Visit `https://coresmith.test` (Herd/Valet) or the URL `php artisan serve` reports.

## Status

Pre-alpha. Design docs and sub-project plans are kept outside this repository.
