<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\PowerState;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Services\Panel\CronJobs;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\Cache;

final class CrontabBuilder
{
    public const SCHEDULE_PATTERN = '/^[0-9*\/,-]+(?: [0-9*\/,-]+){4}$/D';

    public const DIRECTORY = '/etc/cron.d';

    public const SCRIPTS = '/etc/coresmith/cron';

    public const RECORDS = '/var/lib/coresmith/cron';

    public const KEPT_RUNS = 50;

    public const LOCK_SECONDS = 120;

    public const LOCK_WAIT_SECONDS = 60;

    public const COMMAND_SECONDS = 30;

    public function __construct(private readonly AppShell $shell, private readonly CronJobs $cron) {}

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

    public function recordDirectory(Application $application): string
    {
        return self::RECORDS . '/' . SafeName::assertSlug($application->slug);
    }

    public function build(Application $application): string
    {
        $slug = SafeName::assertSlug($application->slug);
        $scripts = $this->scriptDirectory($application);

        $lines = ["# Managed by Coresmith for {$slug}. Change these jobs on the app page."];

        foreach ($this->entries($application) as $index => $entry) {
            $lines[] = $entry['schedule'] . ' root /bin/sh ' . escapeshellarg($scripts . '/' . $index . '.run');
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
            $scripts[$directory . '/' . $index . '.run'] = $this->wrapper($application, $index, $entry['id']);
        }

        return $scripts;
    }

    public function wrapper(Application $application, int $index, string $id): string
    {
        $paths = new ReleasePaths($application);
        $records = $this->recordDirectory($application);
        $record = escapeshellarg($records . '/' . self::assertId($id) . '.runs');
        $running = escapeshellarg($records . '/' . $id . '.') . '"$$".running';
        $kept = self::KEPT_RUNS;
        $run = $this->shell->scheduled($application, $this->scriptDirectory($application) . '/' . $index . '.sh', $paths->appDirectory($paths->current()), $application->runtimeVersions(), app(StackDefaults::class)->process($application));

        return implode("\n", [
            'umask 077',
            'started=$(date +%s)',
            'mkdir -p ' . escapeshellarg($records),
            'running=' . $running,
            'printf \'%s\\n\' "$started" > "$running"',
            $run,
            'status=$?',
            'rm -f "$running"',
            '{',
            '    flock 9',
            "    printf '%s %s %s\\n' \"\$started\" \"\$((\$(date +%s) - started))\" \"\$status\" >> {$record}",
            "    if [ \"\$(wc -l < {$record})\" -gt {$kept} ]; then tail -n {$kept} {$record} > {$record}.new && mv -f {$record}.new {$record}; fi",
            '} 9>> ' . escapeshellarg($records . '/.lock'),
            'exit "$status"',
        ]) . "\n";
    }

    public static function assertId(string $id): string
    {
        if (preg_match('/^[0-9a-f]{16}$/D', $id) !== 1) {
            throw UnsafeNameException::cronJob($id);
        }

        return $id;
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

    public function settle(Application $application): void
    {
        $this->cron->ifUnchanged($application, ['cron_pending_since' => null, 'cron_error' => null, 'cron_written_at' => now()]);
    }

    public function write(SSHConnection $connection, Application $application): void
    {
        Cache::lock($this->lockKey($application), self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($connection, $application): void {
            $stored = $application->exists ? Application::query()->find($application->getKey()) : null;

            if ($stored !== null && $this->runs($stored)) {
                $this->install($connection, $stored);
            } else {
                $this->clear($connection, $stored ?? $application);
            }

            if ($stored !== null) {
                $this->settle($stored);
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
        ]), sudo: true, timeout: self::COMMAND_SECONDS)->throw();

        $staged = [];

        foreach ($scripts as $script => $content) {
            $staged[] = $target = $stagedDirectory . '/' . basename($script);
            $connection->uploadContent($content, $target, self::COMMAND_SECONDS);
        }

        $connection->uploadContent($this->build($application), $stagedFile, self::COMMAND_SECONDS);

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
            $this->pruneRecords($application),
        ]), sudo: true, timeout: self::COMMAND_SECONDS)->throw();
    }

    private function pruneRecords(Application $application): string
    {
        $keep = implode('|', array_map(fn(array $entry): string => self::assertId($entry['id']), $this->entries($application)));

        return 'for record in ' . escapeshellarg($this->recordDirectory($application)) . '/*.runs; do [ -e "$record" ] || continue; '
            . 'case "$(basename "$record" .runs)" in ' . $keep . ') ;; *) rm -f "$record" ;; esac; done';
    }

    private function clear(SSHConnection $connection, Application $application): void
    {
        $file = $this->path($application);
        $directory = $this->scriptDirectory($application);

        $connection->execute(implode(' && ', [
            'rm -f ' . escapeshellarg($file) . ' ' . escapeshellarg($this->staged($file)),
            'rm -rf ' . escapeshellarg($directory) . ' ' . escapeshellarg($this->staged($directory)),
            $this->removeUserCrontab($application),
        ]), sudo: true, timeout: self::COMMAND_SECONDS)->throw();
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
     * @return list<array{id: string, schedule: string, command: string}>
     */
    private function entries(Application $application): array
    {
        return array_map(fn(array $entry): array => [
            'id' => $entry['id'],
            'schedule' => self::assertSchedule($entry['schedule']),
            'command' => $entry['command'],
        ], $this->cron->entries($application));
    }
}
