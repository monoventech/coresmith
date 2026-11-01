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
use App\Services\AutoDeploy\AutoDeployStatus;
use App\Services\Process\ProcessManager;
use App\Services\Server\PortAllocator;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;

final class SmartToggles
{
    public const MAINTENANCE = 'maintenance_mode';

    public const QUEUE = 'queue_worker';

    public const SCHEDULER = 'scheduler';

    public const SIDEKIQ = 'sidekiq';

    public const SOLID_QUEUE = 'solid_queue';

    public const AUTO_DEPLOY = 'auto_deploy';

    public const REVERB = 'reverb';

    public const INERTIA_SSR = 'inertia_ssr';

    public const SSR_COMMAND = 'php artisan inertia:start-ssr';

    public const START_WINDOW_SECONDS = 180;

    public const CHANGED = 'toggle_changed';

    public const STARTED = 'toggle_started';

    public const START_FAILED = 'toggle_start_failed';

    public const SCHEDULE_COMMAND = 'php artisan schedule:run';

    public const QUEUE_COMMAND = 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600';

    public const HORIZON_COMMAND = 'php artisan horizon';

    public const SIDEKIQ_COMMAND = 'bundle exec sidekiq';

    public const SOLID_QUEUE_COMMAND = 'bin/jobs';

    private const WORKERS = [
        self::QUEUE => ['queue', self::QUEUE_COMMAND],
        self::SIDEKIQ => ['sidekiq', self::SIDEKIQ_COMMAND],
        self::SOLID_QUEUE => ['jobs', self::SOLID_QUEUE_COMMAND],
    ];

    private const GEMS = [
        self::SIDEKIQ => 'sidekiq',
        self::SOLID_QUEUE => 'solid_queue',
    ];

    /**
     * @return list<string>
     */
    public function available(Application $application): array
    {
        $framework = strtolower((string) $application->framework);
        $rails = $application->type === ApplicationType::Ruby && $framework === 'rails';

        return array_values(array_filter([
            $framework === 'laravel' && ($this->ssrDetected($application) || $this->ssrOn($application)) ? self::INERTIA_SSR : null,
            $framework === 'laravel' ? self::QUEUE : null,
            $framework === 'laravel' ? self::SCHEDULER : null,
            $framework === 'laravel' && (($this->packages($application)['reverb'] ?? false) === true || app(Reverb::class)->on($application)) ? self::REVERB : null,
            $rails && $this->offers($application, self::SIDEKIQ) ? self::SIDEKIQ : null,
            $rails && $this->offers($application, self::SOLID_QUEUE) ? self::SOLID_QUEUE : null,
            self::MAINTENANCE,
            filled($application->repository_url) ? self::AUTO_DEPLOY : null,
        ]));
    }

    public function process(string $key): ?string
    {
        return match ($key) {
            self::INERTIA_SSR => 'ssr',
            self::REVERB => 'reverb',
            default => self::WORKERS[$key][0] ?? null,
        };
    }

