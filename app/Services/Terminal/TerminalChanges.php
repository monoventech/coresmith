<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Models\Server;
use App\Services\Process\SystemdUnitBuilder;

final class TerminalChanges
{
    public function __construct(private readonly SystemdUnitBuilder $units) {}

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return list<array{key: string, title: string, detail: string}>
     */
    public function between(Server $server, array $before, array $after): array
    {
        $changed = fn(string $key): bool => ($before[$key] ?? '') !== '' && ($after[$key] ?? '') !== '' && $before[$key] !== $after[$key];
        $stopped = array_values(array_diff($this->list($before['processes'] ?? ''), $this->list($after['processes'] ?? '')));

        return array_values(array_filter([
            (int) ($before['key'] ?? 0) > 0 && ($after['key'] ?? '') === '0' ? self::lostKey($server) : null,
            $changed('sudo') ? ['key' => 'sudo', 'title' => "Coresmith's account", 'detail' => "The server's sudo rules changed. If Coresmith can no longer act as root there, its next check on the server says so."] : null,
            $changed('units') ? ['key' => 'units', 'title' => "The apps' process settings", 'detail' => "Settings Coresmith keeps for the apps' processes changed. Each app's next deploy writes them again."] : null,
            $changed('jobs') ? ['key' => 'jobs', 'title' => "The apps' scheduled jobs", 'detail' => "Scheduled jobs Coresmith keeps for the apps changed. Each app's next deploy writes them again."] : null,
            $changed('settings') ? ['key' => 'settings', 'title' => "The apps' settings", 'detail' => "Files Coresmith keeps for the apps, such as their environment variables, changed. Each app's next deploy writes them again."] : null,
            $changed('folders') ? ['key' => 'folders', 'title' => "The apps' folders", 'detail' => "An app's folder was removed, or its owner or permissions changed, and the app may stop working. Deploy it again from its page. If that fails, the deploy's log says why."] : null,
            $changed('web') ? ['key' => 'web', 'title' => "The web server's settings", 'detail' => "The web server's settings changed. Coresmith may replace these changes the next time it updates an app's addresses."] : null,
            $changed('firewall') ? ['key' => 'firewall', 'title' => 'The firewall rules', 'detail' => "The firewall rules changed. The server's Hardening tab shows what's open now, and can put Coresmith's rules back."] : null,
            ($before['running'] ?? '') === 'active' && ($after['running'] ?? '') !== '' && $after['running'] !== 'active' ? ['key' => 'running', 'title' => 'The web server', 'detail' => "The web server isn't running, so nobody can open the apps on this server. Reboot the server, or open a terminal and start it again."] : null,
            $stopped !== [] ? ['key' => 'processes', 'title' => "The apps' processes", 'detail' => 'These stopped: ' . implode(', ', array_map(fn(string $unit): string => $this->name($server, $unit), $stopped)) . ". Start them again from each app's page."] : null,
        ]));
    }

    /**
     * @return array{key: string, title: string, detail: string}
     */
    public static function lostKey(Server $server): array
    {
        return ['key' => 'key', 'title' => "Coresmith's sign-in key", 'detail' => "Coresmith's key is gone from {$server->name}, so Coresmith can't reach it. Put the key from the server's Hardening tab back in {$server->ssh_user}'s authorized keys."];
    }

    /**
     * @return array{key: string, title: string, detail: string}
     */
    public static function unchecked(): array
    {
        return ['key' => 'unchecked', 'title' => 'What Coresmith looks after', 'detail' => "Coresmith couldn't check what changed this time. Its regular checks on the server still run."];
    }

    /**
     * @return list<string>
     */
    private function list(string $units): array
    {
        return array_values(array_filter(explode(',', $units)));
    }

    private function name(Server $server, string $unit): string
    {
        foreach ($server->applications as $application) {
            foreach ((array) $application->processes as $process) {
                $name = (string) ($process['name'] ?? '');

                if ($name !== '' && rescue(fn(): string => $this->units->unitName($application, $name), '', report: false) === $unit) {
                    return "{$application->name}'s {$name}";
                }
            }

            if (rescue(fn(): string => $this->units->unitName($application, 'web'), '', report: false) === $unit) {
                return "{$application->name}'s web";
            }
        }

        return $unit;
    }
}
