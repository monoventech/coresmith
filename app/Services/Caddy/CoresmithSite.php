<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use JsonException;
use RuntimeException;
use stdClass;

final class CoresmithSite
{
    public const SITE_ROUTE = 'coresmith-site';

    public const ANY_ROUTE = 'coresmith-any';

    public const INTERNAL_SERVER = 'coresmith-internal';

    public const SITE_POLICY = 'coresmith-site-tls';

    public const ANY_POLICY = 'coresmith-any-tls';

    public const LOGGER = 'coresmith';

    public const LOGGER_NAME = 'http.log.access.' . self::LOGGER;

    public const INTERNAL_LISTEN = '127.0.0.1:8484';

    public const UNREADABLE = "The web server couldn't read Coresmith's settings. Run caddy adapt --config /etc/caddy/Caddyfile on this machine to see why.";

    /**
     * @param  list<stdClass>  $hostRoutes
     * @param  list<stdClass>  $sitePolicies
     */
    private function __construct(
        private readonly array $hostRoutes,
        private readonly stdClass $catchAll,
        private readonly stdClass $internal,
        private readonly array $sitePolicies,
        private readonly stdClass $anyPolicy,
        private readonly stdClass $onDemand,
        private readonly stdClass $log,
        private readonly stdClass $admin,
    ) {}

    public static function fromAdapted(string $json): self
    {
        try {
            $adapted = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(self::UNREADABLE);
        }

        if (!$adapted instanceof stdClass) {
            throw new RuntimeException(self::UNREADABLE);
        }

        $servers = (array) ($adapted->apps->http->servers ?? []);
        $https = self::listening($servers, ':443') ?? throw self::missing('server on :443');
        $routes = self::objects($https->routes ?? []);
        $hostRoutes = array_values(array_filter($routes, fn(stdClass $route): bool => self::hosts($route) !== []));
        $catchAll = array_values(array_filter($routes, fn(stdClass $route): bool => !isset($route->match)))[0] ?? throw self::missing('catch-all route');
        $internal = self::listening($servers, self::INTERNAL_LISTEN) ?? throw self::missing('listener on ' . self::INTERNAL_LISTEN);

        if ($hostRoutes === []) {
            throw self::missing('route for its address');
        }

        $policies = self::objects($adapted->apps->tls->automation->policies ?? []);
        $anyPolicy = array_values(array_filter($policies, self::onDemandPolicy(...)))[0] ?? throw self::missing('on-demand certificate policy');
        $onDemand = $adapted->apps->tls->automation->on_demand ?? null;

        if (!$onDemand instanceof stdClass) {
            throw self::missing('on-demand permission');
        }

        $log = self::accessLog($adapted, $https, self::hosts($hostRoutes[0])[0]) ?? throw self::missing('access log');
        $admin = $adapted->admin ?? null;

        if (!$admin instanceof stdClass || !isset($admin->listen)) {
            throw self::missing('admin socket');
        }

        return new self(
            $hostRoutes,
            $catchAll,
            $internal,
            array_values(array_filter($policies, fn(stdClass $policy): bool => !self::onDemandPolicy($policy))),
            $anyPolicy,
            $onDemand,
            $log,
            $admin,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function siteRoute(): array
    {
        return [
            '@id' => self::SITE_ROUTE,
            'match' => [['host' => array_values(array_unique(array_merge(...array_map(self::hosts(...), $this->hostRoutes))))]],
            'handle' => [
                self::logsAsCoresmith(),
                ['handler' => 'subroute', 'routes' => $this->hostRoutes],
            ],
            'terminal' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function anyRoute(): array
    {
        return [
            '@id' => self::ANY_ROUTE,
            'handle' => [self::logsAsCoresmith(), ...self::objects($this->catchAll->handle ?? [])],
            'terminal' => true,
        ];
    }

    public function internalServer(): stdClass
    {
        return $this->internal;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sitePolicies(): array
    {
        return array_map(
            fn(stdClass $policy, int $index): array => ['@id' => self::SITE_POLICY . ($index === 0 ? '' : '-' . ($index + 1))] + (array) $policy,
            $this->sitePolicies,
            array_keys($this->sitePolicies),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function anyPolicy(): array
    {
        return ['@id' => self::ANY_POLICY] + (array) $this->anyPolicy;
    }

    public function onDemand(): stdClass
    {
        return $this->onDemand;
    }

    /**
     * @return array<string, mixed>
     */
    public function logger(): array
    {
        return [
            'writer' => $this->log->writer,
            'encoder' => $this->log->encoder ?? ['format' => 'json'],
            'include' => [self::LOGGER_NAME],
        ];
    }

    public function admin(): stdClass
    {
        return $this->admin;
    }

    /**
     * @return list<string>
     */
    public static function hosts(stdClass $route): array
    {
        $hosts = [];

        foreach (self::objects($route->match ?? []) as $match) {
            foreach ((array) ($match->host ?? []) as $host) {
                $hosts[] = (string) $host;
            }
        }

        return $hosts;
    }

    /**
     * @param  array<array-key, mixed>  $servers
     */
    private static function listening(array $servers, string $listen): ?stdClass
    {
        foreach ($servers as $server) {
            if ($server instanceof stdClass && ($server->listen ?? null) === [$listen]) {
                return $server;
            }
        }

        return null;
    }

    private static function accessLog(stdClass $adapted, stdClass $https, string $host): ?stdClass
    {
        $logs = $https->logs ?? null;
        $name = $logs instanceof stdClass ? ((array) ($logs->logger_names->{$host} ?? []))[0] ?? ($logs->default_logger_name ?? null) : null;
        $log = is_string($name) ? ($adapted->logging->logs->{$name} ?? null) : null;

        return $log instanceof stdClass && isset($log->writer) ? $log : null;
    }

    private static function onDemandPolicy(stdClass $policy): bool
    {
        return ($policy->on_demand ?? false) === true;
    }

    /**
     * @return list<stdClass>
     */
    private static function objects(mixed $items): array
    {
        return array_values(array_filter(is_array($items) ? $items : [], fn(mixed $item): bool => $item instanceof stdClass));
    }

    /**
     * @return array<string, mixed>
     */
    private static function logsAsCoresmith(): array
    {
        return ['handler' => 'vars', 'access_logger_names' => [self::LOGGER]];
    }

    private static function missing(string $what): RuntimeException
    {
        return new RuntimeException("The web server's settings for Coresmith have no {$what}. Run the installer again to put them back.");
    }
}
