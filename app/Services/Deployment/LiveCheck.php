<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use App\Stacks\Route;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

final class LiveCheck
{
    private const SECONDS = 15;

    public function ask(SSHConnection $connection, Application $application): ?LiveAnswer
    {
        $domain = trim((string) $application->domain);
        $served = $application->type->language()->route($application)->proxies() && $application->base_port !== null;

        if ($domain === '' && !$served) {
            return null;
        }

        $url = $domain === '' ? 'http://127.0.0.1:' . $application->base_port . '/' : 'https://' . $domain . '/';
        $command = $domain === ''
            ? 'curl -s -o /dev/null --max-time ' . self::SECONDS . " -w '%{http_code}' " . escapeshellarg($url)
            : 'curl -sk -o /dev/null --max-time ' . self::SECONDS . " -w '%{http_code}' --resolve " . escapeshellarg($domain . ':443:127.0.0.1') . ' ' . escapeshellarg($url);
        $status = $this->status($connection, $command);

        if ($status >= 500) {
            Sleep::for(2)->seconds();
            $status = $this->status($connection, $command);
        }

        if ($status < 100 || $status > 599) {
            return null;
        }

        return new LiveAnswer($url, $status, $status >= 500 ? $this->newestError($connection, $application, $status) : null);
    }

    private function status(SSHConnection $connection, string $command): int
    {
        return (int) trim($connection->execute($command, timeout: self::SECONDS + 5)->output);
    }

    private function newestError(SSHConnection $connection, Application $application, int $status): ?string
    {
        if ($application->type->language()->route($application) !== Route::PhpFastcgi) {
            return $status === 502 ? $this->wrongPort($connection, $application) : null;
        }

        return (strtolower((string) $application->framework) === 'laravel' ? $this->laravelError($connection, $application) : null)
            ?? $this->phpError($connection, $application);
    }

    private function wrongPort(SSHConnection $connection, Application $application): ?string
    {
        $unit = escapeshellarg(app(SystemdUnitBuilder::class)->unitName($application, 'web'));
        $script = 'cg=$(systemctl show -p ControlGroup --value ' . $unit . ' 2>/dev/null); [ -n "$cg" ] || exit 0; '
            . 'pids=$(tr "\\n" "|" < "/sys/fs/cgroup$cg/cgroup.procs" 2>/dev/null); [ -n "$pids" ] || exit 0; '
            . 'ss -ltnpH 2>/dev/null | grep -E "pid=(${pids%|})," | awk \'{print $4}\'';
        $ports = collect(preg_split('/\s+/', trim($connection->execute($script, sudo: true)->output)) ?: [])
            ->map(fn(string $address): ?int => preg_match('/:(\d+)$/', $address, $matches) === 1 ? (int) $matches[1] : null)
            ->filter()
            ->unique()
            ->values();
        $expected = (int) $application->base_port;

        if ($ports->isEmpty() || $ports->contains($expected)) {
            return null;
        }

        return 'The app listens on port ' . $ports->implode(', ') . ", not the port Coresmith gives it, {$expected}. Have it listen on the PORT environment variable.";
    }

    private function phpError(SSHConnection $connection, Application $application): ?string
    {
        $ident = 'coresmith-app-' . SafeName::assertSlug($application->slug) . '-php';
        $line = trim($connection->execute('journalctl -t ' . escapeshellarg($ident) . " --since '-2 min' -o cat --no-pager 2>/dev/null | grep -E 'PHP (Fatal|Parse) error' | tail -n 1", sudo: true)->output);

        return preg_match('/PHP (?:Fatal|Parse) error:\s+(.+)$/', $line, $matches) === 1 ? Str::limit(trim($matches[1]), 300) : null;
    }

    private function laravelError(SSHConnection $connection, Application $application): ?string
    {
        $paths = new ReleasePaths($application);
        $logs = escapeshellarg($paths->appDirectory($paths->current()) . '/storage/logs');
        $line = trim($connection->execute("f=\$(ls -t {$logs}/*.log 2>/dev/null | head -n 1); [ -n \"\$f\" ] && tail -n 400 \"\$f\" | grep -E '\\.(ERROR|CRITICAL|ALERT|EMERGENCY): ' | tail -n 1", sudo: true)->output);

        if (preg_match('/\.(?:ERROR|CRITICAL|ALERT|EMERGENCY): (.+?)(?: \{"|$)/', $line, $matches) !== 1) {
            return null;
        }

        return Str::limit(trim($matches[1]), 300);
    }
}
