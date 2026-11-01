<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Jobs\DeployApplication;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\DiscardRelease;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Exception\UnableToConnectException;
use Spatie\Activitylog\Models\Activity;
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

function staleCancelledDeployment(array $deployment = [], array $application = []): Deployment
{
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $app = Application::factory()->for($server)->create(['slug' => 'shop', 'status' => ApplicationStatus::Deploying, 'current_release' => '20261019-090000', ...$application]);

    return Deployment::factory()->for($app)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
        'release' => '20261020-120000',
        'log' => "[12:00:00] clone: fetching over https\n[12:00:05] deps: installing dependencies",
        'cancel_requested_at' => now()->subMinutes(18),
        'cancelled_by' => User::factory()->create(['name' => 'Maya Lindqvist'])->id,
        ...$deployment,
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

test("a dead deploy a person asked to cancel reads Cancelled, as that person's cancel", function () {
    Event::fake([DeploymentFailed::class]);
    Queue::fake();
    $deployment = staleCancelledDeployment();

    (new DetectStaleDeployments)->handle();

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Cancelled)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->failure_reason)->toBeNull()
        ->and($fresh->cancelled_by)->toBe($deployment->cancelled_by)
        ->and($fresh->log)->toStartWith("[12:00:00] clone: fetching over https\n[12:00:05] deps: installing dependencies\ncancel: Maya Lindqvist asked to stop the deploy\n")
        ->and($fresh->log)->toMatch('/\n\[\d{2}:\d{2}:\d{2}\] deploy cancelled\. the current release is untouched\.$/')
        ->and($fresh->log)->not->toContain('No progress was reported')
        ->and(Activity::query()->where('description', 'deployment_cancelled')->sole()->properties->toArray())->toBe(['by' => 'Maya Lindqvist', 'phase' => 'deps']);

    Event::assertNotDispatched(DeploymentFailed::class);
});

test('a cancelled dead deploy frees the lock, puts the app back and still has its half-built release removed', function (?string $live, ApplicationStatus $status) {
    Queue::fake();
    $deployment = staleCancelledDeployment(application: ['current_release' => $live]);
    Cache::lock('deploy:' . $deployment->application_id, 600)->get();

    (new DetectStaleDeployments)->handle();

    expect($deployment->application->fresh()->status)->toBe($status)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 600)->get())->toBeTrue();

    Queue::assertPushed(DiscardRelease::class, 1);
    Queue::assertPushedOn('server-tasks', DiscardRelease::class, fn(DiscardRelease $job): bool => (fn(): Deployment => $this->deployment)->call($job)->is($deployment));
})->with([
    'an app with a live release' => ['20261019-090000', ApplicationStatus::Active],
    'a first deploy that never went live' => [null, ApplicationStatus::Pending],
]);

test('the sweep removes the half-built release of a cancelled dead deploy from its server', function () {
    $deployment = staleCancelledDeployment();
    $transport = (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/shop/current'", "/var/coresmith/apps/shop/releases/20261019-090000\n");

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DetectStaleDeployments)->handle();
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($transport->commands())->toBe([
            "readlink '/var/coresmith/apps/shop/current'",
            "rm -rf '/var/coresmith/apps/shop/releases/20261020-120000'",
        ]);
});

test('a dead deploy past its migrations still fails, even with a cancel asked', function (string $marker) {
    Event::fake([DeploymentFailed::class]);
    Queue::fake();
    $deployment = staleCancelledDeployment(['log' => "[12:00:00] clone: fetching over https\n" . $marker]);
    Cache::lock('deploy:' . $deployment->application_id, 600)->get();

    (new DetectStaleDeployments)->handle();

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Failed)
        ->and($fresh->failure_reason)->toBe('No progress was reported for 20 minutes, so Coresmith stopped waiting for this deploy. Deploy again.')
        ->and($fresh->log)->toContain('No progress was reported for 20 minutes')
        ->and($fresh->log)->not->toContain('cancel:')
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 600)->get())->toBeTrue()
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Event::assertDispatchedTimes(DeploymentFailed::class, 1);
    Queue::assertPushed(DiscardRelease::class, 1);
})->with([
    'migrations' => ['[12:00:30] migrate: running migrations against release 20261020-120000'],
    'the health check' => ['[12:00:30] health: checking the release before it goes live'],
    'the swap' => ['[12:00:30] swap: current → releases/20261020-120000'],
    'a custom blueprint' => ['[12:00:30] build: running the custom blueprint'],
]);

test('a dead deploy nobody asked to cancel is failed as before', function () {
    Event::fake([DeploymentFailed::class]);
    Queue::fake();
    $deployment = staleCancelledDeployment(['cancel_requested_at' => null, 'cancelled_by' => null]);
    Cache::lock('deploy:' . $deployment->application_id, 600)->get();

    (new DetectStaleDeployments)->handle();

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Failed)
        ->and($fresh->failure_reason)->toBe('No progress was reported for 20 minutes, so Coresmith stopped waiting for this deploy. Deploy again.')
        ->and($fresh->log)->toBe("[12:00:00] clone: fetching over https\n[12:00:05] deps: installing dependencies\nNo progress was reported for 20 minutes, so Coresmith stopped waiting for this deploy. Deploy again.")
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 600)->get())->toBeTrue()
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Event::assertDispatchedTimes(DeploymentFailed::class, 1);
    Queue::assertPushed(DiscardRelease::class, 1);
});

