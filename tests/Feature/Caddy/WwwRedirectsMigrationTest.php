<?php

declare(strict_types=1);

use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use Illuminate\Support\Facades\Queue;

test('updating reroutes every live app that gains a www name, and leaves the rest alone', function () {
    Queue::fake();
    $apex = Application::factory()->create(['domain' => 'example.com', 'additional_domains' => [], 'current_release' => '20261117-120000']);
    $extra = Application::factory()->create(['domain' => null, 'additional_domains' => ['www.example.org'], 'current_release' => '20261117-120000']);
    Application::factory()->create(['domain' => 'app.example.net', 'additional_domains' => [], 'current_release' => '20261117-120000']);
    Application::factory()->create(['domain' => 'example.io', 'additional_domains' => [], 'current_release' => null]);
    Application::factory()->create(['domain' => null, 'additional_domains' => null, 'current_release' => '20261117-120000']);

    (require database_path('migrations/2026_11_17_160000_route_www_redirects.php'))->up();

    Queue::assertPushed(SyncApplicationRoute::class, 2);
    Queue::assertPushed(SyncApplicationRoute::class, fn(SyncApplicationRoute $job): bool => $job->application->is($apex));
    Queue::assertPushed(SyncApplicationRoute::class, fn(SyncApplicationRoute $job): bool => $job->application->is($extra));
});
