<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\FrontDoor;

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Str;

final class WireGuard
{
    public const INTERFACE = 'coresmith-door';

    public const CONFIG = '/etc/wireguard/coresmith-door.conf';

    public const KEY = '/etc/wireguard/coresmith-door.key';

    private const NEXT = self::CONFIG . '.next';

    private const UNIT = 'wg-quick@' . self::INTERFACE;

    private const DISTRIBUTIONS = ['ubuntu', 'debian', 'rocky', 'almalinux', 'fedora', 'amzn'];

    public function install(SSHConnection $connection): void
    {
        if (str_starts_with(trim($connection->execute('command -v wg || true')->output), '/')) {
            return;
        }

        $server = $connection->server();

        if (!in_array($server->os_name, self::DISTRIBUTIONS, true)) {
            throw UnsupportedOperatingSystemException::forOs($server->os_name);
        }

        $packages = PackageManagerFactory::for($server);

        $connection->execute($packages->update(), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
        $connection->execute($packages->install(['wireguard-tools']), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
    }

    public function publicKey(SSHConnection $connection): string
    {
        $key = escapeshellarg(self::KEY);

        return trim($connection->execute("install -d -m 700 /etc/wireguard && (umask 077; [ -s {$key} ] || wg genkey > {$key}) && wg pubkey < {$key}", sudo: true)->throw()->output);
    }

    public function clash(SSHConnection $connection, string $subnet, ?int $port): ?string
    {
        $name = $connection->server()->name;
        $prefix = preg_quote(Str::beforeLast(Str::before($subnet, '/'), '.') . '.', '/');
        $lines = [
            ...preg_split('/\r\n|\n|\r/', $connection->execute('ip -4 -o addr show || true')->output) ?: [],
            ...preg_split('/\r\n|\n|\r/', $connection->execute('ip -4 route show || true')->output) ?: [],
        ];

        foreach ($lines as $line) {
            if (!str_contains($line, self::INTERFACE) && preg_match('/\b' . $prefix . '\d{1,3}\b/', $line) === 1) {
                return "Another network on {$name} uses {$subnet}, which Coresmith needs for the link. Move that network to another range, then try again.";
            }
        }

        if ($port === null || preg_match('/:' . $port . '\s/', $connection->execute('ss -Hulpn || true')->output) !== 1) {
            return null;
        }

        $ours = trim($connection->execute('wg show ' . self::INTERFACE . ' listen-port 2>/dev/null || true', sudo: true)->output);

        return $ours === (string) $port ? null : "Something else on {$name} listens on UDP {$port}, which the link needs. Stop it or move it to another port, then try again.";
    }

    /**
     * @param  list<array{key: string, allowed: string, endpoint?: string, keepalive?: int}>  $peers
     */
    public function configure(SSHConnection $connection, string $address, ?int $listenPort, array $peers): void
    {
        $lines = ['[Interface]', "Address = {$address}/24"];

        if ($listenPort !== null) {
            $lines[] = "ListenPort = {$listenPort}";
        }

        $lines[] = 'PostUp = wg set %i private-key ' . self::KEY;

        foreach ($peers as $peer) {
            array_push($lines, '', '[Peer]', "PublicKey = {$peer['key']}", "AllowedIPs = {$peer['allowed']}");

            if (isset($peer['endpoint'])) {
                $lines[] = "Endpoint = {$peer['endpoint']}";
            }

            if (isset($peer['keepalive'])) {
                $lines[] = "PersistentKeepalive = {$peer['keepalive']}";
            }
        }

        $connection->execute('install -d -m 700 /etc/wireguard', sudo: true)->throw();
        $connection->uploadContent(implode("\n", $lines) . "\n", self::NEXT);
        $connection->execute(self::swap(), sudo: true)->throw();
    }

    public function handshakeAge(SSHConnection $connection, string $peerKey): ?int
    {
        $output = $connection->execute('wg show ' . self::INTERFACE . ' latest-handshakes 2>/dev/null || true', sudo: true)->output;

        foreach (preg_split('/\r\n|\n|\r/', trim($output)) ?: [] as $line) {
            [$key, $at] = array_pad(preg_split('/\s+/', trim($line)) ?: [], 2, '');

            if ($key === $peerKey && ctype_digit($at) && (int) $at > 0) {
                return max(0, now()->getTimestamp() - (int) $at);
            }
        }

        return null;
    }

    public function remove(SSHConnection $connection): void
    {
        $files = implode(' ', array_map(escapeshellarg(...), [self::CONFIG, self::KEY, self::NEXT]));

        $connection->execute('systemctl disable --now ' . self::UNIT . " 2>/dev/null; rm -f {$files}", sudo: true)->throw();
    }

    public static function swap(): string
    {
        $config = escapeshellarg(self::CONFIG);
        $next = escapeshellarg(self::NEXT);

        return implode(' && ', [
            "chmod 600 {$next}",
            "{ if cmp -s {$next} {$config}; then rm -f {$next}; changed=0; else mv -f {$next} {$config}; changed=1; fi; }",
            'systemctl enable --quiet ' . self::UNIT,
            '{ if [ "$changed" = 1 ] || ! systemctl is-active --quiet ' . self::UNIT . '; then systemctl restart ' . self::UNIT . '; fi; }',
        ]);
    }
}
