<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\PowerState;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

final class CrontabBuilder
{
    public const SCHEDULE_PATTERN = '/^[0-9*\/,-]+(?: [0-9*\/,-]+){4}$/D';

    public const DIRECTORY = '/etc/cron.d';

    public const SCRIPTS = '/etc/coresmith/cron';

    public const LOCK_SECONDS = 120;

    public const LOCK_WAIT_SECONDS = 60;

    public function __construct(private readonly AppShell $shell) {}

    public static function assertSchedule(string $schedule): string
    {
        if (preg_match(self::SCHEDULE_PATTERN, $schedule) !== 1 || CronExpression::problem($schedule) !== null) {
            throw UnsafeNameException::cronSchedule($schedule);
        }

        return $schedule;
    }

    public function path(Application $application): string
    {
        return self::DIRECTORY . '/coresmith-app-' . SafeName::assertSlug($application->slug);
    }

    public function scriptDirectory(Application $application): string
    {
        return self::SCRIPTS . '/' . SafeName::assertSlug($application->slug);
    }

    public function build(Application $application): string
    {
        $slug = SafeName::assertSlug($application->slug);
        $paths = new ReleasePaths($application);
        $current = $paths->appDirectory($paths->current());
        $scripts = $this->scriptDirectory($application);

        $lines = ["# Coresmith managed crons for {$slug} - do not edit manually"];

        foreach ($this->entries($application) as $index => $entry) {
            $lines[] = $entry['schedule'] . ' root ' . $this->shell->scheduled($application, $scripts . '/' . $index . '.sh', $current, $application->runtimeVersions(), $application->type?->defaultEnvironment() ?? []);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, string>
     */
    public function scripts(Application $application): array
    {
        $log = escapeshellarg((new ReleasePaths($application))->schedulerLog());
        $directory = $this->scriptDirectory($application);
        $scripts = [];

        foreach ($this->entries($application) as $index => $entry) {
            $scripts[$directory . '/' . $index . '.sh'] = 'exec >> ' . $log . " 2>&1\n{\n" . $entry['command'] . "\n} < /dev/null\n";
        }

        return $scripts;
    }

    public function lockKey(Application $application): string
    {
        return 'crontab:' . SafeName::assertSlug($application->slug);
    }

    public function runs(Application $application): bool
    {
        return !$application->trashed()
            && $application->power_state !== PowerState::Stopped
            && $application->current_release !== null
            && $this->scripts($application) !== [];
    }

    public function settle(Application $application, CarbonInterface $readAt): void
    {
        Application::query()
            ->whereKey($application->getKey())
            ->where(fn($query) => $query->whereNull('cron_pending_since')->orWhere('cron_pending_since', '<=', $readAt))
            ->update(['cron_pending_since' => null, 'cron_error' => null]);
    }

    public function write(SSHConnection $connection, Application $application): void
    {
        Cache::lock($this->lockKey($application), self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($connection, $application): void {
            $readAt = now();
            $stored = $application->exists ? Application::query()->find($application->getKey()) : null;

            if ($stored !== null && $this->runs($stored)) {
                $this->install($connection, $stored);
            } else {
                $this->clear($connection, $stored ?? $application);
            }

            if ($stored !== null) {
                $this->settle($stored, $readAt);
            }
        });
    }

    public function remove(SSHConnection $connection, Application $application): void
    {
        Cache::lock($this->lockKey($application), self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, fn() => $this->clear($connection, $application));
    }

    private function install(SSHConnection $connection, Application $application): void
    {
        $scripts = $this->scripts($application);

        $directory = $this->scriptDirectory($application);
        $stagedDirectory = $this->staged($directory);
        $file = $this->path($application);
        $stagedFile = $this->staged($file);
        $root = escapeshellarg(self::SCRIPTS);

        $connection->execute(implode(' && ', [
            'rm -rf ' . escapeshellarg($stagedDirectory),
            'mkdir -p ' . escapeshellarg($stagedDirectory),
            'chown root:root ' . $root . ' ' . escapeshellarg($stagedDirectory),
            'chmod 700 ' . $root . ' ' . escapeshellarg($stagedDirectory),
        ]), sudo: true)->throw();

        $staged = [];

        foreach ($scripts as $script => $content) {
            $staged[] = $target = $stagedDirectory . '/' . basename($script);
            $connection->uploadContent($content, $target);
        }

        $connection->uploadContent($this->build($application), $stagedFile);

        $quoted = implode(' ', array_map(escapeshellarg(...), $staged));

        $connection->execute(implode(' && ', [
            'chown root:root ' . $quoted,
            'chmod 600 ' . $quoted,
            'chown root:root ' . escapeshellarg($stagedFile),
            'chmod 644 ' . escapeshellarg($stagedFile),
            'rm -rf ' . escapeshellarg($directory),
            'mv -T ' . escapeshellarg($stagedDirectory) . ' ' . escapeshellarg($directory),
            'mv -f ' . escapeshellarg($stagedFile) . ' ' . escapeshellarg($file),
            $this->removeUserCrontab($application),
        ]), sudo: true)->throw();
    }

    private function clear(SSHConnection $connection, Application $application): void
    {
        $file = $this->path($application);
        $directory = $this->scriptDirectory($application);

        $connection->execute(implode(' && ', [
            'rm -f ' . escapeshellarg($file) . ' ' . escapeshellarg($this->staged($file)),
            'rm -rf ' . escapeshellarg($directory) . ' ' . escapeshellarg($this->staged($directory)),
            $this->removeUserCrontab($application),
        ]), sudo: true)->throw();
    }

    private function staged(string $path): string
    {
        return dirname($path) . '/.' . basename($path) . '.new';
    }

    private function removeUserCrontab(Application $application): string
    {
        $user = escapeshellarg(SafeName::assertSystemUser($application->system_user));

        return "if crontab -u {$user} -l >/dev/null 2>&1; then crontab -u {$user} -r; fi";
    }

    /**
     * @return list<array{schedule: string, command: string}>
     */
    private function entries(Application $application): array
    {
        $entries = [];

        foreach ((array) $application->cron_jobs as $job) {
            if (!is_array($job) || !isset($job['schedule'], $job['command']) || trim((string) $job['command']) === '') {
                continue;
            }

            $entries[] = ['schedule' => self::assertSchedule((string) $job['schedule']), 'command' => (string) $job['command']];
        }

        return $entries;
    }
}
