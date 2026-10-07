<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Enums\ApplicationType;
use App\Models\Application;

final readonly class JsTooling
{
    public const MANAGERS = ['bun' => 'bun', 'npm' => 'nodejs', 'pnpm' => 'nodejs', 'yarn' => 'nodejs', 'deno' => 'deno'];

    private const COREPACK = 'COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack ';

    public function __construct(
        public string $manager,
        public bool $locked,
        public bool $build,
        public bool $start,
        public ?string $managerVersion = null,
        public bool $manifest = true,
    ) {}

    public static function for(Application $application, RepositoryFacts $facts): ?self
    {
        $isJs = $application->type === ApplicationType::Js;

        if ($isJs && $application->js_runtime === 'deno') {
            return new self(
                'deno',
                in_array('deno.lock', $facts->lockfiles, true),
                $facts->denoTask('build'),
                $facts->denoTask('start'),
                null,
                $facts->has('deno.json') || $facts->has('deno.jsonc') || $facts->has('package.json'),
            );
        }

        if (!$facts->has('package.json')) {
            return $isJs ? new self($application->js_runtime === 'node' ? 'npm' : 'bun', false, false, false, null, false) : null;
        }

        $build = $facts->script('build');

        if (!$isJs && !$build) {
            return null;
        }

        $manager = match ($facts->lockfile()) {
            'bun.lock', 'bun.lockb' => 'bun',
            'package-lock.json' => 'npm',
            'pnpm-lock.yaml' => 'pnpm',
            'yarn.lock' => 'yarn',
            default => $isJs && $application->js_runtime === 'node' ? 'npm' : 'bun',
        };

        $declared = (string) ($facts->json('package.json')['packageManager'] ?? '');
        $version = preg_match('/^' . $manager . '@(\d[\w.+-]*)/', $declared, $matches) === 1 ? $matches[1] : null;

        return new self($manager, $facts->lockfile() !== null, $build, $facts->script('start'), $version);
    }

    public function runtime(): string
    {
        return self::MANAGERS[$this->manager];
    }

    /**
     * @return list<string>
     */
    public function install(): array
    {
        if (!$this->manifest) {
            return [];
        }

        return [match ($this->manager) {
            'npm' => $this->locked ? 'npm ci' : 'npm install',
            'pnpm' => self::COREPACK . 'pnpm install --frozen-lockfile',
            'yarn' => self::COREPACK . ($this->berry() ? 'yarn install --immutable' : 'yarn install --frozen-lockfile'),
            'deno' => 'deno install',
            default => $this->locked ? 'bun install --frozen-lockfile' : 'bun install',
        }];
    }

    /**
     * @return list<string>
     */
    public function buildCommands(): array
    {
        if (!$this->build) {
            return [];
        }

        return [match ($this->manager) {
            'npm' => 'npm run build',
            'pnpm' => self::COREPACK . 'pnpm run build',
            'yarn' => self::COREPACK . 'yarn run build',
            'deno' => 'deno task build',
            default => 'bun run build',
        }];
    }

    /**
     * @return array{manager: string, locked: bool, build: bool, start: bool, manager_version: ?string, manifest: bool}
     */
    public function toArray(): array
    {
        return [
            'manager' => $this->manager,
            'locked' => $this->locked,
            'build' => $this->build,
            'start' => $this->start,
            'manager_version' => $this->managerVersion,
            'manifest' => $this->manifest,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $manager = $data['manager'] ?? null;

        if (!is_string($manager) || !isset(self::MANAGERS[$manager])) {
            return null;
        }

        return new self(
            $manager,
            (bool) ($data['locked'] ?? false),
            (bool) ($data['build'] ?? false),
            (bool) ($data['start'] ?? false),
            isset($data['manager_version']) ? (string) $data['manager_version'] : null,
            (bool) ($data['manifest'] ?? true),
        );
    }

    private function berry(): bool
    {
        return $this->managerVersion !== null && (int) explode('.', $this->managerVersion)[0] >= 2;
    }
}
