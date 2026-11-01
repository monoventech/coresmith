<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\PowerState;
use App\Jobs\SyncApplicationCron;
use App\Models\Application;
use App\Services\Deployment\LogSanitizer;
use InvalidArgumentException;

final class CronJobs
{
    public const MAX_ENTRIES = 20;

    public const APPLY_WINDOW_SECONDS = 180;

    public const SCHEDULER_LABEL = 'Laravel scheduler';

    public const ADDED = 'cron_job_added';

    public const UPDATED = 'cron_job_updated';

    public const REMOVED = 'cron_job_removed';

    private const SUMMARY_LENGTH = 80;

    private const LOGGED_LENGTH = 300;

    public function __construct(private readonly LogSanitizer $sanitizer = new LogSanitizer) {}

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function id(array $entry): string
    {
        return substr(hash('sha256', (string) ($entry['schedule'] ?? '') . "\0" . (string) ($entry['command'] ?? '') . "\0" . (string) ($entry['description'] ?? '')), 0, 16);
    }

    /**
     * @return list<array{id: string, schedule: string, command: string, description: string|null}>
     */
    public function entries(Application $application): array
    {
        $entries = [];

        foreach ((array) $application->cron_jobs as $job) {
            if (!is_array($job) || !isset($job['schedule'], $job['command']) || trim((string) $job['command']) === '') {
                continue;
            }

            $entries[] = self::normalized($job);
        }

        return $entries;
    }

