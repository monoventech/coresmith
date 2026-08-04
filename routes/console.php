<?php

use App\Enums\ServerStatus;
use App\Jobs\CollectServerMetrics;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\IdleAppSleeper;
use App\Models\Server;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    Server::query()
        ->where('status', ServerStatus::Active->value)
        ->each(fn (Server $server) => CollectServerMetrics::dispatch($server));
})->everyMinute()->name('coresmith:collect-metrics')->withoutOverlapping();

Schedule::job(new IdleAppSleeper)->everyFiveMinutes()->name('coresmith:sleep-idle-apps');

Schedule::job(new DetectStaleDeployments)->everyFiveMinutes()->name('coresmith:detect-stale-deployments');
