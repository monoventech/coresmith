<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Jobs\SyncApplicationProcesses;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationToggledNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use App\Services\Runtime\AsdfManager;
use InvalidArgumentException;

final class SmartToggles
{
    public const MAINTENANCE = 'maintenance_mode';

    public const QUEUE = 'queue_worker';

    public const SCHEDULER = 'scheduler';

    public const SIDEKIQ = 'sidekiq';

    public const SCHEDULE_COMMAND = 'php artisan schedule:run';

    public function __construct(private readonly AsdfManager $asdf) {}

    /**
     * @return list<string>
     */
    public function available(Application $application): array
    {
        $framework = strtolower((string) $application->framework);

        return array_values(array_filter([
            $framework === 'laravel' ? self::QUEUE : null,
            $framework === 'laravel' ? self::SCHEDULER : null,
            $application->type === ApplicationType::Ruby && $framework === 'rails' ? self::SIDEKIQ : null,
            self::MAINTENANCE,
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describe(Application $application): array
    {
        $slug = $application->slug;
        $stopped = $application->power_state === PowerState::Stopped;

        return array_map(fn(string $key): array => match ($key) {
            self::QUEUE => [
                'key' => $key,
                'label' => 'Queue worker',
                'description' => 'php artisan queue:work · systemd unit coresmith-app-' . $slug . '-queue',
                'busy_on' => 'Creating the systemd unit and starting the worker…',
                'busy_off' => 'Stopping the unit and removing it…',
                'on' => $this->isOn($application, $key),
                'disabled' => false,
            ],
            self::SCHEDULER => [
                'key' => $key,
                'label' => 'Scheduler',
                'description' => 'artisan schedule:run every minute · cron',
                'busy_on' => 'Writing the crontab entry…',
                'busy_off' => 'Removing the crontab entry…',
                'on' => $this->isOn($application, $key),
                'disabled' => false,
            ],
            self::SIDEKIQ => [
                'key' => $key,
                'label' => 'Sidekiq',
                'description' => 'bundle exec sidekiq · systemd unit coresmith-app-' . $slug . '-sidekiq',
                'busy_on' => 'Creating the systemd unit and starting Sidekiq…',
                'busy_off' => 'Stopping the unit and removing it…',
                'on' => $this->isOn($application, $key),
                'disabled' => false,
            ],
            default => [
                'key' => $key,
                'label' => 'Maintenance page',
                'description' => $stopped ? 'The application is stopped, so visitors already see one.' : 'Caddy serves a holding page, the processes keep running',
                'busy_on' => 'Pointing Caddy at the maintenance page…',
                'busy_off' => 'Routing traffic back to the app…',
                'on' => $this->isOn($application, $key),
                'disabled' => $stopped,
            ],
        }, $this->available($application));
    }

    public function isOn(Application $application, string $key): bool
    {
        return match ($key) {
            self::MAINTENANCE => (bool) (((array) $application->toggles)[self::MAINTENANCE] ?? false),
            self::QUEUE => $this->hasProcess($application, 'queue'),
            self::SIDEKIQ => $this->hasProcess($application, 'sidekiq'),
            self::SCHEDULER => collect((array) $application->cron_jobs)->contains(fn(mixed $job): bool => is_array($job) && ($job['command'] ?? null) === self::SCHEDULE_COMMAND),
            default => false,
        };
    }

    public function label(string $key): string
    {
        return match ($key) {
            self::QUEUE => 'Queue worker',
            self::SCHEDULER => 'Scheduler',
            self::SIDEKIQ => 'Sidekiq',
            default => 'Maintenance page',
        };
    }

    public function apply(Application $application, string $key, bool $on): void
    {
        if (!in_array($key, $this->available($application), true)) {
            throw new InvalidArgumentException("[{$key}] is not a toggle for {$application->name}.");
        }

        match ($key) {
            self::MAINTENANCE => $this->maintenance($application, $on),
            self::QUEUE => $this->process($application, 'queue', $this->phpBinary($application) . ' artisan queue:work --sleep=3 --tries=3 --max-time=3600', $on),
            self::SIDEKIQ => $this->process($application, 'sidekiq', $this->asdf->binaryPath('ruby', (string) $application->ruby_version, 'bundle') . ' exec sidekiq', $on),
            self::SCHEDULER => $this->scheduler($application, $on),
        };

        activity()
            ->performedOn($application)
            ->withProperties(['toggle' => $key, 'label' => $this->label($key), 'on' => $on])
            ->log('toggle_changed');

        $actor = auth()->user();

        app(TeamNotifier::class)->send(
            $application->team,
            Preferences::TOGGLE_CHANGES,
            new ApplicationToggledNotification($application, $this->label($key) . ' turned ' . ($on ? 'on' : 'off'), $actor?->name),
            $actor instanceof User ? $actor : null,
        );
    }

    private function maintenance(Application $application, bool $on): void
    {
        $toggles = (array) $application->toggles;
        $toggles[self::MAINTENANCE] = $on;

        $application->forceFill(['toggles' => $toggles])->save();

        SyncApplicationRoute::dispatch($application);
    }

    private function process(Application $application, string $name, string $command, bool $on): void
    {
        $processes = array_values(array_filter((array) $application->processes, fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) !== $name));

        if ($on) {
            $processes[] = ['name' => $name, 'command' => $command];
        }

        $application->forceFill(['processes' => $processes === [] ? null : $processes])->save();

        SyncApplicationProcesses::dispatch($application, $on ? [] : [$name]);
    }

    private function scheduler(Application $application, bool $on): void
    {
        $jobs = array_values(array_filter((array) $application->cron_jobs, fn(mixed $job): bool => is_array($job) && ($job['command'] ?? null) !== self::SCHEDULE_COMMAND));

        if ($on) {
            $jobs[] = ['schedule' => '* * * * *', 'command' => self::SCHEDULE_COMMAND];
        }

        $application->forceFill(['cron_jobs' => $jobs === [] ? null : $jobs])->save();

        SyncApplicationProcesses::dispatch($application, []);
    }

    private function hasProcess(Application $application, string $name): bool
    {
        return collect((array) $application->processes)->contains(fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) === $name);
    }

    private function phpBinary(Application $application): string
    {
        return $application->php_version === null ? '/usr/bin/php' : $this->asdf->binaryPath('php', (string) $application->php_version);
    }
}
