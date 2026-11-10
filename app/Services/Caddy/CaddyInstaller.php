<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\InstanceSetting;
use App\Services\Server\AptPackageManager;
use App\Services\Server\MetricsCollector;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use stdClass;

final class CaddyInstaller
{
    public const CONFIG_FILE = '/etc/caddy/caddy-config.json';

    public const SERVER = 'coresmith';

    public const UNKNOWN_HOSTS_ROUTE = 'coresmith-unknown';

    public const SERVICE_DROP_IN = '/etc/systemd/system/caddy.service.d/coresmith.conf';

    public const ADMIN_TMPFILES = '/etc/tmpfiles.d/coresmith-caddy.conf';

    public const ADMIN_TMPFILES_ENTRY = "d /run/caddy-admin 0700 caddy caddy -\n";

    public const THIS_MACHINE_ADMIN_TMPFILES_ENTRY = "d /run/caddy-admin 0750 caddy coresmith -\n";

    public const ADAPT_CORESMITH_SITE = 'caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile 2>/dev/null';

    public function install(SSHConnection $connection): void
    {
        $this->installPackage($connection);

        CaddyApiClient::exclusively($connection->server(), function () use ($connection): void {
            $this->writeConfig($connection);
            $this->enableService($connection);
        });

        $this->verifyAdminApi($connection);
    }

