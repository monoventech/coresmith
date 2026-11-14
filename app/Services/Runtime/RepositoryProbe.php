<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\Application;
use App\Services\Deployment\AppShell;
use App\Services\SSH\SSHConnection;

final class RepositoryProbe
{
    public const DIRECTORY_VARIABLE = 'CORESMITH_PROBE_DIR';

    public const FILES = [
        '.tool-versions', 'composer.json', 'package.json', '.nvmrc', '.node-version', '.ruby-version', 'Gemfile', 'Gemfile.lock', '.dvmrc', 'deno.json', 'deno.jsonc',
        'svelte.config.js', 'svelte.config.ts', 'next.config.js', 'next.config.mjs', 'next.config.ts', 'nuxt.config.ts', 'nuxt.config.js',
        'astro.config.mjs', 'astro.config.ts', 'astro.config.js', 'vite.config.ts', 'vite.config.js', 'pnpm-workspace.yaml', 'angular.json',
        '.python-version', 'runtime.txt', 'pyproject.toml', 'requirements.txt', 'Pipfile', 'Procfile', 'manage.py',
        'app.py', 'main.py', 'wsgi.py', 'asgi.py', 'server.py', 'application.py', 'run.py', 'api.py',
    ];

    public const GLOBS = ['*/settings.py', '*/settings/*.py', '*/wsgi.py', '*/asgi.py', '*/main.py', '*/app.py', '*/__init__.py', '*/api.py', '*/server.py'];

    public const LOCKFILES = ['bun.lock', 'bun.lockb', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'deno.lock'];

    public const MARKERS = [
        'artisan', 'bin/rails', 'bin/console', 'config.ru', 'Rakefile', 'index.php', 'public/index.php', 'web/index.php',
        'index.html', 'public/index.html', 'main.ts', 'config/master.key', 'config/database.yml', 'symfony.lock', 'config/bundles.php', 'server.js', 'ace.js',
        'uv.lock', 'poetry.lock', 'Pipfile.lock', 'alembic.ini',
    ];

    public const ENV_SECRETS = ['APP_KEY', 'APP_SECRET', 'SECRET_KEY_BASE', 'RAILS_MASTER_KEY', 'SECRET_KEY'];

    public const MAX_BYTES = 262144;

    public const COMPOSER_LOCK = 'composer.lock';

    public const COMPOSER_LOCK_MAX_BYTES = 8388608;

    private const SCRIPT = <<<'BASH'
    cd "$CORESMITH_PROBE_DIR" 2>/dev/null || exit 0
    for f in {files}; do
      if [ -f "$f" ] && [ ! -L "$f" ]; then printf '@@file %s ' "$f"; head -c {bytes} "$f" | base64 | tr -d '\n'; printf '\n'; fi
    done
    for f in {globs}; do
      if [ -f "$f" ] && [ ! -L "$f" ]; then printf '@@file %s ' "$f"; head -c {bytes} "$f" | base64 | tr -d '\n'; printf '\n'; fi
    done
    for f in {lockfiles}; do
      if [ -f "$f" ]; then printf '@@lock %s\n' "$f"; fi
    done
    for f in {markers}; do
      if [ -f "$f" ] && [ ! -L "$f" ]; then printf '@@has %s\n' "$f"; fi
    done
    if [ -f .env ] && [ ! -L .env ]; then
      for k in {secrets}; do
        if grep -Eq "^[[:space:]]*(export[[:space:]]+)?${k}=[\"']?[^\"'[:space:]#]" .env; then printf '@@envkey %s\n' "$k"; fi
      done
    fi
    if [ -f composer.lock ] && [ ! -L composer.lock ]; then
      printf '@@file composer.lock '; head -c {lockbytes} composer.lock | base64 | tr -d '\n'; printf '\n'
      grep -A1 -E '"name": "(laravel/framework|symfony/framework-bundle)",' composer.lock | awk -F'"' '/"name"/ {n=$4} /"version"/ {print "@@locked " n " " $4}'
    fi
    if [ -f Gemfile.lock ] && [ ! -L Gemfile.lock ]; then
      sed -n 's/^    rails (\([^)]*\))$/@@locked rails \1/p' Gemfile.lock | head -n 1
      sed -n 's/^    sidekiq (\([^)]*\))$/@@locked sidekiq \1/p' Gemfile.lock | head -n 1
      sed -n 's/^    solid_queue (\([^)]*\))$/@@locked solid_queue \1/p' Gemfile.lock | head -n 1
    fi
    printf '@@done\n'
    exit 0
    BASH;

    public function read(SSHConnection $connection, string $directory, ?Application $as = null, string $folder = ''): RepositoryFacts
    {
        $command = 'echo ' . escapeshellarg(base64_encode($this->script())) . ' | base64 -d | '
            . self::DIRECTORY_VARIABLE . '=' . escapeshellarg($directory) . ' bash -s';

        if ($as === null) {
            return $this->parse($connection->execute($command)->output, $folder);
        }

        return $this->parse($connection->execute(app(AppShell::class)->run($as, $command, $directory), sudo: true)->output, $folder);
    }

    public function script(): string
    {
        return strtr(self::SCRIPT, [
            '{files}' => implode(' ', self::FILES),
            '{globs}' => implode(' ', self::GLOBS),
            '{lockfiles}' => implode(' ', self::LOCKFILES),
            '{markers}' => implode(' ', self::MARKERS),
            '{secrets}' => implode(' ', self::ENV_SECRETS),
            '{bytes}' => (string) self::MAX_BYTES,
            '{lockbytes}' => (string) self::COMPOSER_LOCK_MAX_BYTES,
        ]) . "\n";
    }

    public function parse(string $output, string $folder = ''): RepositoryFacts
    {
        $files = [];
        $lockfiles = [];
        $locked = [];
        $markers = [];
        $envKeys = [];
        $complete = false;

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('~^@@file (\S+) ([A-Za-z0-9+/=]*)$~', $line, $matches) === 1 && (in_array($matches[1], [...self::FILES, self::COMPOSER_LOCK], true) || self::globbed($matches[1]))) {
                $content = base64_decode($matches[2], true);

                if ($content !== false) {
                    $files[$matches[1]] = $content;
                }
            } elseif (preg_match('/^@@lock (\S+)$/', $line, $matches) === 1 && in_array($matches[1], self::LOCKFILES, true)) {
                $lockfiles[] = $matches[1];
            } elseif (preg_match('/^@@locked (\S+) v?(\d[\w.+-]*)$/', $line, $matches) === 1) {
                $locked[$matches[1]] = $matches[2];
            } elseif ($line === '@@done') {
                $complete = true;
            } elseif (preg_match('/^@@has (\S+)$/', $line, $matches) === 1 && in_array($matches[1], self::MARKERS, true)) {
                $markers[] = $matches[1];
            } elseif (preg_match('/^@@envkey (\S+)$/', $line, $matches) === 1 && in_array($matches[1], self::ENV_SECRETS, true)) {
                $envKeys[] = $matches[1];
            }
        }

        return new RepositoryFacts($files, array_values(array_unique($lockfiles)), $locked, array_values(array_unique($markers)), array_values(array_unique($envKeys)), $complete, $folder);
    }

    public static function globbed(string $path): bool
    {
        foreach (self::GLOBS as $pattern) {
            if (fnmatch($pattern, $path, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }
}
