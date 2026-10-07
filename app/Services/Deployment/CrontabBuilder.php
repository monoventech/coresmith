<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;

final class CrontabBuilder
{
    public const SCHEDULE_PATTERN = '/^[\d*\/,\-\s]{1,100}$/';

    public function __construct(private readonly DeploymentCommands $commands) {}

    public function build(Application $application): string
    {
        $slug = SafeName::assertSlug($application->slug);
        $current = ReleasePaths::ROOT . '/' . $slug . '/current';
        $log = '/var/coresmith/logs/' . $slug . '/scheduler.log';
        $path = rtrim(str_replace(['PATH=', ':$PATH '], ['', ''], $this->commands->pathPrefix($application->runtimeVersions())), ' ');
        $path = $path === '' ? '/usr/local/bin:/usr/bin:/bin' : $path . ':/usr/local/bin:/usr/bin:/bin';

        $lines = [
            "# Coresmith managed crons for {$slug} - do not edit manually",
            'SHELL=/bin/bash',
            'PATH=' . $path,
            '',
        ];

        foreach ($this->entries($application) as $entry) {
            $lines[] = $entry['schedule'] . ' cd ' . $current . ' && ' . $entry['command'] . ' >> ' . $log . ' 2>&1';
        }

        return implode("\n", $lines) . "\n";
    }

    public function install(Application $application): string
    {
        return 'crontab -u ' . SafeName::assertSystemUser($application->system_user) . ' -';
    }

    public function write(SSHConnection $connection, Application $application): void
    {
        $file = '/tmp/coresmith-cron-' . SafeName::assertSlug($application->slug);

        $connection->uploadContent($this->build($application), $file);
        $connection->execute('cat ' . $file . ' | ' . $this->install($application) . ' && rm -f ' . $file, sudo: true)->throw();
    }

    /**
     * @return list<array{schedule: string, command: string}>
     */
    private function entries(Application $application): array
    {
        $entries = [];

        foreach ((array) $application->cron_jobs as $job) {
            if (!is_array($job) || !isset($job['schedule'], $job['command'])) {
                continue;
            }

            $schedule = trim((string) $job['schedule']);
            $command = (string) $job['command'];

            if (preg_match(self::SCHEDULE_PATTERN, $schedule) !== 1) {
                throw UnsafeNameException::cronSchedule($schedule);
            }

            if (str_contains($command, "\n") || str_contains($command, "\r")) {
                throw UnsafeNameException::cronCommand($command);
            }

            $entries[] = ['schedule' => $schedule, 'command' => $command];
        }

        return $entries;
    }
}
