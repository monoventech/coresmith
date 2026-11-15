<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Enums\ApplicationType;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\Stack;

final readonly class DetectedStack
{
    private const FRAMEWORKS = [
        'php' => ['laravel' => 'Laravel', 'symfony' => 'Symfony', '' => 'Plain PHP'],
        'js' => ['' => 'Other server'],
        'ruby' => ['rails' => 'Rails', 'sinatra' => 'Sinatra', 'rack' => 'Rack'],
        'python' => ['' => 'Other framework'],
        'go' => ['' => 'net/http'],
    ];

    private const SERVER_LABELS = [
        'express' => 'Express', 'fastify' => 'Fastify', 'hono' => 'Hono', '@hono/node-server' => 'Hono', 'koa' => 'Koa', '@nestjs/core' => 'NestJS',
        '@hapi/hapi' => 'hapi', 'restify' => 'Restify', 'polka' => 'Polka', '@adonisjs/core' => 'AdonisJS', 'h3' => 'h3', 'elysia' => 'Elysia',
        'socket.io' => 'Socket.IO', 'ws' => 'ws', '@feathersjs/feathers' => 'Feathers', '@loopback/core' => 'LoopBack', 'sails' => 'Sails',
        '@remix-run/serve' => 'Remix', '@react-router/serve' => 'React Router', 'json-server' => 'JSON Server', '@strapi/strapi' => 'Strapi',
        '@keystone-6/core' => 'Keystone', 'payload' => 'Payload', '@medusajs/medusa' => 'Medusa', 'micro' => 'Micro', '@tinyhttp/app' => 'tinyhttp',
        'hyper-express' => 'HyperExpress', 'srvx' => 'srvx',
    ];

    /**
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $unsettled
     * @param  list<Blocker>  $blockers
     * @param  array<string, list<string>>  $options
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
        public ?string $mainPackage = null,
        public array $options = [],
        public ?string $serverPackage = null,
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
        $framework = ($this->type === 'js' && $this->framework === '' && $this->serverPackage !== null ? (self::SERVER_LABELS[$this->serverPackage] ?? $this->serverPackage) : null)
            ?? ApplicationType::tryFrom((string) $this->type)?->language()->frameworkLabel((string) $this->framework) ?? self::FRAMEWORKS[(string) $this->type][(string) $this->framework] ?? (string) $this->framework;

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
            'main_package' => $this->mainPackage,
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
                'main_package' => $this->mainPackage,
                'version' => $this->version(),
            ],
            'options' => $this->options,
            'sources' => $this->sources,
            'unsettled' => $this->unsettled,
            'blockers' => array_map(fn(Blocker $blocker): array => $blocker->toArray(), $this->blockers),
        ];
    }
}
