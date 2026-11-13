<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Exceptions\SSHCommandFailedException;
use App\Models\Application;
use App\Models\InstanceSetting;
use App\Models\Server;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

final class CaddyApiClient
{
    public const ADMIN_PORT = 2019;

    public const ADMIN_ORIGIN = 'http://localhost:' . self::ADMIN_PORT;

    public const ADMIN_SOCKET = '/run/caddy-admin/admin.sock';

    public const ADMIN_LISTEN = 'unix/' . self::ADMIN_SOCKET;

    public const ROUTES_PATH = '/config/apps/http/servers/' . CaddyInstaller::SERVER . '/routes';

    public const AFTER_CORESMITH_SITE = self::ROUTES_PATH . '/1';

    public const FIRST_ROUTE = self::ROUTES_PATH . '/0';

    public const POLICIES_PATH = '/config/apps/tls/automation/policies';

    public const FIRST_POLICY = self::POLICIES_PATH . '/0';

    public const THIS_MACHINE_LOCK = 'caddy-config:this-machine';

    public const THIS_MACHINE_PERSIST = '/usr/local/libexec/coresmith/persist-caddy-config';

    public const LOCK_SECONDS = 120;

    public const LOCK_WAIT_SECONDS = 60;

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

        $this->changing($connection, function () use ($connection, $application, $config): void {
            $this->insertSite($connection, $application, $config);
            $this->persistConfig($connection);
        });
    }

    public function updateSite(SSHConnection $connection, Application $application): void
    {
        $config = $this->buildValidated($application);

        $this->changing($connection, function () use ($connection, $application, $config): void {
            $this->request($connection, 'PATCH', '/id/' . CaddyRouteBuilder::routeId($application), $config);
            $this->updatePolicy($connection, $application);
            $this->persistConfig($connection);
        });
    }

    public function putSite(SSHConnection $connection, Application $application): void
    {
        $config = $this->buildValidated($application);
        $path = '/id/' . CaddyRouteBuilder::routeId($application);

        $this->changing($connection, function () use ($connection, $application, $config, $path): void {
            if ($this->exists($connection, $path)) {
                $this->request($connection, 'PATCH', $path, $config);
                $this->updatePolicy($connection, $application);
            } else {
                $this->insertSite($connection, $application, $config);
            }

            $this->persistConfig($connection);
        });
    }

    public function closeUnknownHosts(SSHConnection $connection): void
    {
        $this->changing($connection, function () use ($connection): void {
            if (!$this->exists($connection, '/id/' . CaddyInstaller::UNKNOWN_HOSTS_ROUTE)) {
                $this->request($connection, 'POST', self::ROUTES_PATH, CaddyInstaller::unknownHostsRoute(), retry: false);
            }

            $this->persistConfig($connection);
        });
    }

    public function removeSite(SSHConnection $connection, Application $application): void
    {
        $this->changing($connection, function () use ($connection, $application): void {
            $removed = $this->delete($connection, '/id/' . CaddyRouteBuilder::routeId($application));

            if ($this->onCoresmithsMachine($connection)) {
                $removed = $this->delete($connection, '/id/' . self::policyId($application)) || $removed;
            }

            if ($removed) {
                $this->persistConfig($connection);
            }
        });
    }

    public function putAddress(SSHConnection $connection, Application $application, string $kind, string $listen): void
    {
        $server = $this->builder->addressServer($application, $listen, $kind);

        $this->changing($connection, function () use ($connection, $application, $kind, $server): void {
            $this->request($connection, 'POST', self::addressPath($application, $kind), $server);
            $this->persistConfig($connection);
        });
    }

    public function removeAddress(SSHConnection $connection, Application $application, string $kind): void
    {
        $this->changing($connection, function () use ($connection, $application, $kind): void {
            if ($this->delete($connection, self::addressPath($application, $kind))) {
                $this->persistConfig($connection);
            }
        });
    }

    public static function addressPath(Application $application, string $kind): string
    {
        return '/config/apps/http/servers/' . CaddyRouteBuilder::addressServerId($application, $kind);
    }

    public static function policyId(Application $application): string
    {
        return CaddyRouteBuilder::routeId($application) . '-tls';
    }

    public function persistConfig(SSHConnection $connection): void
    {
        $this->script($connection, $this->onCoresmithsMachine($connection) ? self::THIS_MACHINE_PERSIST : self::persistScript($connection->server()->caddyAdminOnSocket()));
    }

    public static function exclusively(Server $server, callable $change): mixed
    {
        if (!$server->runs_coresmith) {
            return $change();
        }

        return Cache::lock(self::THIS_MACHINE_LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $change);
    }

    public static function persistScript(bool $socket): string
    {
        return 'curl -fsS --max-time 30 -X GET ' . self::address($socket, '/config/') . ' > ' . self::STAGED_CONFIG_FILE . ' && mv -f ' . self::STAGED_CONFIG_FILE . ' ' . CaddyInstaller::CONFIG_FILE;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function insertSite(SSHConnection $connection, Application $application, array $config): void
    {
        if (!$this->onCoresmithsMachine($connection)) {
            $this->request($connection, 'PUT', self::FIRST_ROUTE, $config, retry: false);

            return;
        }

        $this->request($connection, 'PUT', self::AFTER_CORESMITH_SITE, $config, retry: false);
        $this->request($connection, 'PUT', self::FIRST_POLICY, $this->policy($application), retry: false);
    }

    private function updatePolicy(SSHConnection $connection, Application $application): void
    {
        if (!$this->onCoresmithsMachine($connection)) {
            return;
        }

        try {
            $this->request($connection, 'PATCH', '/id/' . self::policyId($application), $this->policy($application));
        } catch (SSHCommandFailedException $e) {
            if (!$this->missing($e)) {
                throw $e;
            }

            $this->request($connection, 'PUT', self::FIRST_POLICY, $this->policy($application), retry: false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function policy(Application $application): array
    {
        return CaddyInstaller::appPolicy(
            CaddyRouteBuilder::routeId($application),
            $this->builder->domains($application),
            CaddyInstaller::issuers(InstanceSetting::get('notification_email')),
        );
    }

    private function delete(SSHConnection $connection, string $path): bool
    {
        try {
            $this->request($connection, 'DELETE', $path);
        } catch (SSHCommandFailedException $e) {
            if ($this->missing($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    private function onCoresmithsMachine(SSHConnection $connection): bool
    {
        return (bool) $connection->server()->runs_coresmith;
    }

    private function changing(SSHConnection $connection, callable $change): void
    {
        self::exclusively($connection->server(), $change);
    }

    private function script(SSHConnection $connection, string $script): void
    {
        $this->run($connection, 'sh -c ' . escapeshellarg($script));
    }

    private function exists(SSHConnection $connection, string $path): bool
    {
        try {
            $this->run($connection, $this->curl($connection->server(), 'GET', $path) . ' >/dev/null');

            return true;
        } catch (SSHCommandFailedException $e) {
            if ($this->missing($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function missing(SSHCommandFailedException $e): bool
    {
        return str_contains($e->output(), 'returned error: 404');
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

    public static function probe(Server $server, int $timeout = 10): string
    {
        return self::probeAt($server->caddyAdminOnSocket(), $timeout);
    }

    public static function probeAt(bool $socket, int $timeout = 10): string
    {
        return 'curl -fsS --max-time ' . $timeout . ' ' . self::address($socket, '/config/') . ' >/dev/null';
    }

    public static function address(bool $socket, string $path): string
    {
        if ($socket) {
            return '--unix-socket ' . self::ADMIN_SOCKET . ' ' . escapeshellarg('http://localhost' . $path);
        }

        return escapeshellarg(self::ADMIN_ORIGIN . $path) . ' -H ' . escapeshellarg('Origin: ' . self::ADMIN_ORIGIN);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function request(SSHConnection $connection, string $method, string $path, ?array $payload = null, ?bool $retry = null): void
    {
        $this->run($connection, $this->curl($connection->server(), $method, $path, $payload), retry: $retry ?? in_array($method, self::IDEMPOTENT_METHODS, true));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function curl(Server $server, string $method, string $path, ?array $payload = null): string
    {
        $command = 'curl -fsS --max-time 30 -X ' . $method . ' ' . self::address($server->caddyAdminOnSocket(), $path);

        if ($payload !== null) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $command .= ' -H ' . escapeshellarg('Content-Type: application/json') . ' -d ' . escapeshellarg($json);
        }

        return $command;
    }
}