    private function installPackage(SSHConnection $connection): void
    {
        $server = $connection->server();
        $packages = PackageManagerFactory::for($server);

        foreach ($this->repositorySetup($server->os_name) as $command) {
            $connection->execute($command, sudo: true)->throw();
        }

        $connection->execute($packages->update(), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
        $connection->execute($packages->install(['caddy']), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
    }

    /**
     * @return list<string>
     */
    private function repositorySetup(?string $osName): array
    {
        return match ($osName) {
            'ubuntu', 'debian' => [
                (new AptPackageManager)->install(['debian-keyring', 'debian-archive-keyring', 'apt-transport-https', 'curl', 'gnupg']),
                "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg",
                "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list",
                "printf 'Package: caddy\\nPin: origin dl.cloudsmith.io\\nPin-Priority: 1001\\n' > /etc/apt/preferences.d/caddy",
            ],
            'rocky', 'almalinux', 'fedora', 'amzn' => [
                "dnf install -y 'dnf-command(copr)' || true",
                'dnf copr enable @caddy/caddy -y',
            ],
            default => throw UnsupportedOperatingSystemException::forOs($osName),
        };
    }

    private function writeConfig(SSHConnection $connection): void
    {
        $logs = dirname(MetricsCollector::ACCESS_LOG);

        $connection->execute('mkdir -p /etc/caddy ' . $logs . ' ' . dirname(self::SERVICE_DROP_IN) . ' && chown caddy:caddy ' . $logs, sudo: true)->throw();

        $current = $connection->execute('cat ' . self::CONFIG_FILE . ' 2>/dev/null || true', sudo: true)->output;
        $email = InstanceSetting::get('notification_email');
        $config = $connection->server()->runs_coresmith
            ? $this->thisMachineConfig(CoresmithSite::fromAdapted($connection->execute(self::ADAPT_CORESMITH_SITE, sudo: true)->output), $email, $current)
            : $this->config($email, $current);

        $connection->uploadContent($config, self::CONFIG_FILE);
        $connection->uploadContent($this->serviceDropIn(), self::SERVICE_DROP_IN);

        $connection->execute('chmod 644 ' . self::CONFIG_FILE . ' ' . self::SERVICE_DROP_IN, sudo: true)->throw();

        $this->prepareAdminSocket($connection);

        $server = $connection->server();
        $server->forceFill(['meta' => [...(array) $server->meta, 'caddy_admin' => 'socket', ...($server->runs_coresmith ? [] : ['caddy_unknown_hosts' => 'closed'])]])->save();
    }

    public function prepareAdminSocket(SSHConnection $connection): void
    {
        $connection->uploadContent($connection->server()->runs_coresmith ? self::THIS_MACHINE_ADMIN_TMPFILES_ENTRY : self::ADMIN_TMPFILES_ENTRY, self::ADMIN_TMPFILES);
        $connection->execute('chmod 644 ' . self::ADMIN_TMPFILES . ' && systemd-tmpfiles --create ' . self::ADMIN_TMPFILES, sudo: true)->throw();
    }

    private function enableService(SSHConnection $connection): void
    {
        $connection->execute('systemctl daemon-reload', sudo: true)->throw();
        $connection->execute('systemctl enable caddy', sudo: true)->throw();
        $connection->execute('systemctl restart caddy', sudo: true)->throw();
    }

    private function verifyAdminApi(SSHConnection $connection): void
    {
        $connection->execute(CaddyApiClient::probe($connection->server()), sudo: true)->throw();
    }

    public function config(?string $email = null, string $current = ''): string
    {
        $routes = array_values(array_filter(
            $this->existingRoutes($current),
            fn(mixed $route): bool => !$route instanceof stdClass || ($route->{'@id'} ?? null) !== self::UNKNOWN_HOSTS_ROUTE,
        ));
        $config = $this->base(['listen' => CaddyApiClient::ADMIN_LISTEN], [...$routes, self::unknownHostsRoute()]);
        $issuers = self::issuers($email);

        if ($issuers !== null) {
            $config['apps']['tls'] = ['automation' => ['policies' => [['issuers' => $issuers]]]];
        }

        return $this->encode($config);
    }

    public function thisMachineConfig(CoresmithSite $site, ?string $email, string $current = ''): string
    {
        $apps = array_values(array_filter(
            $this->existingRoutes($current),
            fn(mixed $route): bool => !$route instanceof stdClass || !str_starts_with((string) ($route->{'@id'} ?? ''), 'coresmith-'),
        ));
        $issuers = self::issuers($email);
        $policies = [];

        foreach ($apps as $route) {
            $id = $route instanceof stdClass ? ($route->{'@id'} ?? null) : null;
            $hosts = $route instanceof stdClass ? CoresmithSite::hosts($route) : [];

            if (is_string($id) && $hosts !== []) {
                $policies[] = self::appPolicy($id, $hosts, $issuers);
            }
        }

        $config = $this->base($site->admin(), [$site->siteRoute(), ...$apps, $site->anyRoute()]);
        $config['logging']['logs']['access']['exclude'] = [CoresmithSite::LOGGER_NAME];
        $config['logging']['logs'][CoresmithSite::LOGGER] = $site->logger();
        $config['apps']['http']['servers'][CoresmithSite::INTERNAL_SERVER] = $site->internalServer();
        $config['apps']['tls'] = [
            'automation' => [
                'policies' => [...$policies, ...$site->sitePolicies(), $site->anyPolicy()],
                'on_demand' => $site->onDemand(),
            ],
        ];

        return $this->encode($config);
    }

    /**
     * @return array{'@id': string, handle: list<array{handler: string, abort: bool}>}
     */
    public static function unknownHostsRoute(): array
    {
        return ['@id' => self::UNKNOWN_HOSTS_ROUTE, 'handle' => [['handler' => 'static_response', 'abort' => true]]];
    }

    /**
     * @param  list<string>  $hosts
     * @param  list<array<string, string>>|null  $issuers
     * @return array<string, mixed>
     */
    public static function appPolicy(string $routeId, array $hosts, ?array $issuers): array
    {
        $policy = ['@id' => $routeId . '-tls', 'subjects' => $hosts];

        if ($issuers !== null) {
            $policy['issuers'] = $issuers;
        }

        return $policy;
    }

    /**
     * @return list<array<string, string>>|null
     */
    public static function issuers(?string $email): ?array
    {
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\s{}"]/', $email) === 1) {
            return null;
        }

        return [
            ['module' => 'acme', 'email' => $email],
            ['module' => 'acme', 'ca' => 'https://acme.zerossl.com/v2/DV90', 'email' => $email],
        ];
    }

    /**
     * @param  array<string, mixed>|stdClass  $admin
     * @param  list<mixed>  $routes
     * @return array<string, mixed>
     */
    private function base(array|stdClass $admin, array $routes): array
    {
        return [
            'admin' => $admin,
            'logging' => [
                'logs' => [
                    'default' => ['exclude' => ['http.log.access']],
                    'access' => [
                        'writer' => ['output' => 'file', 'filename' => MetricsCollector::ACCESS_LOG],
                        'encoder' => ['format' => 'json'],
                        'include' => ['http.log.access'],
                    ],
                ],
            ],
            'apps' => [
                'http' => [
                    'servers' => [
                        self::SERVER => [
                            'listen' => [':443'],
                            'routes' => $routes,
                            'read_timeout' => '10s',
                            'read_header_timeout' => '5s',
                            'write_timeout' => '30s',
                            'idle_timeout' => '120s',
                            'max_header_bytes' => 65536,
                            'tls_connection_policies' => [['protocol_min' => 'tls1.2']],
                            'logs' => new stdClass,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function encode(array $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public function serviceDropIn(): string
    {
        $file = self::CONFIG_FILE;

        return <<<UNIT
        [Service]
        ExecStart=
        ExecStart=/usr/bin/caddy run --environ --config {$file}
        ExecReload=
        ExecReload=/usr/bin/caddy reload --config {$file} --force

        UNIT;
    }

    /**
     * @return list<mixed>
     */
    private function existingRoutes(string $current): array
    {
        $node = json_decode($current);

        foreach (['apps', 'http', 'servers', self::SERVER, 'routes'] as $key) {
            if (!$node instanceof stdClass || !property_exists($node, $key)) {
                return [];
            }

            $node = $node->{$key};
        }

        return is_array($node) && array_is_list($node) ? $node : [];
    }
}
