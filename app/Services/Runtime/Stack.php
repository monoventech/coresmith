<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final readonly class Stack
{
    /**
     * @param  array<string, ResolvedRuntime>  $runtimes
     * @param  array{name: string, major: int}|null  $framework
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $runtimes = [],
        public ?array $framework = null,
        public ?JsTooling $tooling = null,
        public array $notes = [],
    ) {}

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
     * @return array{runtimes: array<string, array{runtime: string, version: string, source: string, detail: ?string}>, framework: array{name: string, major: int}|null, tooling: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'runtimes' => array_map(fn(ResolvedRuntime $runtime): array => $runtime->toArray(), $this->runtimes),
            'framework' => $this->framework,
            'tooling' => $this->tooling?->toArray(),
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
        $tooling = $data['tooling'] ?? null;

        return new self(
            $runtimes,
            is_array($framework) && isset($framework['name'], $framework['major']) ? ['name' => (string) $framework['name'], 'major' => (int) $framework['major']] : null,
            JsTooling::fromArray(is_array($tooling) ? $tooling : null),
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
            'stack' => $this->toArray(),
        ];
    }
}
