<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final readonly class RepositoryFacts
{
    private const JS_LOCKFILES = ['bun.lock', 'bun.lockb', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'];

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $lockfiles
     * @param  array<string, string>  $locked
     * @param  list<string>  $markers
     * @param  list<string>  $envKeys
     */
    public function __construct(
        public array $files = [],
        public array $lockfiles = [],
        public array $locked = [],
        public array $markers = [],
        public array $envKeys = [],
        public bool $complete = false,
        public string $folder = '',
    ) {}

    public function at(): string
    {
        return $this->folder === '' ? "at the repository's root" : "in {$this->folder}";
    }

    public function has(string $file): bool
    {
        return array_key_exists($file, $this->files);
    }

    public function exists(string $path): bool
    {
        return in_array($path, $this->markers, true);
    }

    public function gem(string $name): ?string
    {
        return preg_match('/^    ' . preg_quote($name, '/') . ' \(([^)]+)\)\r?$/m', (string) $this->file('Gemfile.lock'), $matches) === 1 ? $matches[1] : null;
    }

    public function dependency(string $package): ?string
    {
        $manifest = $this->json('package.json');

        foreach (['dependencies', 'devDependencies'] as $section) {
            $version = is_array($manifest[$section] ?? null) ? ($manifest[$section][$package] ?? null) : null;

            if (is_string($version)) {
                return $version;
            }
        }

        return null;
    }

    public function workspace(): bool
    {
        $workspaces = $this->json('package.json')['workspaces'] ?? null;
        $packages = is_array($workspaces) ? (array_is_list($workspaces) ? $workspaces : (array) ($workspaces['packages'] ?? [])) : [];

        return array_diff(array_map(fn(mixed $entry): string => is_string($entry) ? trim($entry) : '', [...$packages, ...$this->pnpmPackages()]), ['', '.', './']) !== [];
    }

    /**
     * @return list<string>
     */
    private function pnpmPackages(): array
    {
        $yaml = (string) $this->file('pnpm-workspace.yaml');

        if (preg_match('/^packages:[ \t]*\[([^\]]*)\]/m', $yaml, $inline) === 1) {
            return array_map(fn(string $entry): string => trim($entry, " \t'\""), explode(',', $inline[1]));
        }

        if (preg_match('/^packages:[ \t]*\R((?:[ \t]+-[^\r\n]*\R?)+)/m', $yaml, $block) !== 1) {
            return [];
        }

        preg_match_all('/^[ \t]+-[ \t]*([^#\r\n]*)/m', $block[1], $entries);

        return array_map(fn(string $entry): string => trim($entry, " \t'\""), $entries[1]);
    }

    public function file(string $file): ?string
    {
        return $this->files[$file] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(string $file): array
    {
        $content = $this->files[$file] ?? null;

        if ($content === null) {
            return [];
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $decoded = json_decode((string) preg_replace(['~^\s*//.*$~m', '~,(\s*[}\]])~'], ['', '$1'], $content), true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function lockfile(): ?string
    {
        foreach (self::JS_LOCKFILES as $lockfile) {
            if (in_array($lockfile, $this->lockfiles, true)) {
                return $lockfile;
            }
        }

        return null;
    }

    public function script(string $name): bool
    {
        $scripts = $this->json('package.json')['scripts'] ?? [];

        return is_array($scripts) && is_string($scripts[$name] ?? null) && trim($scripts[$name]) !== '';
    }

    public function denoTask(string $name): bool
    {
        foreach (['deno.json', 'deno.jsonc'] as $file) {
            $tasks = $this->json($file)['tasks'] ?? [];

            if (is_array($tasks) && isset($tasks[$name])) {
                return true;
            }
        }

        return $this->script($name);
    }

    public function locked(string $package): ?string
    {
        return $this->locked[$package] ?? null;
    }
}
