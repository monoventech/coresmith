<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Tunnel;

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use SensitiveParameter;

final class Cloudflared
{
    public const UNIT = 'coresmith-tunnel';

    public const UNIT_FILE = '/etc/systemd/system/' . self::UNIT . '.service';

    public const DIRECTORY = '/etc/coresmith-tunnel';

    public const TOKEN_FILE = self::DIRECTORY . '/token.env';

    private const NEXT_TOKEN = self::TOKEN_FILE . '.next';

    private const NEXT_UNIT = self::DIRECTORY . '/unit.next';

    public function start(SSHConnection $connection, #[SensitiveParameter] string $token): void
    {
        $binary = $this->install($connection);

        $connection->execute('install -d -m 700 -o root -g root ' . self::DIRECTORY, sudo: true)->throw();
        $connection->uploadContent("TUNNEL_TOKEN={$token}\n", self::NEXT_TOKEN);
        $connection->uploadContent(self::unit($binary), self::NEXT_UNIT);
        $connection->execute(self::swap(), sudo: true)->throw();
    }

    public function stop(SSHConnection $connection): void
    {
        $connection->execute('systemctl disable --now ' . self::UNIT . ' >/dev/null 2>&1; rm -f ' . self::UNIT_FILE . ' ' . self::TOKEN_FILE . ' ' . self::NEXT_TOKEN . ' ' . self::NEXT_UNIT . ' && rmdir ' . self::DIRECTORY . ' 2>/dev/null; systemctl daemon-reload', sudo: true);
    }

    public static function unit(string $binary): string
    {
        return implode("\n", [
            '[Unit]',
            'Description=Coresmith tunnel to Cloudflare',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=notify',
            'DynamicUser=yes',
            'EnvironmentFile=' . self::TOKEN_FILE,
            "ExecStart={$binary} --no-autoupdate tunnel run",
            'Restart=always',
            'RestartSec=5',
            'TimeoutStartSec=0',
            'NoNewPrivileges=yes',
            'PrivateTmp=yes',
            'ProtectSystem=strict',
            'ProtectHome=yes',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);
    }

    public static function swap(): string
    {
        $token = escapeshellarg(self::TOKEN_FILE);
        $nextToken = escapeshellarg(self::NEXT_TOKEN);
        $unit = escapeshellarg(self::UNIT_FILE);
        $nextUnit = escapeshellarg(self::NEXT_UNIT);

        return implode(' && ', [
            'changed=0',
            "chmod 600 {$nextToken} {$nextUnit}",
            "{ if cmp -s {$nextToken} {$token}; then rm -f {$nextToken}; else mv -f {$nextToken} {$token} && changed=1; fi; }",
            "{ if cmp -s {$nextUnit} {$unit}; then rm -f {$nextUnit}; else install -m 644 {$nextUnit} {$unit} && rm -f {$nextUnit} && changed=1; fi; }",
            'systemctl daemon-reload',
            'systemctl enable --quiet ' . self::UNIT,
            '{ if [ "$changed" = 1 ] || ! systemctl is-active --quiet ' . self::UNIT . '; then systemctl restart --no-block ' . self::UNIT . '; fi; }',
        ]);
    }

    private function install(SSHConnection $connection): string
    {
        $found = trim($connection->execute('command -v cloudflared || true')->output);

        if (str_starts_with($found, '/')) {
            return $found;
        }

        $server = $connection->server();
        $packages = PackageManagerFactory::for($server);

        foreach ($this->repository($server->os_name) as $command) {
            $connection->execute($command, sudo: true)->throw();
        }

        $connection->execute($packages->update(), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
        $connection->execute($packages->install(['cloudflared']), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();

        $installed = trim($connection->execute('command -v cloudflared || true')->output);

        return str_starts_with($installed, '/') ? $installed : '/usr/bin/cloudflared';
    }

    /**
     * @return list<string>
     */
    private function repository(?string $osName): array
    {
        return match ($osName) {
            'ubuntu', 'debian' => [
                'install -d -m 0755 /usr/share/keyrings',
                "curl -fsSL 'https://pkg.cloudflare.com/cloudflare-main.gpg' -o /usr/share/keyrings/cloudflare-main.gpg",
                "printf 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main\\n' > /etc/apt/sources.list.d/cloudflared.list",
            ],
            'rocky', 'almalinux', 'fedora', 'amzn' => [
                "curl -fsSL 'https://pkg.cloudflare.com/cloudflared.repo' -o /etc/yum.repos.d/cloudflared.repo",
            ],
            default => throw UnsupportedOperatingSystemException::forOs($osName),
        };
    }
}
