<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DiscardRelease;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeSSHTransport;

const CLEANUP_RELEASE = '20261020-120000';

const CLEANUP_READLINK = "readlink '/var/coresmith/apps/shop/current'";

const CLEANUP_REMOVAL = "rm -rf '/var/coresmith/apps/shop/releases/" . CLEANUP_RELEASE . "'";

const CLEANUP_LEFTOVER = 'Could not remove the half-built release. Remove releases/' . CLEANUP_RELEASE . ' by hand if it is still there.';

function cleanupDeployment(array $deployment = [], array $application = []): Deployment
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'name' => 'web-1']);
    $app = Application::factory()->for($server)->create(['slug' => 'shop', 'status' => ApplicationStatus::Active, 'current_release' => '20261019-090000', ...$application]);

    return Deployment::factory()->for($app)->create([
        'status' => DeploymentStatus::Failed,
        'started_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinute(),
        'release' => CLEANUP_RELEASE,
        ...$deployment,
    ]);
}

function cleanupTransport(string $current = '/var/coresmith/apps/shop/releases/20261019-090000'): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo(CLEANUP_READLINK, $current . "\n");
}

function cleanupDiscard(Deployment $deployment, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DiscardRelease($deployment->fresh()))->handle(app(SSHConnectionManager::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('a half-built release is removed once current is known not to point at it', function (string $readlink, int $exitCode) {
    $deployment = cleanupDeployment();
    $transport = (new FakeSSHTransport)->respondTo(CLEANUP_READLINK, $readlink, $exitCode);

    cleanupDiscard($deployment, $transport);

    expect($transport->commands())->toBe([CLEANUP_READLINK, CLEANUP_REMOVAL])
        ->and($transport->connected())->toBeFalse();
})->with([
    'current is an older release' => ["/var/coresmith/apps/shop/releases/20261019-090000\n", 0],
    'there is no current yet' => ['', 1],
]);

test('a release current points at is never removed, whatever the app records as live', function (?string $recorded, string $target) {
    $deployment = cleanupDeployment(application: ['current_release' => $recorded]);
    $transport = cleanupTransport($target);

    cleanupDiscard($deployment, $transport);

    expect($transport->commands())->toBe([CLEANUP_READLINK])
        ->and($transport->connected())->toBeFalse();
})->with([
    'the worker died between the swap and the save' => ['20261019-090000', '/var/coresmith/apps/shop/releases/' . CLEANUP_RELEASE],
    'a relative link' => ['20261019-090000', 'releases/' . CLEANUP_RELEASE],
    'a link with a trailing slash' => ['20261019-090000', '/var/coresmith/apps/shop/releases/' . CLEANUP_RELEASE . '/'],
    'no live release recorded yet' => [null, '/var/coresmith/apps/shop/releases/' . CLEANUP_RELEASE],
]);

test('a release the app records as live is left alone without connecting at all', function () {
    $deployment = cleanupDeployment(application: ['current_release' => CLEANUP_RELEASE]);
    $transport = new FakeSSHTransport;

    cleanupDiscard($deployment, $transport);

    expect($transport->calls())->toBe([])
        ->and($transport->commands())->toBe([]);
});

test('a deployment with no release name, or one that is not a release name, removes nothing', function (?string $release) {
    $deployment = cleanupDeployment(['release' => $release]);
    $transport = new FakeSSHTransport;

    cleanupDiscard($deployment, $transport);

    expect($transport->calls())->toBe([]);
})->with([
    'none' => [null],
    'empty' => [''],
    'a traversal' => ['../../shared'],
    'a word' => ['latest'],
    'an injection' => [CLEANUP_RELEASE . '; rm -rf /'],
    'a trailing newline' => [CLEANUP_RELEASE . "\n"],
]);

test('the copy of the live data a reclaimed deploy made stays, because a newer deploy may already use it', function () {
    $deployment = cleanupDeployment(['seeded_shared_storage' => true]);
    $transport = cleanupTransport();

    cleanupDiscard($deployment, $transport);

    expect($transport->commands())->toContain(CLEANUP_REMOVAL)
        ->and($transport->commands())->not->toContain("rm -rf '/var/coresmith/apps/shop/shared/storage'")
        ->and($deployment->fresh()->seeded_shared_storage)->toBeTrue();
});

test('an app removed in the meantime still has its half-built release cleaned up', function () {
    $deployment = cleanupDeployment();
    $deployment->application->delete();
    $transport = cleanupTransport();

    cleanupDiscard($deployment, $transport);

    expect($transport->commands())->toBe([CLEANUP_READLINK, CLEANUP_REMOVAL]);
});

test('a detached server has nothing to clean up', function () {
    $deployment = cleanupDeployment();
    $deployment->application->server->delete();
    $transport = new FakeSSHTransport;

    cleanupDiscard($deployment, $transport);

    expect($transport->calls())->toBe([]);
});

test('a release that cannot be removed fails the job so the queue tries again, and the connection is let go', function () {
    $deployment = cleanupDeployment();
    $transport = cleanupTransport()->respondTo('rm -rf', "rm: cannot remove: Device or resource busy\n", 1);

    expect(fn() => cleanupDiscard($deployment, $transport))->toThrow(SSHCommandFailedException::class)
        ->and($transport->connected())->toBeFalse();
});

test('the removal is a short server task that is tried twice', function () {
    $job = new DiscardRelease(cleanupDeployment());

    expect($job->queue)->toBe('server-tasks')
        ->and($job->tries)->toBe(2)
        ->and($job->timeout)->toBe(120);
});

test('a release that could not be removed on either try is named in the deploy log, once', function () {
    $deployment = cleanupDeployment(['log' => '[12:00:00] release ' . CLEANUP_RELEASE]);

    (new DiscardRelease($deployment))->failed(new RuntimeException('boom'));

    $log = (string) $deployment->fresh()->log;

    expect(substr_count($log, CLEANUP_LEFTOVER))->toBe(1)
        ->and($log)->toBe('[12:00:00] release ' . CLEANUP_RELEASE . "\n" . CLEANUP_LEFTOVER);
});

test('a removal that keeps failing leaves the note when the queue gives the job up', function () {
    $deployment = cleanupDeployment();
    $transport = cleanupTransport()->respondTo('rm -rf', "rm: cannot remove: Device or resource busy\n", 1);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        expect(fn() => DiscardRelease::dispatchSync($deployment->fresh()))->toThrow(SSHCommandFailedException::class);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect(substr_count((string) $deployment->fresh()->log, CLEANUP_LEFTOVER))->toBe(1);
});

test('a removal that works leaves the deploy log alone', function () {
    $deployment = cleanupDeployment(['log' => 'first']);

    cleanupDiscard($deployment, cleanupTransport());

    expect($deployment->fresh()->log)->toBe('first');
});

test('the note joins the log as it stands now, not as the job was queued with it', function () {
    $deployment = cleanupDeployment(['log' => 'first']);
    $job = new DiscardRelease($deployment);

    Deployment::query()->whereKey($deployment->getKey())->update(['log' => "first\nsecond"]);

    $job->failed(new RuntimeException('boom'));

    expect($deployment->fresh()->log)->toBe("first\nsecond\n" . CLEANUP_LEFTOVER);
});

test('nothing is said about a release the job never tried to remove', function (?string $release) {
    $deployment = cleanupDeployment(['release' => $release, 'log' => 'first']);

    (new DiscardRelease($deployment))->failed(new RuntimeException('boom'));

    expect($deployment->fresh()->log)->toBe('first');
})->with([
    'none' => [null],
    'empty' => [''],
    'a traversal' => ['../../shared'],
    'a trailing newline' => [CLEANUP_RELEASE . "\n"],
]);

test('a deployment that is gone has nothing to note', function () {
    $deployment = cleanupDeployment();
    $job = new DiscardRelease($deployment);

    Deployment::query()->whereKey($deployment->getKey())->delete();

    $job->failed(new RuntimeException('boom'));

    expect(Deployment::query()->whereKey($deployment->getKey())->exists())->toBeFalse();
});

test('a note that cannot be saved is reported, never thrown', function () {
    Exceptions::fake();
    $deployment = cleanupDeployment(['log' => 'first']);

    Deployment::saving(function (Deployment $saving): void {
        if (str_contains((string) $saving->log, 'Could not remove')) {
            throw new RuntimeException('The database went away.');
        }
    });

    (new DiscardRelease($deployment))->failed(new RuntimeException('boom'));

    expect($deployment->fresh()->log)->toBe('first');

    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'The database went away.');
});
