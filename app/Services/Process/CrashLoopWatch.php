<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Models\Application;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

final class CrashLoopWatch
{
    public const CHECKS = 6;

    public const INTERVAL_SECONDS = 10;

    public const FAILURES = 3;

    public function __construct(private readonly ProcessManager $processes) {}

    public function watch(SSHConnection $connection, Application $application): ?CrashLoop
    {
        $units = $this->processes->unitNames($application);

        if ($units === []) {
            return null;
        }

        $baseline = [];
        $down = [];

        foreach ($units as $unit) {
            $baseline[$unit] = $this->restarts($connection, $unit);
            $down[$unit] = 0;
        }

        for ($check = 1; $check <= self::CHECKS; $check++) {
            Sleep::for(self::INTERVAL_SECONDS)->seconds();

            foreach ($units as $unit) {
                $active = trim($connection->execute('systemctl is-active ' . escapeshellarg($unit))->output) === 'active';
                $down[$unit] = $active ? 0 : $down[$unit] + 1;

                if ($down[$unit] >= self::FAILURES || $this->restarts($connection, $unit) - $baseline[$unit] >= self::FAILURES) {
                    return new CrashLoop($unit, $this->output($connection, $application, $unit), $this->outOfMemory($connection, $unit));
                }
            }
        }

        return null;
    }

    private function outOfMemory(SSHConnection $connection, string $unit): bool
    {
        return trim($connection->execute('systemctl show -p Result --value ' . escapeshellarg($unit))->output) === 'oom-kill';
    }

    private function restarts(SSHConnection $connection, string $unit): int
    {
        return (int) trim($connection->execute('systemctl show -p NRestarts --value ' . escapeshellarg($unit))->output);
    }

    private function output(SSHConnection $connection, Application $application, string $unit): string
    {
        $slug = SafeName::assertSlug($application->slug);
        $process = Str::after($unit, 'coresmith-app-' . $slug . '-');

        if ($process === 'fpm') {
            return $connection->execute('journalctl -u ' . escapeshellarg($unit) . ' --no-pager -n 20 2>/dev/null || true', sudo: true)->output;
        }

        return $this->processes->processLog($connection, $application, $unit, 20);
    }
}
