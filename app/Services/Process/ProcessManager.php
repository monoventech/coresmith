<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Services\Deployment\LogSanitizer;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

final class ProcessManager
{
    public const START_GRACE_SECONDS = 3;

    public const START_LOG_LINES = 30;

    public function __construct(
        private readonly SystemdUnitBuilder $units,
        private readonly FpmPoolBuilder $fpm,
        private readonly ProcessStartAdvice $advice = new ProcessStartAdvice,
        private readonly LogSanitizer $sanitizer = new LogSanitizer,
    ) {}

    public function install(SSHConnection $connection, Application $application): void
    {
        $connection->execute('mkdir -p /var/coresmith/logs/' . SafeName::assertSlug($application->slug), sudo: true)->throw();

        if ($application->type === ApplicationType::Php) {
            $connection->execute('mkdir -p /etc/php-fpm.d /var/run/php', sudo: true)->throw();
            $connection->uploadContent($this->fpm->pool($application), $this->fpm->poolPath($application));
            $connection->uploadContent(
                $this->fpm->unit($application),
                SystemdUnitBuilder::UNIT_DIR . '/' . $this->fpm->unitName($application) . '.service',
            );
        }

        $webCommand = $this->units->webCommand($application);

        if ($webCommand !== null) {
            $connection->uploadContent(
                $this->units->build($application, 'web', $webCommand),
                $this->units->unitPath($application, 'web'),
            );
        }

        foreach ($this->workerProcesses($application) as $name => $command) {
            $connection->uploadContent(
                $this->units->build($application, $name, $command),
                $this->units->unitPath($application, $name),
            );
        }

        $connection->execute('systemctl daemon-reload', sudo: true)->throw();
    }

    public function start(SSHConnection $connection, Application $application): void
    {
        foreach ($this->unitNames($application) as $unit) {
            $connection->execute('systemctl enable --now ' . escapeshellarg($unit), sudo: true)->throw();
        }

        $application->forceFill(['power_state' => PowerState::Active])->save();
    }

    public function stop(SSHConnection $connection, Application $application, PowerState $state = PowerState::Stopped): void
    {
        foreach ($this->unitNames($application) as $unit) {
            $connection->execute('systemctl stop ' . escapeshellarg($unit), sudo: true)->throw();
        }

        $application->forceFill(['power_state' => $state])->save();
    }

    public function refresh(SSHConnection $connection, Application $application, bool $restart = false): void
    {
        $fpm = $application->type === ApplicationType::Php ? $this->fpm->unitName($application) : null;

        foreach ($this->unitNames($application) as $unit) {
            $connection->execute('systemctl enable ' . escapeshellarg($unit), sudo: true)->throw();
            $connection->execute('systemctl ' . ($unit === $fpm && !$restart ? 'reload-or-restart ' : 'restart ') . escapeshellarg($unit), sudo: true);
        }
    }

    public function verifyStarted(SSHConnection $connection, Application $application): void
    {
        $units = $this->unitNames($application);

        if ($units === []) {
            return;
        }

        Sleep::for(self::START_GRACE_SECONDS)->seconds();

        $fpm = $application->type === ApplicationType::Php ? $this->fpm->unitName($application) : null;

        foreach ($units as $unit) {
            $state = trim($connection->execute('systemctl is-active ' . escapeshellarg($unit))->output);

            if ($state === 'active') {
                continue;
            }

            $journal = $connection->execute(
                'journalctl -u ' . escapeshellarg($unit) . " --no-pager -n 30 --since '2 minutes ago' 2>/dev/null || true",
                sudo: true,
            )->output;
            $log = $unit === $fpm ? '' : trim($this->sanitizer->sanitize($this->processLog($connection, $application, $unit, self::START_LOG_LINES), $application));
            $output = $log === '' ? $journal : trim(rtrim($journal) . "\n" . $log);

            throw new ProcessDidNotStart($unit, $state === '' ? 'unknown' : $state, $output, $this->advice->for($application, $output));
        }
    }

    public function processLog(SSHConnection $connection, Application $application, string $unit, int $lines): string
    {
        $slug = SafeName::assertSlug($application->slug);
        $process = SafeName::assertProcess(Str::after($unit, 'coresmith-app-' . $slug . '-'));
        $log = escapeshellarg('/var/coresmith/logs/' . $slug . '/' . $process . '.log');

        return $connection->execute("tail -n {$lines} {$log} 2>/dev/null || true", sudo: true)->output;
    }

    public function sleep(SSHConnection $connection, Application $application): void
    {
        $this->stop($connection, $application, PowerState::Sleeping);
    }

    public function wake(SSHConnection $connection, Application $application): void
    {
        $this->start($connection, $application);

        $application->forceFill(['last_request_at' => now()])->save();
    }

    /**
     * @return list<string>
     */
    public function unitNames(Application $application): array
    {
        $names = [];

        if ($application->type === ApplicationType::Php) {
            $names[] = $this->fpm->unitName($application);
        }

        if ($this->units->webCommand($application) !== null) {
            $names[] = $this->units->unitName($application, 'web');
        }

        foreach (array_keys($this->workerProcesses($application)) as $process) {
            $names[] = $this->units->unitName($application, $process);
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function workerProcesses(Application $application): array
    {
        $processes = [];

        foreach ((array) $application->processes as $process) {
            if (!is_array($process) || !isset($process['name'], $process['command'])) {
                continue;
            }

            $processes[SafeName::assertProcess((string) $process['name'])] = (string) $process['command'];
        }

        return $processes;
    }
}
