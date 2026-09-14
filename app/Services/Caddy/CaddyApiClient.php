<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Models\Application;
use App\Services\SSH\SSHConnection;

final class CaddyApiClient
{
    public const ADMIN_PORT = 2019;

    public const ROUTES_PATH = '/config/apps/http/servers/coresmith/routes';

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

    public function removeSite(SSHConnection $connection, Application $application): void
    {
        $this->request($connection, 'DELETE', '/id/' . CaddyRouteBuilder::routeId($application));
        $this->persistConfig($connection);
    }

    public function persistConfig(SSHConnection $connection): void
    {
        $connection->execute(
            $this->curl('GET', '/config/') . ' > ' . CaddyInstaller::CONFIG_FILE,
            sudo: true,
        )->throw();
    }

    public function loadPersistedConfig(SSHConnection $connection): void
    {
        $file = CaddyInstaller::CONFIG_FILE;

        $connection->execute(
            "test -s {$file} && " . $this->curl('POST', '/load', dataFile: $file) . ' || true',
            sudo: true,
        )->throw();
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
        $connection->execute($this->curl($method, $path, $payload), sudo: true)->throw();
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
