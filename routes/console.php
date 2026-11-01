<?php

use App\Enums\ServerStatus;
use App\Jobs\CheckCertificates;
use App\Jobs\CheckGitHubWebhooks;
use App\Jobs\CollectServerMetrics;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\IdleAppSleeper;
use App\Jobs\PollRepositories;
use App\Models\Server;
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

Schedule::job(new IdleAppSleeper)->everyFiveMinutes()->name('coresmith:sleep-idle-apps');

Schedule::job(new DetectStaleDeployments)->everyFiveMinutes()->name('coresmith:detect-stale-deployments');

Schedule::job(new CheckGitHubWebhooks)->everyFifteenMinutes()->name('coresmith:check-github-webhooks')->withoutOverlapping();

Schedule::job(new PollRepositories)->everyMinute()->name('coresmith:poll-repositories')->withoutOverlapping();

Schedule::command('coresmith:prune-metrics')->weeklyOn(0, '03:15')->name('coresmith:prune-metrics')->withoutOverlapping();
Schedule::command('coresmith:prune-activity')->dailyAt('03:30')->name('coresmith:prune-activity')->withoutOverlapping();

Schedule::command('coresmith:backup')->dailyAt('04:00')->name('coresmith:backup')->withoutOverlapping();

Schedule::command('coresmith:weekly-digest')->weeklyOn(1, '08:00')->name('coresmith:weekly-digest')->withoutOverlapping();
