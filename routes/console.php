<?php

use App\Enums\ServerStatus;
use App\Jobs\CheckCertificates;
use App\Jobs\CheckGitHubWebhooks;
use App\Jobs\CollectServerMetrics;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\IdleAppSleeper;
use App\Jobs\PollRepositories;
use App\Jobs\PruneRemovedApplications;
use App\Jobs\ResumeCloudTasks;
use App\Models\InstanceSetting;
use App\Models\MoveCode;
use App\Models\Server;
use App\Models\StripeEvent;
use App\Services\Deployment\RemovedApplicationFolders;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdateState;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    Server::query()
        ->where('status', ServerStatus::Active->value)
        ->whereNull('pending_host_key_fingerprint')
        ->each(fn(Server $server) => CollectServerMetrics::dispatch($server));
})->everyMinute()->name('coresmith:collect-metrics')->withoutOverlapping();

Schedule::call(function (): void {
    Server::query()
        ->where('status', ServerStatus::Active->value)
        ->whereNull('pending_host_key_fingerprint')
        ->each(fn(Server $server) => CheckCertificates::dispatch($server));
})->dailyAt('05:30')->name('coresmith:check-certificates')->withoutOverlapping();

Schedule::call(function (): void {
    Server::query()
        ->where('status', ServerStatus::Active->value)
        ->whereNull('unreachable_since')
        ->whereNull('pending_host_key_fingerprint')
        ->each(function (Server $server): void {
            if (app(RemovedApplicationFolders::class)->due($server)->isNotEmpty()) {
                PruneRemovedApplications::dispatch($server);
            }
        });
})->dailyAt('03:45')->name('coresmith:prune-removed-apps')->withoutOverlapping();

Schedule::job(new IdleAppSleeper)->everyFiveMinutes()->name('coresmith:sleep-idle-apps');

Schedule::job(new DetectStaleDeployments)->everyFiveMinutes()->name('coresmith:detect-stale-deployments');

Schedule::job(new CheckGitHubWebhooks)->everyFifteenMinutes()->name('coresmith:check-github-webhooks')->withoutOverlapping();

Schedule::job(new PollRepositories)->everyMinute()->name('coresmith:poll-repositories')->withoutOverlapping();

Schedule::job(new ResumeCloudTasks)->everyMinute()->name('coresmith:resume-cloud-tasks')->withoutOverlapping();

Schedule::command('coresmith:prune-metrics')->weeklyOn(0, '03:15')->name('coresmith:prune-metrics')->withoutOverlapping();
Schedule::command('coresmith:prune-activity')->dailyAt('03:30')->name('coresmith:prune-activity')->withoutOverlapping();

Schedule::command('model:prune', ['--model' => [StripeEvent::class]])->dailyAt('05:00')->name('coresmith:prune-stripe-events');

Schedule::command('model:prune', ['--model' => [MoveCode::class]])->dailyAt('05:10')->name('coresmith:prune-move-codes');

Schedule::command('coresmith:trial-reminders')->dailyAt('09:00')->name('coresmith:trial-reminders')->withoutOverlapping();

Schedule::command('coresmith:backup')->dailyAt('04:00')->name('coresmith:backup')->withoutOverlapping();

Schedule::command('coresmith:backup-databases')->dailyAt('02:30')->name('coresmith:backup-databases')->withoutOverlapping();

Schedule::command('coresmith:weekly-digest')->weeklyOn(1, '08:00')->name('coresmith:weekly-digest')->withoutOverlapping();

Schedule::command('coresmith:check-update')
    ->dailyAt(UpdateChecker::dailyAt())
    ->name('coresmith:check-update')
    ->withoutOverlapping()
    ->when(fn(): bool => app(UpdateChecker::class)->enabled());

Schedule::command('coresmith:update --auto')
    ->dailyAt('04:00')
    ->name('coresmith:auto-update')
    ->withoutOverlapping()
    ->when(fn(): bool => (bool) config('coresmith.updates.enabled') && InstanceSetting::get('auto_update') === '1');

Schedule::command('coresmith:update --recover')
    ->everyMinute()
    ->evenInMaintenanceMode()
    ->name('coresmith:recover-update')
    ->withoutOverlapping(5)
    ->when(fn(): bool => (bool) config('coresmith.updates.enabled') && app(UpdateState::class)->interrupted());
