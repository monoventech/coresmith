<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\SSH\SSHConnection;
use RuntimeException;

final class ProtectionCheck
{
    public const META = 'protections';

    public const APT_FAMILY = ['ubuntu', 'debian'];

    private const ENABLED = ['enabled', 'enabled-runtime'];

    private const YES = ['yes', 'true', '1', 'on'];

    public function __construct(private readonly SecurityHardening $hardening) {}

    /**
     * @return array{ssh: string|null, fail2ban: string, kernel: string, kernel_off: list<string>, patches: string, controls: string}
     */
    public function read(SSHConnection $connection): array
    {
        $server = $connection->server();

        return $this->parse($server, $connection->execute($this->script($server), sudo: true)->output);
    }

    /**
     * @param  array<string, mixed>  $reading
     */
    public function record(Server $server, array $reading): void
    {
        $fresh = $server->fresh() ?? $server;

        $fresh->forceFill(['meta' => [...(array) $fresh->meta, self::META => [...$reading, 'checked_at' => now()->toIso8601String()]]])->save();
    }

    public function script(Server $server): string
    {
        $lines = [
            "echo '== fail2ban'",
            'systemctl is-active fail2ban 2>/dev/null || true',
            'fail2ban-client status sshd >/dev/null 2>&1 && echo jail || echo no-jail',
            "echo '== kernel'",
            'sysctl ' . implode(' ', array_keys($this->kernel())) . ' 2>/dev/null || true',
            "echo '== patches'",
            ...(in_array($server->os_name, self::APT_FAMILY, true) ? [
                'apt-config shell UNATTENDED APT::Periodic::Unattended-Upgrade PERIODIC APT::Periodic::Enable 2>/dev/null || true',
                'command -v unattended-upgrade >/dev/null 2>&1 && echo installed || echo missing',
                'systemctl is-enabled apt-daily-upgrade.timer 2>/dev/null || true',
            ] : [
                'for unit in dnf-automatic-install.timer dnf-automatic.timer dnf5-automatic.timer; do state=$(systemctl is-enabled "$unit" 2>/dev/null); printf \'%s %s\n\' "$unit" "${state:-missing}"; done',
                "grep -hE '^[[:space:]]*apply_updates[[:space:]]*=' /usr/share/dnf5/dnf5-plugins/automatic.conf /etc/dnf/automatic.conf 2>/dev/null | tail -n 1",
            ]),
        ];

        if (!$server->runs_coresmith) {
            $lines[] = "echo '== ssh'";
            $lines[] = 'sshd -T 2>/dev/null || true';
        }

        $lines[] = "echo '== controls'";
        $lines[] = '[ -S ' . CaddyApiClient::ADMIN_SOCKET . ' ] && echo socket || echo no-socket';
        $lines[] = "[ -n \"$(ss -ltnH '( sport = :" . CaddyApiClient::ADMIN_PORT . " )' 2>/dev/null)\" ] && echo tcp || echo no-tcp";

        return implode("\n", $lines);
    }

    /**
     * @return array{ssh: string|null, fail2ban: string, kernel: string, kernel_off: list<string>, patches: string, controls: string}
     */
    public function parse(Server $server, string $output): array
    {
        $sections = $this->sections($output);

        if (!isset($sections['controls'])) {
            throw new RuntimeException("The protection check on {$server->name} stopped before it finished.");
        }

        $kernelOff = $this->kernelOff($sections['kernel'] ?? []);

        return [
            'ssh' => $server->runs_coresmith ? null : $this->ssh($sections['ssh'] ?? []),
            'fail2ban' => in_array('active', $sections['fail2ban'] ?? [], true) && in_array('jail', $sections['fail2ban'] ?? [], true) ? 'on' : 'off',
            'kernel' => $kernelOff === [] ? 'on' : 'off',
            'kernel_off' => $kernelOff,
            'patches' => $this->patches($server, $sections['patches'] ?? []) ? 'on' : 'off',
            'controls' => in_array('socket', $sections['controls'], true) && !in_array('tcp', $sections['controls'], true) ? 'on' : 'off',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function kernel(): array
    {
        $settings = [];

        foreach (explode("\n", $this->hardening->sysctlConfig()) as $line) {
            if (preg_match('/^\s*([\w.]+)\s*=\s*(\S+)\s*$/', $line, $match) === 1) {
                $settings[$match[1]] = $match[2];
            }
        }

        return $settings;
    }

    /**
     * @return array<string, list<string>>
     */
    private function sections(string $output): array
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^== (\w+)$/', $line, $match) === 1) {
                $current = $match[1];
                $sections[$current] = [];
            } elseif ($current !== null && $line !== '') {
                $sections[$current][] = $line;
            }
        }

        return $sections;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function kernelOff(array $lines): array
    {
        $actual = [];

        foreach ($lines as $line) {
            if (preg_match('/^([\w.]+)\s*=\s*(.*)$/', $line, $match) === 1) {
                $actual[$match[1]] = trim($match[2]);
            }
        }

        return array_keys(array_filter(
            $this->kernel(),
            fn(string $expected, string $key): bool => array_key_exists($key, $actual) && $actual[$key] !== $expected,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @param  list<string>  $lines
     */
    private function ssh(array $lines): string
    {
        $settings = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line, 2) ?: [];

            if (count($parts) === 2) {
                $settings[strtolower($parts[0])] = strtolower($parts[1]);
            }
        }

        return $this->hardening->passwordProblem($settings) === null ? 'on' : 'off';
    }

    /**
     * @param  list<string>  $lines
     */
    private function patches(Server $server, array $lines): bool
    {
        if (in_array($server->os_name, self::APT_FAMILY, true)) {
            $values = [];

            foreach ($lines as $line) {
                if (preg_match("/^(\w+)='([^']*)'$/", $line, $match) === 1) {
                    $values[$match[1]] = $match[2];
                }
            }

            return (int) ($values['UNATTENDED'] ?? 0) > 0
                && ($values['PERIODIC'] ?? '1') !== '0'
                && in_array('installed', $lines, true)
                && array_intersect(self::ENABLED, $lines) !== [];
        }

        $units = [];
        $apply = null;

        foreach ($lines as $line) {
            if (preg_match('/^(\S+\.timer) (\S+)$/', $line, $match) === 1) {
                $units[$match[1]] = $match[2];
            } elseif (preg_match('/^apply_updates\s*=\s*(\S+)/i', $line, $match) === 1) {
                $apply = strtolower($match[1]);
            }
        }

        $enabled = fn(string $unit): bool => in_array($units[$unit] ?? 'missing', self::ENABLED, true);

        return $enabled('dnf-automatic-install.timer')
            || (($enabled('dnf-automatic.timer') || $enabled('dnf5-automatic.timer')) && in_array($apply, self::YES, true));
    }
}
