<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Jobs\DetectStaleDeployments;
use App\Models\Deployment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

test('a deployment stuck running past the cutoff is failed', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->log)->toContain('timed out')
        ->and($deployment->fresh()->finished_at)->not->toBeNull();

    Event::assertDispatched(DeploymentFailed::class);
});

test('a deployment still inside the window is left alone', function () {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(5),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running);
});

test('a finished deployment is never touched', function () {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Succeeded,
        'started_at' => now()->subHours(3),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);
});

test('the stale deployment lock is force released so the app can deploy again', function () {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
    ]);

    Cache::lock('deploy:' . $deployment->application_id, 600)->get();

    (new DetectStaleDeployments)->handle();

    expect(Cache::lock('deploy:' . $deployment->application_id, 600)->get())->toBeTrue();
});
