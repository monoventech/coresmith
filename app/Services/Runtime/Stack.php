<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final readonly class Stack
{
    public const GEMS = ['sidekiq', 'solid_queue'];

    public const PIPELINE_GEMS = ['sprockets-rails', 'propshaft', 'sinatra-activerecord', 'puma'];

    public const PACKAGES = ['horizon', 'reverb', 'inertia'];

    /**
     * @param  array<string, ResolvedRuntime>  $runtimes
     * @param  array{name: string, major: int}|null  $framework
     * @param  list<string>  $notes
     * @param  array<string, bool>  $gems
     * @param  array<string, bool>  $packages
     * @param  list<string>|null  $files
     * @param  array<string, mixed>  $languageTooling
     */
    public function __construct(
        public array $runtimes = [],
        public ?array $framework = null,
        public ?JsTooling $tooling = null,
        public array $notes = [],
        public array $gems = [],
        public array $packages = [],
        public ?array $files = null,
        public array $languageTooling = [],
    ) {}

    /**
     * @param  array<string, ResolvedRuntime>  $runtimes
     */
    public function withRuntimes(array $runtimes): self
    {
        return new self($runtimes, $this->framework, $this->tooling, $this->notes, $this->gems, $this->packages, $this->files, $this->languageTooling);
    }

    public function locked(string $gem): ?bool
    {
        return $this->gems[$gem] ?? null;
    }

    public function present(string $file): ?bool
    {
        return $this->files === null ? null : in_array($file, $this->files, true);
    }

    /**
     * @return array<string, string>
     */
    public function versions(): array
    {
        return array_map(fn(ResolvedRuntime $runtime): string => $runtime->version, $this->runtimes);
    }

    /**
     * @return list<ResolvedRuntime>
     */
    public function missing(): array
    {
        return array_values(array_filter($this->runtimes, fn(ResolvedRuntime $runtime): bool => !$runtime->installed));
    }

    /**
     * @return array{runtimes: array<string, array{runtime: string, version: string, source: string, detail: ?string}>, framework: array{name: string, major: int}|null, tooling: array<string, mixed>|null, gems: array<string, bool>, packages: array<string, bool>, files: list<string>|null, language_tooling: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'runtimes' => array_map(fn(ResolvedRuntime $runtime): array => $runtime->toArray(), $this->runtimes),
            'framework' => $this->framework,
            'tooling' => $this->tooling?->toArray(),
            'gems' => $this->gems,
            'packages' => $this->packages,
            'files' => $this->files,
            'language_tooling' => $this->languageTooling,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $runtimes = [];

        foreach ((array) ($data['runtimes'] ?? []) as $runtime => $resolved) {
            if (is_array($resolved)) {
                $runtimes[(string) $runtime] = ResolvedRuntime::fromArray($resolved);
            }
        }

        $framework = $data['framework'] ?? null;
        $tooling = JsTooling::fromArray(is_array($data['tooling'] ?? null) ? $data['tooling'] : null);
        $tool = $tooling?->runtime();
        $gems = array_filter((array) ($data['gems'] ?? []), fn(mixed $locked, int|string $gem): bool => is_string($gem) && is_bool($locked), ARRAY_FILTER_USE_BOTH);
        $packages = array_filter((array) ($data['packages'] ?? []), fn(mixed $installed, int|string $package): bool => in_array($package, self::PACKAGES, true) && is_bool($installed), ARRAY_FILTER_USE_BOTH);

        $files = is_array($data['files'] ?? null) ? array_values(array_filter($data['files'], 'is_string')) : null;

        uksort($runtimes, fn(int|string $a, int|string $b): int => ($a === $tool) <=> ($b === $tool));

        return new self(
            $runtimes,
            is_array($framework) && isset($framework['name'], $framework['major']) ? ['name' => (string) $framework['name'], 'major' => (int) $framework['major']] : null,
            $tooling,
            gems: $gems,
            packages: $packages,
            files: $files,
            languageTooling: array_filter((array) ($data['language_tooling'] ?? []), fn(int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function applicationAttributes(): array
    {
        $versions = $this->versions();

        return [
            'php_version' => $versions['php'] ?? null,
            'node_version' => $versions['nodejs'] ?? null,
            'bun_version' => $versions['bun'] ?? null,
            'deno_version' => $versions['deno'] ?? null,
            'ruby_version' => $versions['ruby'] ?? null,
            'python_version' => $versions['python'] ?? null,
            'stack' => $this->toArray(),
        ];
    }
}
