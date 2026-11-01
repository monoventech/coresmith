<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Events\DeploymentUpdated;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use phpseclib3\Exception\ConnectionClosedException;
use Tests\Support\FakeSSHTransport;

const KILLED_RELEASE = '20261019-120000';

const KILLED_TIMEOUT_REASON = 'The deploy ran longer than 13 minutes, so Coresmith stopped it. Deploy again.';

const KILLED_LEFTOVER = 'Could not remove the half-built release. Remove releases/' . KILLED_RELEASE . ' by hand if it is still there.';

function killedDeployment(array $application = [], array $deployment = []): Deployment
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'name' => 'web-1']);
    $app = Application::factory()->for($server)->create(['slug' => 'shop', 'status' => ApplicationStatus::Deploying, 'current_release' => null, ...$application]);

    return Deployment::factory()->for($app)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(13),
        'stale_after' => now()->addMinutes(2),
        'release' => KILLED_RELEASE,
        'log' => '[12:00:00] release ' . KILLED_RELEASE,
        ...$deployment,
    ]);
}

function killedFailed(Deployment $deployment, FakeSSHTransport $transport, Throwable $e, ?string $owner = null, string $release = KILLED_RELEASE): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, $release, $owner))->failed($e);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function killedTimeout(): TimeoutExceededException
{
    return new TimeoutExceededException('App\Jobs\DeployApplication has timed out.');
}

function killedLiveTransport(string $target = '/var/coresmith/apps/shop/releases/' . KILLED_RELEASE): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/shop/current'", $target . "\n");
}

function killedLockKey(Deployment $deployment): string
{
    return 'deploy:' . $deployment->application_id;
}

test('a deploy the worker timed out is failed at once, saying why in the log and the reason', function () {
    Event::fake([DeploymentFailed::class, DeploymentUpdated::class]);
    $deployment = killedDeployment();

    killedFailed($deployment, new FakeSSHTransport, killedTimeout());

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Failed)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->failure_reason)->toBe(KILLED_TIMEOUT_REASON)
        ->and($fresh->log)->toStartWith('[12:00:00] release ' . KILLED_RELEASE . "\n" . KILLED_TIMEOUT_REASON . "\n")
        ->and($fresh->log)->toMatch('/\n\[\d{2}:\d{2}:\d{2}\] deploy failed\. the current release is untouched\.$/');

    Event::assertDispatchedTimes(DeploymentFailed::class, 1);
    Event::assertDispatched(DeploymentUpdated::class, fn(DeploymentUpdated $event): bool => $event->deployment->status === DeploymentStatus::Failed);
});

test('a deploy whose worker died is failed when the job comes back, saying the worker stopped', function () {
    $deployment = killedDeployment();

    killedFailed($deployment, new FakeSSHTransport, new MaxAttemptsExceededException('App\Jobs\DeployApplication has been attempted too many times.'));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe('The worker running this deploy stopped before the deploy finished, so Coresmith ended it. Deploy again.');
});

test('a killed deploy puts the app back the way the reclaim does', function (?string $live, ApplicationStatus $status) {
    $deployment = killedDeployment(['current_release' => $live]);

    killedFailed($deployment, killedLiveTransport('/var/coresmith/apps/shop/releases/20261001-090000'), killedTimeout());

    expect($deployment->application->fresh()->status)->toBe($status);
})->with([
    'a first deploy that never went live' => [null, ApplicationStatus::Failed],
    'an app with a live release' => ['20261001-090000', ApplicationStatus::Active],
]);

test('a killed first run frees the lock it took, so the next deploy can start', function () {
    $deployment = killedDeployment();
    Cache::lock(killedLockKey($deployment), DeployApplication::LOCK_SECONDS)->get();

    killedFailed($deployment, new FakeSSHTransport, killedTimeout());

    expect(Cache::lock(killedLockKey($deployment), 10)->get())->toBeTrue();
});

test('a killed resumed deploy gives back the lock it was handed', function () {
    $deployment = killedDeployment();
    Cache::lock(killedLockKey($deployment), DeployApplication::LOCK_SECONDS, 'owner-1')->get();

    killedFailed($deployment, new FakeSSHTransport, killedTimeout(), 'owner-1');

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Cache::lock(killedLockKey($deployment), 10)->get())->toBeTrue();
});

