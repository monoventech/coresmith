<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Services\Runtime\Catalog;
use App\Services\Runtime\Stack;

final readonly class DetectedStack
{
    private const FRAMEWORKS = [
        'php' => ['laravel' => 'Laravel', 'symfony' => 'Symfony', '' => 'Plain PHP'],
        'js' => ['sveltekit' => 'SvelteKit', 'nextjs' => 'Next.js', 'nuxt' => 'Nuxt', 'astro' => 'Astro', '' => 'API or other'],
        'ruby' => ['rails' => 'Rails', 'sinatra' => 'Sinatra'],
    ];

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

    public function label(Catalog $catalog): string
    {
        $version = $this->version();
        $runtime = $this->runtime === null || $version === null ? null : $catalog->label($this->runtime) . ' ' . $version;
        $framework = self::FRAMEWORKS[(string) $this->type][(string) $this->framework] ?? (string) $this->framework;

        return match ($this->type) {
            'static' => 'Static site, web root ' . ($this->webDirectory ?? '/'),
            'js' => $runtime === null ? $framework : "{$framework} on {$runtime}",
            default => $runtime === null ? $framework : "{$framework}, {$runtime}",
        };
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
