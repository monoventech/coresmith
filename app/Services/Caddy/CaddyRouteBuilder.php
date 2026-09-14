<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Exceptions\InvalidRouteConfigException;
use App\Models\Application;
use App\Services\SafeName;

final class CaddyRouteBuilder
{
    public const APPS_ROOT = '/var/coresmith/apps';

    public static function routeId(Application $application): string
    {
        return 'app-' . $application->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Application $application): array
    {
        return [
            '@id' => self::routeId($application),
            'match' => [
                ['host' => $this->domains($application)],
            ],
            'handle' => [
                [
                    'handler' => 'subroute',
                    'routes' => $this->offline($application)
                        ? [$this->serveMaintenancePage($application)]
                        : [
                            $this->blockSensitivePaths(),
                            $this->serveApplication($application),
                        ],
                ],
            ],
            'terminal' => true,
        ];
    }

    /**
     * @return list<string>
     */
    public function domains(Application $application): array
    {
        $domains = $application->allDomains();

        if ($domains === []) {
            throw InvalidRouteConfigException::missingDomain();
        }

        return array_map(SafeName::assertDomain(...), $domains);
    }

    /**
     * @return array<string, mixed>
     */
    public function tlsPolicy(): array
    {
        return ['protocol' => ['minimum_version' => 'tls1.2']];
    }

    public function offline(Application $application): bool
    {
        return $application->power_state === PowerState::Stopped || $this->maintenanceMode($application);
    }

    public function maintenanceMode(Application $application): bool
    {
        return (bool) (((array) $application->toggles)['maintenance_mode'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function serveMaintenancePage(Application $application): array
    {
        return [
            'handle' => [
                $this->securityHeaders(),
                [
                    'handler' => 'headers',
                    'response' => ['set' => [
                        'Content-Type' => ['text/html; charset=utf-8'],
                        'Cache-Control' => ['no-store'],
                        'Retry-After' => ['300'],
                    ]],
                ],
                [
                    'handler' => 'static_response',
                    'status_code' => 503,
                    'body' => $this->maintenanceBody($application),
                ],
            ],
        ];
    }

    private function maintenanceBody(Application $application): string
    {
        $name = htmlspecialchars($application->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $message = $application->power_state === PowerState::Stopped
            ? 'This site has been stopped by its owner.'
            : 'This site is down for maintenance. It will be back shortly.';

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>{$name} is unavailable</title>
            <style>
            :root{color-scheme:light dark}
            body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0b0c;color:#e8e8ea;font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
            main{max-width:32rem;padding:2rem;text-align:center}
            h1{margin:0 0 .5rem;font-size:1.25rem;font-weight:600}
            p{margin:0;color:#a1a1aa}
            @media(prefers-color-scheme:light){body{background:#fafafa;color:#18181b}p{color:#52525b}}
            </style>
            </head>
            <body><main><h1>{$name} is unavailable</h1><p>{$message}</p></main></body>
            </html>
            HTML;
    }

    /**
     * @return array<string, mixed>
     */
    private function blockSensitivePaths(): array
    {
        return [
            'match' => [
                ['path' => SensitivePaths::PATHS],
            ],
            'handle' => [
                ['handler' => 'static_response', 'status_code' => 404],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serveApplication(Application $application): array
    {
        return [
            'handle' => [
                ['handler' => 'encode', 'encodings' => ['gzip' => (object) [], 'zstd' => (object) []]],
                $this->securityHeaders(),
                ...$this->applicationHandlers($application),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function securityHeaders(): array
    {
        return [
            'handler' => 'headers',
            'response' => [
                'set' => [
                    'X-Frame-Options' => ['SAMEORIGIN'],
                    'X-Content-Type-Options' => ['nosniff'],
                    'X-XSS-Protection' => ['1; mode=block'],
                    'Referrer-Policy' => ['strict-origin-when-cross-origin'],
                    'Permissions-Policy' => ['camera=(), microphone=(), geolocation=()'],
                    'Strict-Transport-Security' => ['max-age=31536000; includeSubDomains; preload'],
                ],
                'deferred' => ['delete' => ['Server', 'X-Powered-By']],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function applicationHandlers(Application $application): array
    {
        $root = $this->documentRoot($application);

        return match ($application->type) {
            ApplicationType::Php => [
                [
                    'handler' => 'php_fastcgi',
                    'root' => $root,
                    'unix' => $this->fpmSocket($application),
                    'resolve_root_symlink' => true,
                ],
                ['handler' => 'file_server', 'root' => $root],
            ],
            ApplicationType::Static => [
                ['handler' => 'file_server', 'root' => $root],
            ],
            default => [
                [
                    'handler' => 'reverse_proxy',
                    'upstreams' => [['dial' => '127.0.0.1:' . $application->base_port]],
                ],
            ],
        };
    }

    public function documentRoot(Application $application): string
    {
        $base = self::APPS_ROOT . '/' . SafeName::assertSlug($application->slug) . '/current';
        $directory = trim((string) $application->directory, '/');
        $web = trim((string) $application->web_directory, '/');

        $path = $base;

        if ($directory !== '') {
            $path .= '/' . $directory;
        }

        if ($web !== '') {
            $path .= '/' . $web;
        }

        return $path;
    }

    public function fpmSocket(Application $application): string
    {
        return '/var/run/php/coresmith-app-' . SafeName::assertSlug($application->slug) . '-fpm.sock';
    }
}
