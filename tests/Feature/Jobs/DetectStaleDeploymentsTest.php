<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\DiscardRelease;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Exception\UnableToConnectException;
use Tests\Support\FakeSSHTransport;

function staleReleaseDeployment(string $slug = 'shop'): Deployment
{
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $application = Application::factory()->for($server)->create(['slug' => $slug, 'status' => ApplicationStatus::Deploying, 'current_release' => '20261019-090000']);

    return Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
        'release' => '20261020-120000',
    ]);
}

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

test('a deployment whose deadline moves out after it was picked is neither failed, unlocked nor cleaned up', function (?int $minutesPast) {
    Event::fake([DeploymentFailed::class]);
    Queue::fake();
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(40),
        'stale_after' => $minutesPast === null ? null : now()->subMinutes($minutesPast),
        'release' => '20261020-120000',
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
    Queue::assertNotPushed(DiscardRelease::class);
})->with([
    'past its deadline' => [1],
    'an older row with no deadline' => [null],
]);

test("a reclaimed deploy's half-built release is removed", function () {
    Queue::fake();
    $deployment = staleReleaseDeployment();

    (new DetectStaleDeployments)->handle();

    Queue::assertPushed(DiscardRelease::class, 1);
    Queue::assertPushedOn('server-tasks', DiscardRelease::class, fn(DiscardRelease $job): bool => (fn(): Deployment => $this->deployment)->call($job)->is($deployment));
});

test('the sweep removes the half-built release from the server the deploy ran on', function () {
    $deployment = staleReleaseDeployment();
    $transport = (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/shop/current'", "/var/coresmith/apps/shop/releases/20261019-090000\n");

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DetectStaleDeployments)->handle();
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($transport->commands())->toBe([
            "readlink '/var/coresmith/apps/shop/current'",
            "rm -rf '/var/coresmith/apps/shop/releases/20261020-120000'",
        ]);
});

test('the removal is queued before the failure is announced', function () {
    Queue::fake();
    $queuedWhenAnnounced = null;

    Event::listen(DeploymentFailed::class, function () use (&$queuedWhenAnnounced): void {
        $queuedWhenAnnounced ??= Queue::pushed(DiscardRelease::class)->count();
    });

    staleReleaseDeployment();

    (new DetectStaleDeployments)->handle();

    expect($queuedWhenAnnounced)->toBe(1);
});

test('a removal that fails does not stop the sweep or the failure notice', function () {
    Exceptions::fake();
    Event::fake([DeploymentFailed::class]);
    $first = staleReleaseDeployment();
    $second = staleReleaseDeployment('blog');
    $transport = (new FakeSSHTransport)->refuseConnections();

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DetectStaleDeployments)->handle();
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($first->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($second->fresh()->status)->toBe(DeploymentStatus::Failed);

    Event::assertDispatchedTimes(DeploymentFailed::class, 2);
    Exceptions::assertReported(UnableToConnectException::class);
});
