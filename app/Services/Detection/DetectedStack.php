<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Services\Runtime\Stack;

final readonly class DetectedStack
{
    /**
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $unsettled
     * @param  list<Blocker>  $blockers
     */
    public function __construct(
        public ?string $type,
        public ?string $framework,
        public ?string $jsRuntime,
        public ?string $webDirectory,
        public array $sources = [],
        public array $unsettled = [],
        public array $blockers = [],
        public ?Stack $stack = null,
        public ?string $runtime = null,
    ) {}

    public function verdict(): string
    {
        return match (true) {
            $this->blockers !== [] => 'blocked',
            $this->unsettled !== [] => 'unsure',
            default => 'ready',
        };
    }

    public function version(): ?string
    {
        return $this->runtime === null ? null : ($this->stack?->runtimes[$this->runtime]->version ?? null);
    }

    /**
     * @return array<string, string>
     */
    public function choice(): array
    {
        return array_filter([
            'type' => $this->type,
            'framework' => $this->framework,
            'js_runtime' => $this->jsRuntime,
            'web_directory' => $this->webDirectory,
        ], fn(?string $value): bool => $value !== null);
    }

    /**
     * @return array{verdict: string, fields: array<string, ?string>, sources: array<string, string>, unsettled: array<string, string>, blockers: list<array{field: string, reason: string, fix: string}>}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict(),
            'fields' => [
                'type' => $this->type,
                'framework' => $this->framework,
                'js_runtime' => $this->jsRuntime,
                'web_directory' => $this->webDirectory,
                'version' => $this->version(),
            ],
            'sources' => $this->sources,
            'unsettled' => $this->unsettled,
            'blockers' => array_map(fn(Blocker $blocker): array => $blocker->toArray(), $this->blockers),
        ];
    }
}