    /**
     * @return array{id: string, schedule: string, command: string, description: string|null}|null
     */
    public function find(Application $application, string $id): ?array
    {
        foreach ($this->entries($application) as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function isScheduler(array $entry): bool
    {
        return trim((string) ($entry['command'] ?? '')) === SmartToggles::SCHEDULE_COMMAND;
    }

    public function hasScheduler(Application $application): bool
    {
        foreach ($this->entries($application) as $entry) {
            if ($this->isScheduler($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{id: string, schedule: string, command: string, description: string|null}
     */
    public function add(Application $application, array $entry): array
    {
        $entries = $this->entries($application);

        if (count($entries) >= self::MAX_ENTRIES) {
            throw new InvalidArgumentException("{$application->name} already has " . self::MAX_ENTRIES . ' cron jobs.');
        }

        $added = self::normalized($entry);

        $this->commit($application, [...$entries, $added]);
        $this->log($application, self::ADDED, $added);

        return $added;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{id: string, schedule: string, command: string, description: string|null}|null
     */
    public function update(Application $application, string $id, array $entry): ?array
    {
        $entries = $this->entries($application);
        $index = array_search($id, array_column($entries, 'id'), true);

        if ($index === false) {
            return null;
        }

        $updated = self::normalized($entry);

        if ($updated['id'] === $id) {
            return $entries[$index];
        }

        $from = $entries[$index]['schedule'];
        $entries[$index] = $updated;

        $this->commit($application, $entries);
        $this->log($application, self::UPDATED, $updated, $from);

        return $updated;
    }

    /**
     * @return array{id: string, schedule: string, command: string, description: string|null}|null
     */
    public function remove(Application $application, string $id): ?array
    {
        $entries = $this->entries($application);
        $index = array_search($id, array_column($entries, 'id'), true);

        if ($index === false) {
            return null;
        }

        $removed = $entries[$index];
        unset($entries[$index]);

        $this->commit($application, array_values($entries));
        $this->log($application, self::REMOVED, $removed);

        return $removed;
    }

    public function enableScheduler(Application $application): void
    {
        $this->commit($application, [
            ...$this->withoutScheduler($application),
            self::normalized(['schedule' => '* * * * *', 'command' => SmartToggles::SCHEDULE_COMMAND]),
        ]);
    }

    public function disableScheduler(Application $application): void
    {
        $this->commit($application, $this->withoutScheduler($application));
    }

    public function retry(Application $application): void
    {
        $application->forceFill(['cron_pending_since' => now(), 'cron_error' => null])->save();

        SyncApplicationCron::dispatch($application);
    }

    public function state(Application $application): ?string
    {
        $since = $application->cron_pending_since;

        if ($since === null) {
            return null;
        }

        return $since->greaterThanOrEqualTo(now()->subSeconds(self::APPLY_WINDOW_SECONDS)) ? 'applying' : 'waiting';
    }

    /**
     * @return array{jobs: list<array<string, mixed>>, limit: int, state: string|null, note: string|null, error: string|null, user: string, live: bool}
     */
    public function describe(Application $application): array
    {
        $live = $application->current_release !== null;

        return [
            'jobs' => array_map(fn(array $entry): array => $this->row($entry), $this->entries($application)),
            'limit' => self::MAX_ENTRIES,
            'state' => $this->state($application),
            'note' => match (true) {
                !$live => 'undeployed',
                $application->power_state === PowerState::Stopped => 'paused',
                default => null,
            },
            'error' => $application->cron_error,
            'user' => (string) $application->system_user,
            'live' => $live,
        ];
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{id: string, schedule: string, command: string, description: string|null}
     */
    private static function normalized(array $job): array
    {
        $description = trim((string) ($job['description'] ?? ''));
        $entry = ['schedule' => (string) ($job['schedule'] ?? ''), 'command' => (string) ($job['command'] ?? ''), 'description' => $description === '' ? null : $description];

        return ['id' => self::id($entry), ...$entry];
    }

    /**
     * @param  array{id: string, schedule: string, command: string, description: string|null}  $entry
     * @return array<string, mixed>
     */
    private function row(array $entry): array
    {
        $summary = self::summary($entry['command']);
        $scheduler = $this->isScheduler($entry);
        $title = $entry['description'] ?? ($scheduler ? self::SCHEDULER_LABEL : null);

        return [
            ...$entry,
            'command_summary' => $summary,
            'title' => $title ?? $summary,
            'title_is_command' => $title === null,
            'sentence' => CronSchedule::sentence($entry['schedule']),
            'preset' => CronSchedule::preset($entry['schedule']),
            'scheduler' => $scheduler,
        ];
    }

    private static function summary(string $command): string
    {
        $lines = explode("\n", $command);
        $first = $lines[0];

        if (mb_strlen($first) > self::SUMMARY_LENGTH) {
            return mb_substr($first, 0, self::SUMMARY_LENGTH) . '…';
        }

        return count($lines) > 1 ? $first . '…' : $first;
    }

    /**
     * @return list<array{id: string, schedule: string, command: string, description: string|null}>
     */
    private function withoutScheduler(Application $application): array
    {
        return array_values(array_filter($this->entries($application), fn(array $entry): bool => !$this->isScheduler($entry)));
    }

    /**
     * @param  list<array{id: string, schedule: string, command: string, description: string|null}>  $entries
     */
    private function commit(Application $application, array $entries): void
    {
        $stored = array_map(fn(array $entry): array => [
            'schedule' => $entry['schedule'],
            'command' => $entry['command'],
            ...($entry['description'] === null ? [] : ['description' => $entry['description']]),
        ], $entries);

        $application->forceFill(['cron_jobs' => $stored === [] ? null : $stored, 'cron_pending_since' => now(), 'cron_error' => null])->save();

        SyncApplicationCron::dispatch($application);
    }

    /**
     * @param  array{id: string, schedule: string, command: string, description: string|null}  $entry
     */
    private function log(Application $application, string $event, array $entry, ?string $from = null): void
    {
        activity()
            ->performedOn($application)
            ->withProperties([
                'schedule' => $entry['schedule'],
                ...($event === self::UPDATED ? ['from' => $from] : []),
                'description' => $entry['description'],
                'command' => mb_substr($this->sanitizer->sanitize($entry['command'], $application), 0, self::LOGGED_LENGTH),
            ])
            ->log($event);
    }
}