test('a cancelled deploy whose deadline moves out after it was picked is neither cancelled, unlocked nor cleaned up', function () {
    Event::fake([DeploymentFailed::class]);
    Queue::fake();
    $deployment = staleCancelledDeployment(['started_at' => now()->subMinutes(40), 'stale_after' => now()->subMinute()]);
    Cache::lock('deploy:' . $deployment->application_id, 600)->get();

    Deployment::retrieved(function (Deployment $retrieved): void {
        Deployment::query()->whereKey($retrieved->getKey())->update(['stale_after' => now()->addMinutes(15)]);
    });

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running)
        ->and($deployment->fresh()->log)->toBe("[12:00:00] clone: fetching over https\n[12:00:05] deps: installing dependencies")
        ->and($deployment->fresh()->finished_at)->toBeNull()
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Deploying)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 600)->get())->toBeFalse()
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Event::assertNotDispatched(DeploymentFailed::class);
    Queue::assertNotPushed(DiscardRelease::class);
});

test('a log line written after the sweep read a cancelled deploy is kept', function () {
    Queue::fake();
    $deployment = staleCancelledDeployment();
    $written = false;

    Deployment::retrieved(function (Deployment $retrieved) use (&$written): void {
        if ($written) {
            return;
        }

        $written = true;
        Deployment::query()->whereKey($retrieved->getKey())->update(['log' => $retrieved->log . "\n[12:00:10] deps: still going"]);
    });

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->toContain("\n[12:00:10] deps: still going\ncancel: Maya Lindqvist asked to stop the deploy\n");
});

test('a cancelled dead deploy of a removed app ends Cancelled, and the next stale deploy in the run is still failed', function () {
    Event::fake([DeploymentFailed::class]);
    Queue::fake();
    $cancelled = staleCancelledDeployment();
    $cancelled->application->delete();
    $other = staleCancelledDeployment(['cancel_requested_at' => null, 'cancelled_by' => null], ['slug' => 'blog']);

    (new DetectStaleDeployments)->handle();

    expect($cancelled->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($cancelled->fresh()->log)->toMatch('/\n\[\d{2}:\d{2}:\d{2}\] deploy cancelled\. the current release is untouched\.$/')
        ->and($other->fresh()->status)->toBe(DeploymentStatus::Failed);

    Event::assertDispatchedTimes(DeploymentFailed::class, 1);
    Queue::assertPushed(DiscardRelease::class, 2);
});

test('a cancelled dead deploy hands a newer push to auto deploy once its lock is free, and never its own head', function (bool $pushedSince) {
    Queue::fake();
    $head = str_repeat('a', 40);
    $newer = str_repeat('b', 40);
    $deployment = staleCancelledDeployment(['requested_commit' => $head], ['quick_deploy' => true, 'repository_head' => $head]);
    Cache::lock('deploy:' . $deployment->application_id, 600)->get();
    $lockFree = null;

    if ($pushedSince) {
        app(AutoDeploy::class)->observe($deployment->application->fresh(), $newer, 'push');
    }

    Deployment::created(function (Deployment $created) use (&$lockFree): void {
        $lock = Cache::lock('deploy:' . $created->application_id, 10);
        $lockFree = $lock->get();
        $lock->release();
    });

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(Deployment::query()->where('requested_commit', $head)->count())->toBe(1)
        ->and(Deployment::query()->where('requested_commit', $newer)->count())->toBe($pushedSince ? 1 : 0)
        ->and($lockFree)->toBe($pushedSince ? true : null)
        ->and(Queue::pushed(DeployApplication::class))->toHaveCount($pushedSince ? 1 : 0);
})->with([
    'a push landed meanwhile' => [true],
    'nothing new was pushed' => [false],
]);

test('a cancel that throws once it has won still frees the lock, removes the release and follows up', function () {
    Queue::fake();
    $newer = str_repeat('b', 40);
    $deployment = staleCancelledDeployment(['requested_commit' => str_repeat('a', 40)], ['quick_deploy' => true, 'repository_head' => $newer]);
    Cache::lock('deploy:' . $deployment->application_id, 600)->get();

    Application::saving(function (): void {
        throw new RuntimeException('The database went away.');
    });

    expect(fn() => (new DetectStaleDeployments)->handle())->toThrow(RuntimeException::class, 'The database went away.');

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 10)->get())->toBeTrue()
        ->and(Deployment::query()->where('requested_commit', $newer)->count())->toBe(1);

    Queue::assertPushed(DiscardRelease::class, 1);
    Queue::assertPushed(DeployApplication::class, 1);
});