test('a deploy that already finished is left alone, and a lock another deploy holds stays held', function (DeploymentStatus $status, ?string $owner) {
    Event::fake([DeploymentFailed::class]);
    $deployment = killedDeployment(deployment: ['status' => $status, 'failure_reason' => 'first', 'finished_at' => now()->subMinute()]);
    $finished = $deployment->fresh()->getAttributes();
    Cache::lock(killedLockKey($deployment), 600, 'someone-else')->get();
    $transport = new FakeSSHTransport;

    killedFailed($deployment, $transport, killedTimeout(), $owner);

    expect($deployment->fresh()->getAttributes())->toBe($finished)
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Deploying)
        ->and($transport->commands())->toBe([])
        ->and(Cache::lock(killedLockKey($deployment), 10)->get())->toBeFalse();

    Event::assertNotDispatched(DeploymentFailed::class);
})->with([
    'succeeded, first run' => [DeploymentStatus::Succeeded, null],
    'failed, first run' => [DeploymentStatus::Failed, null],
    'cancelled, first run' => [DeploymentStatus::Cancelled, null],
    'failed, resumed' => [DeploymentStatus::Failed, 'owner-1'],
]);

test('a deployment that leaves running after it was read is neither failed again nor unlocked', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = killedDeployment();
    Cache::lock(killedLockKey($deployment), 600)->get();
    $transport = new FakeSSHTransport;

    Deployment::retrieved(function (Deployment $retrieved): void {
        Deployment::query()->whereKey($retrieved->getKey())->update(['status' => DeploymentStatus::Failed->value, 'failure_reason' => 'reclaimed']);
    });

    killedFailed($deployment, $transport, killedTimeout());

    expect($deployment->fresh()->failure_reason)->toBe('reclaimed')
        ->and($deployment->fresh()->log)->toBe('[12:00:00] release ' . KILLED_RELEASE)
        ->and($transport->commands())->toBe([])
        ->and(Cache::lock(killedLockKey($deployment), 10)->get())->toBeFalse();

    Event::assertNotDispatched(DeploymentFailed::class);
});

test('a second failed() for the same deploy writes nothing twice', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = killedDeployment();

    killedFailed($deployment, new FakeSSHTransport, killedTimeout());
    killedFailed($deployment, new FakeSSHTransport, killedTimeout());

    expect(substr_count((string) $deployment->fresh()->log, KILLED_TIMEOUT_REASON))->toBe(1);

    Event::assertDispatchedTimes(DeploymentFailed::class, 1);
});

