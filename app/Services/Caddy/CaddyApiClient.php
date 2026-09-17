<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Exceptions\SSHCommandFailedException;
use App\Models\Application;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHResult;
use Illuminate\Support\Sleep;

final class CaddyApiClient
{
    public const ADMIN_PORT = 2019;

    public const ROUTES_PATH = '/config/apps/http/servers/coresmith/routes';

    public const STAGED_CONFIG_FILE = '/etc/caddy/caddy-config.staged.json';

    public const RETRIES = 3;

    public const RETRY_SECONDS = 2;

    private const BUSY_EXIT_CODES = [7, 28, 52, 56];

    private const IDEMPOTENT_METHODS = ['GET', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly CaddyRouteBuilder $builder,
        private readonly RouteConfigValidator $validator,
    ) {}

    public function addSite(SSHConnection $connection, Application $application): void
    {
        $config = $this->buildValidated($application);

        $this->request($connection, 'POST', self::ROUTES_PATH, $config);
        $this->persistConfig($connection);
    }

    public function updateSite(SSHConnection $connection, Application $application): void
    {
        $config = $this->buildValidated($application);

        $this->request($connection, 'PATCH', '/id/' . CaddyRouteBuilder::routeId($application), $config);
        $this->persistConfig($connection);
    }

    public function putSite(SSHConnection $connection, Application $application): void
    {
        $config = $this->buildValidated($application);
        $path = '/id/' . CaddyRouteBuilder::routeId($application);

        if ($this->exists($connection, $path)) {
            $this->request($connection, 'PATCH', $path, $config);
        } else {
            $this->request($connection, 'POST', self::ROUTES_PATH, $config);
        }

        $this->persistConfig($connection);
    }

    public function removeSite(SSHConnection $connection, Application $application): void
    {
        $this->request($connection, 'DELETE', '/id/' . CaddyRouteBuilder::routeId($application));
        $this->persistConfig($connection);
    }

    public function persistConfig(SSHConnection $connection): void
    {
        $this->script($connection, $this->curl('GET', '/config/') . ' > ' . self::STAGED_CONFIG_FILE . ' && mv -f ' . self::STAGED_CONFIG_FILE . ' ' . CaddyInstaller::CONFIG_FILE);
    }

    public function loadPersistedConfig(SSHConnection $connection): void
    {
        $file = CaddyInstaller::CONFIG_FILE;

        $this->script($connection, "test -s {$file} || exit 0; " . $this->curl('POST', '/load', dataFile: $file));
    }

    private function script(SSHConnection $connection, string $script): void
    {
        $this->run($connection, 'sh -c ' . escapeshellarg($script));
    }

    private function exists(SSHConnection $connection, string $path): bool
    {
        try {
            $this->run($connection, $this->curl('GET', $path) . ' >/dev/null');

            return true;
        } catch (SSHCommandFailedException $e) {
            if (str_contains($e->output(), 'returned error: 404')) {
                return false;
            }

            throw $e;
        }
    }

    private function run(SSHConnection $connection, string $command, bool $retry = true): SSHResult
    {
        for ($attempt = 0; ; $attempt++) {
            $result = $connection->execute($command, sudo: true);

            if ($result->successful() || !$retry || $attempt >= self::RETRIES || !$this->busy($result)) {
                return $result->throw();
            }

            Sleep::for(self::RETRY_SECONDS)->seconds();
        }
    }

    private function busy(SSHResult $result): bool
    {
        return in_array($result->exitCode, self::BUSY_EXIT_CODES, true)
            || preg_match('/returned error: 5\d\d/', $result->output) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValidated(Application $application): array
    {
        $config = $this->builder->build($application);

        $this->validator->validate($config, $application);

        return $config;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function request(SSHConnection $connection, string $method, string $path, ?array $payload = null): void
    {
        $this->run($connection, $this->curl($method, $path, $payload), retry: in_array($method, self::IDEMPOTENT_METHODS, true));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function curl(string $method, string $path, ?array $payload = null, ?string $dataFile = null): string
    {
        $url = 'http://localhost:' . self::ADMIN_PORT . $path;

        $command = 'curl -fsS --max-time 30 -X ' . $method . ' ' . escapeshellarg($url);

        if ($payload !== null) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $command .= ' -H ' . escapeshellarg('Content-Type: application/json') . ' -d ' . escapeshellarg($json);
        }

        if ($dataFile !== null) {
            $command .= ' -H ' . escapeshellarg('Content-Type: application/json') . ' --data-binary ' . escapeshellarg('@' . $dataFile);
        }

        return $command;
    }
}
