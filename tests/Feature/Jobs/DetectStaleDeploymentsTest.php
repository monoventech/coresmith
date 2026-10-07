<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Jobs\DetectStaleDeployments;
use App\Models\Application;
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
        ->and($deployment->fresh()->log)->toContain('No progress was reported')
        ->and($deployment->fresh()->finished_at)->not->toBeNull();

    Event::assertDispatched(DeploymentFailed::class);
});

test('a reclaim marks the app active when it already has a live release, and writes why the deploy failed', function () {
    $application = Application::factory()->create(['status' => ApplicationStatus::Deploying, 'current_release' => '20260910-090000']);
    $deployment = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and($deployment->fresh()->failure_reason)->toBe('No progress was reported for 20 minutes, so Coresmith stopped waiting for this deploy. Deploy again.');
});

test('a reclaim marks the app failed when it never finished a first deploy', function () {
    $application = Application::factory()->create(['status' => ApplicationStatus::Deploying, 'current_release' => null]);
    $deployment = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($application->fresh()->status)->toBe(ApplicationStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe('No progress was reported for 20 minutes, so Coresmith stopped waiting for this deploy. Deploy again.');
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

test('a deployment whose deadline was pushed out for a runtime install is left alone', function () {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(40),
        'stale_after' => now()->addMinutes(20),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running);
});

test('a deployment past its deadline is reclaimed however recently it started', function () {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(2),
        'stale_after' => now()->subMinute(),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->log)->toContain('No progress was reported for 2 minutes, so Coresmith stopped waiting for this deploy. Deploy again.');
});

test('a stale deployment of a soft-deleted app is failed, and a second unrelated stale deployment in the same run is also failed', function () {
    Event::fake([DeploymentFailed::class]);
    $removedApp = Application::factory()->create(['status' => ApplicationStatus::Active, 'current_release' => '20260910-090000']);
    $removedDeployment = Deployment::factory()->for($removedApp)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
    ]);
    $removedApp->delete();

    $otherApp = Application::factory()->create(['status' => ApplicationStatus::Deploying, 'current_release' => null]);
    $otherDeployment = Deployment::factory()->for($otherApp)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($removedDeployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($otherDeployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($otherApp->fresh()->status)->toBe(ApplicationStatus::Failed);

    Event::assertDispatched(DeploymentFailed::class, 2);
});

test('a deployment whose deadline moves out after it was picked is neither failed nor unlocked', function (?int $minutesPast) {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(40),
        'stale_after' => $minutesPast === null ? null : now()->subMinutes($minutesPast),
    ]);
    $applicationStatus = $deployment->application->status;
    Cache::lock('deploy:' . $deployment->application_id, 600)->get();

    Deployment::retrieved(function (Deployment $retrieved): void {
        Deployment::query()->whereKey($retrieved->getKey())->update(['stale_after' => now()->addMinutes(15)]);
    });

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running)
        ->and($deployment->fresh()->log)->toBeNull()
        ->and($deployment->fresh()->failure_reason)->toBeNull()
        ->and($deployment->application->fresh()->status)->toBe($applicationStatus)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 600)->get())->toBeFalse();

    Event::assertNotDispatched(DeploymentFailed::class);
})->with([
    'past its deadline' => [1],
    'an older row with no deadline' => [null],
]);
