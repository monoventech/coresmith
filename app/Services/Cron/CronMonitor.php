<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cron;

use App\Enums\MonitorKind;
use App\Events\FleetChanged;
use App\Models\Application;
use App\Models\MonitorAlert;
use App\Models\Server;
use App\Notifications\CronJobNotification;
use App\Notifications\CronJobRecoveredNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Monitors\MonitorLimits;
use App\Services\Panel\CronJobs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CronMonitor
{
    public const RECOVERY_SECONDS = 300;

    public const QUIET_SECONDS = 3600;

    public function __construct(private readonly MonitorLimits $limits, private readonly CronJobs $jobs, private readonly CrontabBuilder $crontab) {}

    public function evaluate(Server $server, Application $application, int $now, string $timezone): void
    {
        $entries = collect($this->jobs->entries($application))->keyBy('id');
        $open = MonitorAlert::query()
            ->where('application_id', $application->getKey())
            ->where('kind', MonitorKind::Cron)
            ->whereNotNull('open_key')
            ->get()
            ->keyBy('job');
        $watching = $this->limits->for($server, MonitorKind::Cron)->enabled && $this->crontab->runs($application);

        foreach ($open as $job => $alert) {
            if (!$watching || !$entries->has($job)) {
                $alert->close();
                $open->forget($job);
            }
        }

        if (!$watching) {
            return;
        }

        $since = max((int) $application->cron_written_at?->getTimestamp(), (int) $server->booted_at?->getTimestamp());
        $state = (array) $application->cron_runs;

        foreach ($entries as $id => $entry) {
            $job = $state[$id] ?? null;

            if (!is_array($job)) {
                continue;
            }

            $last = is_array($job['last'] ?? null) ? $job['last'] : null;
            $alert = $open->get($id);

            if ($alert !== null) {
                if ($last !== null && (int) $last['exit'] === 0 && (int) $last['started'] > (int) $alert->peak && (int) $last['started'] >= $alert->opened_at->getTimestamp() + self::RECOVERY_SECONDS) {
                    $this->recover($application, $entry, $alert, $last, $timezone);
                }

                continue;
            }

            if ($this->recentlyClosed($application, $id)) {
                continue;
            }

            if ($last !== null && (int) $last['exit'] !== 0) {
                $this->open($server, $application, $entry, 'failed', (int) $last['started'], (int) $last['exit'], $timezone);

                continue;
            }

            $due = CronDue::missed($entry['schedule'], $job, $now, $timezone, $since);

            if ($due !== null) {
                $this->open($server, $application, $entry, 'missed', $due, null, $timezone);
            }
        }
    }

    private function recentlyClosed(Application $application, string $job): bool
    {
        return MonitorAlert::query()
            ->where('application_id', $application->getKey())
            ->where('kind', MonitorKind::Cron)
            ->where('job', $job)
            ->where('closed_at', '>=', now()->subSeconds(self::QUIET_SECONDS))
            ->exists();
    }

    /**
     * @param  array{id: string, schedule: string, command: string, description: string|null}  $entry
     */
    private function open(Server $server, Application $application, array $entry, string $reason, int $moment, ?int $exit, string $timezone): void
    {
        try {
            $alert = DB::transaction(fn(): MonitorAlert => MonitorAlert::open($server, MonitorKind::Cron, $application, level: $reason, peak: (float) $moment, job: $entry['id']));
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $title = $this->jobs->title($entry);

        if (!$this->notify($application, new CronJobNotification($application, $title, $reason, $moment, $exit, $timezone))) {
            $alert->delete();

            return;
        }

        activity()->performedOn($application)->withProperties($reason === 'failed' ? ['job' => $title, 'exit' => $exit] : ['job' => $title, 'due' => $moment])->log('cron_' . $reason);
        FleetChanged::forApplication($application);
    }

    /**
     * @param  array{id: string, schedule: string, command: string, description: string|null}  $entry
     * @param  array{started: int, seconds: int, exit: int}  $last
     */
    private function recover(Application $application, array $entry, MonitorAlert $alert, array $last, string $timezone): void
    {
        if (!$alert->close()) {
            return;
        }

        $title = $this->jobs->title($entry);

        $this->notify($application, new CronJobRecoveredNotification($application, $title, (int) $last['started'], (int) $last['seconds'], $timezone));
        activity()->performedOn($application)->withProperties(['job' => $title])->log('cron_recovered');
        FleetChanged::forApplication($application);
    }

    private function notify(Application $application, Notification $notification): bool
    {
        try {
            app(TeamNotifier::class)->send($application->team, Preferences::SERVER_ALERTS, $notification, about: $application);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
