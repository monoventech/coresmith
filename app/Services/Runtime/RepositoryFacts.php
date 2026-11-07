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
     */
    public function __construct(
        public array $files = [],
        public array $lockfiles = [],
        public array $locked = [],
    ) {}

    public function has(string $file): bool
    {
        return array_key_exists($file, $this->files);
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
