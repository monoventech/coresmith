<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\PowerState;
use App\Exceptions\CronListRefused;
use App\Jobs\SyncApplicationCron;
use App\Models\Application;
use App\Services\Deployment\LogSanitizer;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

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

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

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
     * @param  list<array{id: string, schedule: string, command: string, description: string|null}>  $entries
     * @param  array<string, mixed>  $entry
     */
    public function problem(array $entries, ?string $editing, array $entry): ?string
    {
        $others = collect($entries)->reject(fn(array $other): bool => $other['id'] === $editing);
        $schedule = (string) ($entry['schedule'] ?? '');
        $command = (string) ($entry['command'] ?? '');

        return match (true) {
            $editing === null && count($entries) >= self::MAX_ENTRIES => 'This app already has ' . self::MAX_ENTRIES . ' cron jobs. Remove one, or run several commands from one job.',
            $others->contains(fn(array $other): bool => $other['schedule'] === $schedule && $other['command'] === $command) => 'That exact job is already on the list.',
            $this->isScheduler($entry) && $others->contains(fn(array $other): bool => $this->isScheduler($other)) => 'The Laravel scheduler is already on the list. Edit that job instead.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{id: string, schedule: string, command: string, description: string|null}
     */
    public function add(Application $application, array $entry): array
    {
        $added = self::normalized($entry);

        $this->change($application, function (array $entries) use ($added): array {
            $this->refuse($entries, null, $added);

            return [[...$entries, $added], null];
        });

        $this->log($application, self::ADDED, $added);

        return $added;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{id: string, schedule: string, command: string, description: string|null}|null
     */
    public function update(Application $application, string $id, array $entry): ?array
    {
        $updated = self::normalized($entry);
        $from = null;

        $result = $this->change($application, function (array $entries) use ($id, $updated, &$from): array {
            $index = array_search($id, array_column($entries, 'id'), true);

            if ($index === false) {
                return [null, null];
            }

            if ($updated['id'] === $id) {
                return [null, $entries[$index]];
            }

            $this->refuse($entries, $id, $updated);

            $from = $entries[$index]['schedule'];
            $entries[$index] = $updated;

            return [$entries, $updated];
        });

        if ($from !== null) {
            $this->log($application, self::UPDATED, $updated, $from);
        }

        return $result;
    }

    /**
     * @return array{id: string, schedule: string, command: string, description: string|null}|null
     */
    public function remove(Application $application, string $id): ?array
    {
        $removed = $this->change($application, function (array $entries) use ($id): array {
            $index = array_search($id, array_column($entries, 'id'), true);

            if ($index === false) {
                return [null, null];
            }

            $removed = $entries[$index];
            unset($entries[$index]);

            return [array_values($entries), $removed];
        });

        if ($removed !== null) {
            $this->log($application, self::REMOVED, $removed);
        }

        return $removed;
    }

    public function enableScheduler(Application $application): void
    {
        $this->change($application, function (array $entries): array {
            $others = $this->withoutScheduler($entries);

            if (count($others) >= self::MAX_ENTRIES) {
                throw new CronListRefused('This app already has ' . self::MAX_ENTRIES . ' cron jobs. Remove one, then turn the scheduler on.');
            }

            return [[...$others, self::normalized(['schedule' => '* * * * *', 'command' => SmartToggles::SCHEDULE_COMMAND])], null];
        });
    }

    public function disableScheduler(Application $application): void
    {
        $this->change($application, fn(array $entries): array => [$this->withoutScheduler($entries), null]);
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
     * @param  list<array{id: string, schedule: string, command: string, description: string|null}>  $entries
     * @return list<array{id: string, schedule: string, command: string, description: string|null}>
     */
    private function withoutScheduler(array $entries): array
    {
        return array_values(array_filter($entries, fn(array $entry): bool => !$this->isScheduler($entry)));
    }

    /**
     * @param  Closure(list<array{id: string, schedule: string, command: string, description: string|null}>): array{0: list<array{id: string, schedule: string, command: string, description: string|null}>|null, 1: mixed}  $edit
     */
    private function change(Application $application, Closure $edit): mixed
    {
        try {
            return Cache::lock('cron-jobs:' . $application->getKey(), self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($application, $edit): mixed {
                $application->refresh();

                [$entries, $result] = $edit($this->entries($application));

                if ($entries !== null) {
                    $this->commit($application, $entries);
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            throw new CronListRefused('Another change to these cron jobs is still being saved. Try again in a moment.');
        }
    }

    /**
     * @param  list<array{id: string, schedule: string, command: string, description: string|null}>  $entries
     * @param  array<string, mixed>  $entry
     */
    private function refuse(array $entries, ?string $editing, array $entry): void
    {
        $problem = $this->problem($entries, $editing, $entry);

        if ($problem !== null) {
            throw new CronListRefused($problem);
        }
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