    public function failedWorker(Application $application, string $unit): ?string
    {
        $processes = app(ProcessManager::class);

        if ($processes->serves($application, $unit)) {
            return null;
        }

        $process = $processes->process($application, $unit);

        foreach (self::WORKERS as $key => [$name]) {
            if ($name === $process) {
                return $this->label($key);
            }
        }

        return ucfirst($process);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describe(Application $application): array
    {
        $slug = $application->slug;
        $stopped = $application->power_state === PowerState::Stopped;

        return array_map(fn(string $key): array => [...match ($key) {
            self::QUEUE => [
                'key' => $key,
                'label' => 'Queue worker',
                'description' => ($this->horizon($application) ? 'Detected: laravel/horizon' : 'php artisan queue:work') . ' · systemd unit coresmith-app-' . $slug . '-queue',
                'busy_on' => $this->horizon($application) ? 'Creating the systemd unit and starting Horizon…' : 'Creating the systemd unit and starting the worker…',
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
            self::SOLID_QUEUE => [
                'key' => $key,
                'label' => 'Solid Queue',
                'description' => 'bin/jobs · systemd unit coresmith-app-' . $slug . '-jobs',
                'busy_on' => 'Creating the systemd unit and starting Solid Queue…',
                'busy_off' => 'Stopping the unit and removing it…',
                'on' => $this->isOn($application, $key),
                'disabled' => false,
            ],
            self::INERTIA_SSR => [
                'key' => $key,
                'label' => 'Inertia SSR',
                'description' => $this->ssrDescription($application),
                'busy_on' => 'Saving, it starts with the next deploy…',
                'busy_off' => 'Stopping the unit and freeing the port…',
                'on' => $this->isOn($application, $key),
                'disabled' => false,
            ],
            self::REVERB => [
                'key' => $key,
                'label' => 'Reverb websockets',
                'description' => $this->reverbDescription($application),
                'busy_on' => 'Saving, it starts with the next deploy…',
                'busy_off' => 'Stopping the unit and removing the route…',
                'on' => $this->isOn($application, $key),
                'disabled' => !$this->isOn($application, $key) && app(Reverb::class)->problem($application) !== null,
            ],
            self::AUTO_DEPLOY => [
                'key' => $key,
                'label' => 'Auto deploy',
                'description' => app(AutoDeployStatus::class)->describe($application),
                'busy_on' => 'Turning on auto deploy…',
                'busy_off' => 'Turning off auto deploy…',
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
        }, ...$this->outcome($application, $key)], $this->available($application));
    }

    public function isOn(Application $application, string $key): bool
    {
        return match ($key) {
            self::MAINTENANCE => (bool) (((array) $application->toggles)[self::MAINTENANCE] ?? false),
            self::QUEUE, self::SIDEKIQ, self::SOLID_QUEUE => $this->hasProcess($application, self::WORKERS[$key][0]),
            self::SCHEDULER => collect((array) $application->cron_jobs)->contains(fn(mixed $job): bool => is_array($job) && ($job['command'] ?? null) === self::SCHEDULE_COMMAND),
            self::AUTO_DEPLOY => (bool) $application->quick_deploy,
            self::REVERB => app(Reverb::class)->on($application),
            self::INERTIA_SSR => $this->ssrOn($application),
            default => false,
        };
    }

    public function label(string $key): string
    {
        return match ($key) {
            self::QUEUE => 'Queue worker',
            self::SCHEDULER => 'Scheduler',
            self::SIDEKIQ => 'Sidekiq',
            self::SOLID_QUEUE => 'Solid Queue',
            self::AUTO_DEPLOY => 'Auto deploy',
            self::REVERB => 'Reverb websockets',
            self::INERTIA_SSR => 'Inertia SSR',
            default => 'Maintenance page',
        };
    }

    /**
     * @return array<string, bool>
     */
    public function packages(Application $application): array
    {
        return array_filter((array) (((array) $application->stack)['packages'] ?? []), 'is_bool');
    }

    public function queueCommand(Application $application): string
    {
        return $this->horizon($application) ? self::HORIZON_COMMAND : self::QUEUE_COMMAND;
    }

    public function syncDeployTimeProcesses(Application $application): void
    {
        $queue = collect((array) $application->processes)->first(fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) === 'queue');

        if ($queue !== null && ($queue['command'] ?? null) !== $this->queueCommand($application)) {
            $this->replaceProcess($application, 'queue', ['name' => 'queue', 'command' => $this->queueCommand($application)]);
        }

        $reverb = app(Reverb::class);
        $wanted = $reverb->ready($application) ? Reverb::COMMAND . $reverb->port($application) : null;
        $current = collect((array) $application->processes)->first(fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) === 'reverb');

        if (($current['command'] ?? null) !== $wanted) {
            $this->replaceProcess($application, 'reverb', $wanted === null ? null : ['name' => 'reverb', 'command' => $wanted]);
        }

        $ssr = $this->ssrOn($application) && $application->ssr_port !== null ? $this->ssrCommand($application) : null;
        $running = collect((array) $application->processes)->first(fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) === 'ssr');

        if (($running['command'] ?? null) !== $ssr) {
            $this->replaceProcess($application, 'ssr', $ssr === null ? null : ['name' => 'ssr', 'command' => $ssr]);
        }
    }