test('the half-built release is removed when current does not point at it', function (string $readlink, int $exitCode) {
    $deployment = killedDeployment();
    $transport = (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/shop/current'", $readlink, $exitCode);

    killedFailed($deployment, $transport, killedTimeout());

    $commands = $transport->commands();
    $read = array_search("readlink '/var/coresmith/apps/shop/current'", $commands, true);
    $remove = array_search("rm -rf '/var/coresmith/apps/shop/releases/" . KILLED_RELEASE . "'", $commands, true);

    expect($read)->not->toBeFalse()
        ->and($remove)->not->toBeFalse()
        ->and($read)->toBeLessThan($remove)
        ->and($deployment->fresh()->log)->not->toContain('Could not remove');
})->with([
    'current is an older release' => ["/var/coresmith/apps/shop/releases/20261001-090000\n", 0],
    'there is no current yet' => ['', 1],
]);

test('a release current points at is never removed, even before the app records it as live', function (?string $recorded, string $target) {
    $deployment = killedDeployment(['current_release' => $recorded]);
    $transport = killedLiveTransport($target);

    killedFailed($deployment, $transport, killedTimeout());

    expect(implode("\n", $transport->commands()))->not->toContain('rm -rf')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
})->with([
    'the app records the swap' => [KILLED_RELEASE, '/var/coresmith/apps/shop/releases/' . KILLED_RELEASE],
    'the worker died between the swap and the save' => ['20261001-090000', '/var/coresmith/apps/shop/releases/' . KILLED_RELEASE],
    'an older relative link' => ['20261001-090000', 'releases/' . KILLED_RELEASE],
]);

test('a deploy killed after the swap says its release is live', function () {
    $deployment = killedDeployment(['current_release' => KILLED_RELEASE]);

    killedFailed($deployment, killedLiveTransport(), killedTimeout());

    expect($deployment->fresh()->log)->toMatch('/\] deploy failed after the symlink swap\. releases\/' . KILLED_RELEASE . ' is live, check its processes\.$/')
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('a server that cannot be reached for the clean up is reported, and the deploy still ends failed and unlocked', function (string $needle) {
    Exceptions::fake();
    $deployment = killedDeployment();
    Cache::lock(killedLockKey($deployment), 600)->get();
    $transport = (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/shop/current'", "/var/coresmith/apps/shop/releases/20261001-090000\n")->dropConnectionOn($needle);

    killedFailed($deployment, $transport, killedTimeout());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe(KILLED_TIMEOUT_REASON)
        ->and($deployment->fresh()->log)->toEndWith(KILLED_LEFTOVER)
        ->and(Cache::lock(killedLockKey($deployment), 10)->get())->toBeTrue();

    Exceptions::assertReported(ConnectionClosedException::class);
})->with([
    'while reading current' => ['readlink'],
    'while removing the release' => ['rm -rf'],
]);

test('a clean up note that cannot be saved is reported, and the lock is still given back', function () {
    Exceptions::fake();
    $deployment = killedDeployment();
    Cache::lock(killedLockKey($deployment), 600)->get();
    $transport = (new FakeSSHTransport)->dropConnectionOn('readlink');

    Deployment::saving(function (Deployment $saving): void {
        if (str_contains((string) $saving->log, 'Could not remove')) {
            throw new RuntimeException('The database went away.');
        }
    });

    killedFailed($deployment, $transport, killedTimeout());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Cache::lock(killedLockKey($deployment), 10)->get())->toBeTrue();

    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'The database went away.');
});

test('a release that cannot be removed is named in the log', function () {
    $deployment = killedDeployment();
    $transport = killedLiveTransport('/var/coresmith/apps/shop/releases/20261001-090000')->respondTo('rm -rf', "rm: cannot remove: Device or resource busy\n", 1);

    killedFailed($deployment, $transport, killedTimeout());

    expect($deployment->fresh()->log)->toEndWith(KILLED_LEFTOVER);
});

test('the copy of the live data a killed deploy made is thrown away, unless its release went live', function (string $target, bool $discarded) {
    $deployment = killedDeployment(deployment: ['seeded_shared_storage' => true]);
    $transport = killedLiveTransport($target);

    killedFailed($deployment, $transport, killedTimeout());

    expect(in_array("rm -rf '/var/coresmith/apps/shop/shared/storage'", $transport->commands(), true))->toBe($discarded)
        ->and($deployment->fresh()->seeded_shared_storage)->toBe(!$discarded);
})->with([
    'not live' => ['/var/coresmith/apps/shop/releases/20261001-090000', true],
    'live' => ['/var/coresmith/apps/shop/releases/' . KILLED_RELEASE, false],
]);

test('a resumed deploy whose lock another deploy now holds keeps that lock and that deploy data', function () {
    $deployment = killedDeployment(deployment: ['seeded_shared_storage' => true]);
    Cache::lock(killedLockKey($deployment), 600, 'someone-else')->get();
    $transport = killedLiveTransport('/var/coresmith/apps/shop/releases/20261001-090000');

    killedFailed($deployment, $transport, killedTimeout(), 'owner-1');

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($transport->commands())->not->toContain("rm -rf '/var/coresmith/apps/shop/shared/storage'")
        ->and(Cache::lock(killedLockKey($deployment), 600, 'someone-else')->isOwnedByCurrentProcess())->toBeTrue();
});

test('a timeout handler running inside the same worker reconnects instead of reusing the busy deploy connection', function () {
    $deployment = killedDeployment();
    $transport = new FakeSSHTransport;

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    app(SSHConnectionManager::class)->for($deployment->application->server)->connect();

    killedFailed($deployment, $transport, killedTimeout());

    expect($transport->calls())->toBe(['connect', 'disconnect', 'connect', 'disconnect']);
});

test('a removed app and a detached server both still end failed with the lock released', function (bool $removeApplication) {
    $deployment = killedDeployment();
    Cache::lock(killedLockKey($deployment), 600)->get();
    $transport = new FakeSSHTransport;

    if ($removeApplication) {
        $deployment->application->delete();
    } else {
        $deployment->application->server->delete();
    }

    killedFailed($deployment, $transport, killedTimeout());

    expect($transport->commands())->toBe([])
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Cache::lock(killedLockKey($deployment), 10)->get())->toBeTrue();
})->with([
    'a removed application' => [true],
    'a detached server' => [false],
]);

test('a job whose release is not a release name never removes anything', function (string $release) {
    $deployment = killedDeployment();
    $transport = killedLiveTransport('/var/coresmith/apps/shop/releases/20261001-090000');

    killedFailed($deployment, $transport, killedTimeout(), release: $release);

    expect(implode("\n", $transport->commands()))->not->toContain('rm -rf')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
})->with([
    'empty' => [''],
    'a traversal' => ['../../shared'],
    'a trailing newline' => [KILLED_RELEASE . "\n"],
]);
