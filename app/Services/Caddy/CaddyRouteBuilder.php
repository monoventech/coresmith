<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Enums\PowerState;
use App\Exceptions\InvalidRouteConfigException;
use App\Models\Application;
use App\Services\Panel\Reverb;
use App\Services\SafeName;
use App\Stacks\Route;

final class CaddyRouteBuilder
{
    public const APPS_ROOT = '/var/coresmith/apps';

    private const SECURITY_HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
    ];

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
                            ...$this->serveApplication($application),
                        ],
                ],
            ],
            'terminal' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function addressServer(Application $application, string $listen, string $kind): array
    {
        return [
            '@id' => self::addressServerId($application, $kind),
            'listen' => [$listen],
            'automatic_https' => ['disable' => true],
            'routes' => [['handle' => $this->build($application)['handle'], 'terminal' => true]],
        ];
    }

    public static function addressServerId(Application $application, string $kind): string
    {
        return 'coresmith-app-' . $application->slug . '-' . $kind;
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
                ...$this->securityHeaders(),
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
            ? 'Its owner has stopped it for now.'
            : 'It is down for maintenance and will be back shortly.';

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
     * @return list<array<string, mixed>>
     */
    private function serveApplication(Application $application): array
    {
        $route = $application->type->language()->route($application);

        return [
            [
                'handle' => [
                    ...($route->servesFiles() ? [['handler' => 'vars', 'root' => $this->documentRoot($application)]] : []),
                    ...$this->securityHeaders(),
                    ['handler' => 'encode', 'encodings' => ['gzip' => (object) [], 'zstd' => (object) []], 'prefer' => ['gzip', 'zstd']],
                ],
            ],
            ...$this->reverbRoutes($application),
            ...$this->applicationRoutes($application, $route),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reverbRoutes(Application $application): array
    {
        $reverb = app(Reverb::class);
        $credentials = $reverb->credentials($application);

        if (!$reverb->live($application) || $credentials === null || preg_match(Reverb::ID_PATTERN, $credentials['id']) !== 1 || preg_match(Reverb::ID_PATTERN, $credentials['key']) !== 1) {
            return [];
        }

        $proxy = [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:' . $reverb->port($application)]]]];

        return [
            ['match' => [['path' => ['/app/' . $credentials['key']], 'header' => ['Upgrade' => ['websocket']]]], 'handle' => $proxy],
            ['match' => [['path' => ['/apps/' . $credentials['id'] . '/*']]], 'handle' => $proxy],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function securityHeaders(): array
    {
        $defaults = [];

        foreach (self::SECURITY_HEADERS as $name => $value) {
            $defaults[] = [
                'handler' => 'headers',
                'response' => [
                    'require' => ['headers' => [$name => null]],
                    'set' => [$name => [$value]],
                ],
            ];
        }

        return [
            ...$defaults,
            [
                'handler' => 'headers',
                'response' => [
                    'delete' => ['Server', 'X-Powered-By'],
                    'deferred' => true,
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function applicationRoutes(Application $application, Route $route): array
    {
        return match ($route) {
            Route::PhpFastcgi => [
                [
                    'match' => [['file' => ['try_files' => ['{http.request.uri.path}/index.php']], 'not' => [['path' => ['*/']]]]],
                    'handle' => [[
                        'handler' => 'static_response',
                        'headers' => ['Location' => ['{http.request.orig_uri.path}/{http.request.orig_uri.prefixed_query}']],
                        'status_code' => 308,
                    ]],
                ],
                [
                    'match' => [['file' => [
                        'try_files' => ['{http.request.uri.path}', '{http.request.uri.path}/index.php', 'index.php'],
                        'try_policy' => 'first_exist_fallback',
                        'split_path' => ['.php'],
                    ]]],
                    'handle' => [['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}']],
                ],
                [
                    'match' => [['path' => ['*.php']]],
                    'handle' => [[
                        'handler' => 'reverse_proxy',
                        'transport' => ['protocol' => 'fastcgi', 'resolve_root_symlink' => true, 'split_path' => ['.php']],
                        'upstreams' => [['dial' => 'unix/' . $this->fpmSocket($application)]],
                    ]],
                ],
                ['handle' => [['handler' => 'file_server']]],
            ],
            Route::FileServer => [
                ['handle' => [['handler' => 'file_server']]],
            ],
            Route::ReverseProxy => [
                ['handle' => [[
                    'handler' => 'reverse_proxy',
                    'upstreams' => [['dial' => '127.0.0.1:' . $application->base_port]],
                ]]],
            ],
            Route::ReverseProxyHttp2 => [
                ['handle' => [[
                    'handler' => 'reverse_proxy',
                    'transport' => ['protocol' => 'http', 'versions' => ['h2c', '2']],
                    'upstreams' => [['dial' => '127.0.0.1:' . $application->base_port]],
                ]]],
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