    public function ssrOn(Application $application): bool
    {
        return (((array) $application->toggles)[self::INERTIA_SSR] ?? false) === true;
    }

    public function apply(Application $application, string $key, bool $on): void
    {
        if (!in_array($key, $this->available($application), true)) {
            throw new InvalidArgumentException("[{$key}] is not a toggle for {$application->name}.");
        }

        activity()
            ->performedOn($application)
            ->withProperties(['toggle' => $key, 'label' => $this->label($key), 'on' => $on])
            ->log(self::CHANGED);

        match ($key) {
            self::MAINTENANCE => $this->maintenance($application, $on),
            self::QUEUE, self::SIDEKIQ, self::SOLID_QUEUE => $this->worker($application, $key, $on),
            self::SCHEDULER => $this->scheduler($application, $on),
            self::AUTO_DEPLOY => $this->autoDeploy($application, $on),
            self::REVERB => $this->reverb($application, $on),
            self::INERTIA_SSR => $this->ssr($application, $on),
        };

        $actor = auth()->user();

        app(TeamNotifier::class)->send(
            $application->team,
            Preferences::TOGGLE_CHANGES,
            new ApplicationToggledNotification($application, $this->label($key) . ' turned ' . ($on ? 'on' : 'off'), $actor?->name, withoutDeployment: $key !== self::AUTO_DEPLOY),
            $actor instanceof User ? $actor : null,
        );
    }

    public function started(Application $application, string $key): void
    {
        activity()
            ->performedOn($application)
            ->withProperties(['toggle' => $key, 'label' => $this->label($key)])
            ->log(self::STARTED);
    }

    public function failedToStart(Application $application, string $key, string $process, string $error): void
    {
        $this->replaceProcess($application, $process);

        activity()
            ->performedOn($application)
            ->withProperties(['toggle' => $key, 'label' => $this->label($key), 'error' => $error])
            ->log(self::START_FAILED);
    }

    private function maintenance(Application $application, bool $on): void
    {
        $toggles = (array) $application->toggles;
        $toggles[self::MAINTENANCE] = $on;

        $application->forceFill(['toggles' => $toggles])->save();

        SyncApplicationRoute::dispatch($application);
    }

    private function worker(Application $application, string $key, bool $on): void
    {
        [$name, $command] = self::WORKERS[$key];
        $command = $key === self::QUEUE ? $this->queueCommand($application) : $command;

        $this->replaceProcess($application, $name, $on ? ['name' => $name, 'command' => $command] : null);

        SyncApplicationProcesses::dispatch($application, $on ? [] : [$name], $on ? $key : null);
    }

    /**
     * @param  array{name: string, command: string}|null  $replacement
     */
    private function replaceProcess(Application $application, string $name, ?array $replacement = null): void
    {
        $processes = array_values(array_filter((array) $application->processes, fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) !== $name));

        if ($replacement !== null) {
            $processes[] = $replacement;
        }

