<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Services\Server\AptPackageManager;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\SSHConnection;

final class CaddyInstaller
{
    public const CADDYFILE = '/etc/caddy/Caddyfile';

    public const PANEL_FILE = '/etc/caddy/panel.caddy';

    public const CONFIG_FILE = '/etc/caddy/caddy-config.json';

    public function install(SSHConnection $connection): void
    {
        $this->installPackage($connection);
        $this->writeBootstrapConfig($connection);
        $this->enableService($connection);
        $this->verifyAdminApi($connection);
    }

    private function installPackage(SSHConnection $connection): void
    {
        $server = $connection->server();
        $packages = PackageManagerFactory::for($server);

        foreach ($this->repositorySetup($server->os_name) as $command) {
            $connection->execute($command, sudo: true)->throw();
        }

        $connection->execute($packages->update(), sudo: true)->throw();
        $connection->execute($packages->install(['caddy']), sudo: true)->throw();
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
            ],
            'rocky', 'almalinux', 'fedora', 'amzn' => [
                "dnf install -y 'dnf-command(copr)' || true",
                'dnf copr enable @caddy/caddy -y',
            ],
            default => throw UnsupportedOperatingSystemException::forOs($osName),
        };
    }

    private function writeBootstrapConfig(SSHConnection $connection): void
    {
        $connection->execute('mkdir -p /etc/caddy /var/log/caddy', sudo: true)->throw();

        $connection->uploadContent($this->caddyfile(), self::CADDYFILE);
        $connection->uploadContent($this->panelSite(), self::PANEL_FILE);

        $connection->execute(
            'test -f '.self::CONFIG_FILE.' || echo "{}" > '.self::CONFIG_FILE,
            sudo: true,
        )->throw();

        $connection->execute('chmod 644 '.self::CADDYFILE.' '.self::PANEL_FILE, sudo: true)->throw();
    }

    private function enableService(SSHConnection $connection): void
    {
        $connection->execute('systemctl enable caddy', sudo: true)->throw();
        $connection->execute('systemctl restart caddy', sudo: true)->throw();
    }

    private function verifyAdminApi(SSHConnection $connection): void
    {
        $connection->execute(
            'curl -fsS --max-time 10 http://localhost:'.CaddyApiClient::ADMIN_PORT.'/config/ >/dev/null',
        )->throw();
    }

    public function caddyfile(): string
    {
        $port = CaddyApiClient::ADMIN_PORT;

        return <<<CADDY
        {
            admin localhost:{$port} {
                origins localhost:{$port}
                enforce_origin
            }
            email {\$ACME_EMAIL}

            default_sni ""
            auto_https disable_redirects

            log {
                output file /var/log/caddy/access.log
                format json
            }

            servers {
                timeouts {
                    read_body   10s
                    read_header 5s
                    write       30s
                    idle        120s
                }

                max_header_size 64KB
            }
        }

        :80 {
            respond 444
        }

        :443 {
            tls internal {
                on_demand
            }
            respond 444
        }

        import /etc/caddy/panel.caddy

        CADDY;
    }

    public function panelSite(): string
    {
        return <<<'CADDY'
        # Coresmith panel site. Managed applications are never added here —
        # they are pushed through the Admin API at runtime.

        CADDY;
    }
}
