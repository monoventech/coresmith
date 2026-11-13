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

    public const START_SCRIPTS = ['start:prod', 'start'];

    public const LOCKFILES = ['npm' => ['package-lock.json'], 'pnpm' => ['pnpm-lock.yaml'], 'yarn' => ['yarn.lock'], 'bun' => ['bun.lock', 'bun.lockb']];

    private const BACKGROUND = '/(?:^|[\s;&|(])((?:pm2|forever)\s+start)(?:\s|$)/';

    private const COREPACK = 'COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack ';

    public function __construct(
        public string $manager,
        public bool $locked,
        public bool $build,
        public bool $start,
        public ?string $managerVersion = null,
        public bool $manifest = true,
        public bool $ssr = false,
        public ?string $main = null,
        public string $startScript = 'start',
    ) {}

    public static function declaredManager(RepositoryFacts $facts): ?string
    {
        return preg_match('/^(npm|pnpm|yarn|bun)@/', (string) ($facts->json('package.json')['packageManager'] ?? ''), $matches) === 1 ? $matches[1] : null;
    }

    public static function lockfileFor(RepositoryFacts $facts): ?string
    {
        $declared = self::declaredManager($facts);
        $own = $declared === null ? [] : array_values(array_intersect(self::LOCKFILES[$declared], $facts->lockfiles));

        return $own[0] ?? $facts->lockfile();
    }

    public static function startScriptFor(RepositoryFacts $facts): ?string
    {
        $scripts = array_values(array_filter(self::START_SCRIPTS, fn(string $name): bool => $facts->script($name)));
        $foreground = array_values(array_filter($scripts, fn(string $name): bool => self::backgroundCommand($facts, $name) === null));

        return $foreground[0] ?? $scripts[0] ?? null;
    }

    public static function backgroundCommand(RepositoryFacts $facts, string $script): ?string
    {
        $command = $facts->json('package.json')['scripts'][$script] ?? null;

        return is_string($command) && preg_match(self::BACKGROUND, $command, $matches) === 1 ? (string) preg_replace('/\s+/', ' ', $matches[1]) : null;
    }

    public static function safeMain(mixed $main): ?string
    {
        if (!is_string($main)) {
            return null;
        }

        $main = (string) preg_replace('~^\./~', '', trim($main));

        return preg_match('~^(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9_@][A-Za-z0-9._@/-]*$~D', $main) === 1 ? $main : null;
    }

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

        $lockfile = self::lockfileFor($facts);
        $manager = match ($lockfile) {
            'bun.lock', 'bun.lockb' => 'bun',
            'package-lock.json' => 'npm',
            'pnpm-lock.yaml' => 'pnpm',
            'yarn.lock' => 'yarn',
            default => $isJs && $application->js_runtime !== 'node' ? 'bun' : 'npm',
        };

        $declared = (string) ($facts->json('package.json')['packageManager'] ?? '');
        $version = preg_match('/^' . $manager . '@(\d[\w.+-]*)/', $declared, $matches) === 1 ? $matches[1] : null;

        $start = self::startScriptFor($facts);

        return new self($manager, $lockfile !== null, $build, $start !== null, $version, ssr: $facts->script('build:ssr'), main: self::safeMain($facts->json('package.json')['main'] ?? null), startScript: $start ?? 'start');
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
            'pnpm' => self::COREPACK . 'pnpm install --frozen-lockfile --config.strict-dep-builds=false',
            'yarn' => self::COREPACK . ($this->berry() ? 'yarn install --immutable' : 'yarn install --frozen-lockfile'),
            'deno' => 'deno install',
            default => $this->locked ? 'bun install --frozen-lockfile' : 'bun install',
        }];
    }

    /**
     * @return list<string>
     */
    public function buildCommands(bool $ssr = false): array
    {
        if (!$this->build) {
            return [];
        }

        $script = $ssr && $this->ssr ? 'build:ssr' : 'build';

        return [match ($this->manager) {
            'npm' => "npm run {$script}",
            'pnpm' => self::COREPACK . "pnpm run {$script}",
            'yarn' => self::COREPACK . "yarn run {$script}",
            'deno' => 'deno task build',
            default => "bun run {$script}",
        }];
    }

    /**
     * @return array{manager: string, locked: bool, build: bool, start: bool, manager_version: ?string, manifest: bool, ssr: bool, main: ?string, start_script: string}
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
            'ssr' => $this->ssr,
            'main' => $this->main,
            'start_script' => $this->startScript,
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
            (bool) ($data['ssr'] ?? false),
            self::safeMain($data['main'] ?? null),
            in_array($data['start_script'] ?? null, self::START_SCRIPTS, true) ? (string) $data['start_script'] : 'start',
        );
    }

    private function berry(): bool
    {
        return $this->managerVersion !== null && (int) explode('.', $this->managerVersion)[0] >= 2;
    }
}