        $application->forceFill(['processes' => $processes === [] ? null : $processes])->save();
    }

    private function ssr(Application $application, bool $on): void
    {
        $port = $on ? ($application->ssr_port ?? app(PortAllocator::class)->allocateSsrPort($application->server)) : null;

        $application->forceFill(['toggles' => [...(array) $application->toggles, self::INERTIA_SSR => $on], 'ssr_port' => $port])->save();

        if ($on) {
            return;
        }

        $this->replaceProcess($application, 'ssr');

        SyncApplicationProcesses::dispatch($application, ['ssr']);
    }

    private function ssrDetected(Application $application): bool
    {
        return ($this->packages($application)['inertia'] ?? false) === true && (((array) $application->stack)['tooling']['ssr'] ?? false) === true;
    }

    private function ssrCommand(Application $application): string
    {
        $manager = ((array) $application->stack)['tooling']['manager'] ?? null;

        return 'INERTIA_SSR_PORT=' . $application->ssr_port . ' ' . self::SSR_COMMAND . ' --runtime=' . ($manager === 'bun' ? 'bun' : 'node');
    }

    private function ssrDescription(Application $application): string
    {
        $port = $application->ssr_port;
        $live = collect((array) $application->processes)->contains(fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) === 'ssr');

        return match (true) {
            $this->ssrOn($application) && $live => self::SSR_COMMAND . " · port {$port} auto-allocated" . ($port === PortAllocator::SSR_DEFAULT_PORT ? '' : ' · your SSR entry must read INERTIA_SSR_PORT'),
            $this->ssrOn($application) => "Starts with the next deploy, which builds the SSR bundle · port {$port}",
            default => 'Detected from composer.json and package.json · turning it on gives it a port from the next deploy',
        };
    }

    private function reverb(Application $application, bool $on): void
    {
        $application->forceFill(['toggles' => [...(array) $application->toggles, self::REVERB => $on]])->save();

        if ($on) {
            return;
        }

        $this->replaceProcess($application, 'reverb');

        SyncApplicationProcesses::dispatch($application, ['reverb']);
        SyncApplicationRoute::dispatch($application);
    }

    private function reverbDescription(Application $application): string
    {
        $reverb = app(Reverb::class);
        $port = $reverb->port($application);
        $socket = 'wss://' . $application->domain . '/app';
        $problem = $reverb->problem($application);

        return match (true) {
            $problem !== null => $problem,
            $reverb->live($application) => "php artisan reverb:start · port {$port} · {$socket}",
            $reverb->on($application) => "Starts with the next deploy, on port {$port} behind {$socket}",
            default => "Available, turning it on gives it port {$port} from the next deploy",
        };
    }

    private function horizon(Application $application): bool
    {
        return ($this->packages($application)['horizon'] ?? false) === true;
    }

    private function offers(Application $application, string $key): bool
    {
        return (((array) $application->stack)['gems'][self::GEMS[$key]] ?? false) === true || $this->isOn($application, $key);
    }

    /**
     * @return array{starting: bool, failure: string|null}
     */
    private function outcome(Application $application, string $key): array
    {
        if ($this->process($key) === null || !$application->exists) {
            return ['starting' => false, 'failure' => null];
        }

        $latest = Activity::query()
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->whereIn('description', [self::CHANGED, self::STARTED, self::START_FAILED])
            ->where('properties->toggle', $key)
            ->latest('created_at')
            ->latest('id')
            ->first();

        $running = !in_array($application->power_state, [PowerState::Stopped, PowerState::Sleeping], true);

        return [
            'starting' => !in_array($key, [self::INERTIA_SSR, self::REVERB], true)
                && $latest?->description === self::CHANGED
                && ($latest->properties['on'] ?? false) === true
                && $latest->created_at?->greaterThanOrEqualTo(now()->subSeconds(self::START_WINDOW_SECONDS)) === true
                && $running
                && $this->isOn($application, $key),
            'failure' => $latest?->description === self::START_FAILED && $latest->created_at?->greaterThanOrEqualTo(now()->subDay()) === true
                ? (string) ($latest->properties['error'] ?? '')
                : null,
        ];
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

    private function autoDeploy(Application $application, bool $on): void
    {
        $application->forceFill(['quick_deploy' => $on, 'polled_at' => null, 'poll_error' => null])->save();
    }

    private function hasProcess(Application $application, string $name): bool
    {
        return collect((array) $application->processes)->contains(fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) === $name);
    }
}
