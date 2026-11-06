<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

final class PanelServices
{
    public const HORIZON = 'coresmith-horizon';

    public const REVERB = 'coresmith-reverb';

    private const EPERM = 1;

    public function __construct(private readonly MasterSupervisorRepository $masters) {}

    /**
     * @return list<string>
     */
    public function active(): array
    {
        return array_values(array_filter(
            [self::HORIZON, self::REVERB],
            fn(string $unit): bool => $this->isActive($unit),
        ));
    }

    public function down(): void
    {
        Artisan::call('down', ['--retry' => 60]);
    }

    public function up(): void
    {
        Artisan::call('up');
    }

    public function isDown(): bool
    {
        return app()->isDownForMaintenance();
    }

    public function drain(int $timeout): void
    {
        $pids = $this->masterPids();

        if ($pids === []) {
            return;
        }

        Artisan::call('horizon:terminate');

        for ($attempt = 0; $attempt < $timeout; $attempt++) {
            if (!$this->anyAlive($pids)) {
                return;
            }

            Sleep::for(1)->second();
        }

        if ($this->anyAlive($pids)) {
            throw new UpdateFailed('drain', 'A queued job is still running after ' . self::duration($timeout) . ', so Coresmith stopped before changing anything. Horizon finishes that job before it takes new ones, then restarts on its own. Let it finish, then update again.');
        }
    }

    public static function now(): int
    {
        return intdiv(hrtime(true), 1000);
    }

    public function startedAt(string $unit): int
    {
        $result = Process::timeout(10)->run(['systemctl', 'show', $unit, '--property=ExecMainStartTimestampMonotonic', '--value']);

        return $result->successful() ? (int) trim($result->output()) : 0;
    }

    /**
     * @param  list<string>  $units
     */
    public function restart(array $units, int $timeout, ?int $since = null): void
    {
        $since ??= self::now();

        if (in_array(self::HORIZON, $units, true)) {
            Artisan::call('horizon:terminate');
        }

        if (in_array(self::REVERB, $units, true)) {
            Artisan::call('reverb:restart');
        }

        $pending = $units;
        $attempts = intdiv($timeout, 2);

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            $pending = array_values(array_filter($pending, fn(string $unit): bool => !$this->startedSince($unit, $since)));

            if ($pending === []) {
                return;
            }

            if ($attempt < $attempts) {
                if (in_array(self::HORIZON, $pending, true)) {
                    Artisan::call('horizon:terminate');
                }

                Sleep::for(2)->seconds();
            }
        }

        throw new UpdateFailed('restart', "{$pending[0]} didn't come back within " . self::duration($timeout) . ". Inspect it with journalctl -u {$pending[0]} -n 50.");
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' ' . Str::plural('second', $seconds);
        }

        $minutes = intdiv($seconds, 60);

        return $minutes . ' ' . Str::plural('minute', $minutes);
    }

    private function startedSince(string $unit, int $since): bool
    {
        return $this->startedAt($unit) > $since && $this->isActive($unit);
    }

    private function isActive(string $unit): bool
    {
        return Process::timeout(10)->run(['systemctl', 'is-active', '--quiet', $unit])->successful();
    }

    /**
     * @return list<int>
     */
    private function masterPids(): array
    {
        $pids = [];

        foreach ((array) $this->masters->all() as $master) {
            if (Str::startsWith((string) ($master->name ?? ''), MasterSupervisor::basename()) && (int) ($master->pid ?? 0) > 0) {
                $pids[] = (int) $master->pid;
            }
        }

        return $pids;
    }

    /**
     * @param  list<int>  $pids
     */
    private function anyAlive(array $pids): bool
    {
        foreach ($pids as $pid) {
            if (posix_kill($pid, 0) || posix_get_last_error() === self::EPERM) {
                return true;
            }
        }

        return false;
    }
}
