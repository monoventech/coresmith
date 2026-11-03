<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Exceptions\UnsafeNameException;
use App\Jobs\PruneReleases;
use App\Jobs\PruneRemovedApplications;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\RemovedApplicationFolders;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function removedAppsRun(Server $server, string $job = PruneRemovedApplications::class): FakeSSHTransport
{
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new $job($server), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    return $transport;
}

function removedAppsApp(Server $server, string $slug, ?int $daysAgo): Application
{
    $application = Application::factory()->for($server)->create(['slug' => $slug, 'team_id' => $server->team_id]);

    if ($daysAgo !== null) {
        $application->forceFill(['deleted_at' => now()->subDays($daysAgo)->subMinute()])->saveQuietly();
    }

    return $application;
}

function removedAppsRemoves(FakeSSHTransport $transport, string $slug): bool
{
    return collect($transport->commands())->contains(fn(string $command): bool => str_contains($command, "rm -rf '/var/coresmith/apps/{$slug}'"));
}

beforeEach(fn() => $this->freezeSecond());

test('an app removed more than 7 days ago has its folder removed, once', function () {
    $server = Server::factory()->create();
    $application = removedAppsApp($server, 'old-app', 7);

    $transport = removedAppsRun($server);

    expect(removedAppsRemoves($transport, 'old-app'))->toBeTrue()
        ->and($application->fresh()->folder_removed_at)->not->toBeNull();

    expect(removedAppsRun($server)->commands())->toBe([]);
});

test('an app removed exactly 7 days ago keeps its folder', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->for($server)->create(['slug' => 'recent-app', 'team_id' => $server->team_id]);
    $application->forceFill(['deleted_at' => now()->subDays(7)])->saveQuietly();

    expect(removedAppsRun($server)->commands())->toBe([]);
});

test('a live app with the same slug keeps the folder it uses', function () {
    $removed = (new Application(['slug' => 'shared-slug']))->forceFill(['deleted_at' => now()->subDays(30)]);
    $live = new Application(['slug' => 'shared-slug']);

    expect(app(RemovedApplicationFolders::class)->pick(collect([$removed, $live])))->toBeEmpty();
});

test('a more recently removed app with the same slug keeps the folder until its own 7 days pass', function () {
    $older = (new Application(['slug' => 'shared-slug']))->forceFill(['deleted_at' => now()->subDays(30)]);
    $newer = (new Application(['slug' => 'shared-slug']))->forceFill(['deleted_at' => now()->subDays(2)]);

    expect(app(RemovedApplicationFolders::class)->pick(collect([$older, $newer])))->toBeEmpty();
});

test('a removed app with no one else on its slug is picked', function () {
    $removed = (new Application(['slug' => 'old-app']))->forceFill(['deleted_at' => now()->subDays(30)]);
    $other = new Application(['slug' => 'live-app']);

    expect(app(RemovedApplicationFolders::class)->pick(collect([$removed, $other]))->pluck('slug')->all())->toBe(['old-app']);
});

test('a removed app\'s slug stays taken, so a new app never shares its folder', function () {
    $server = Server::factory()->create();
    removedAppsApp($server, 'old-app', 30);

    expect(fn() => Application::factory()->for($server)->create(['slug' => 'old-app', 'team_id' => $server->team_id]))
        ->toThrow(QueryException::class);
});

test('an app on another server is left alone, and that server keeps its folder', function () {
    $server = Server::factory()->create();
    $other = Server::factory()->create();
    removedAppsApp($other, 'elsewhere', 30);
    removedAppsApp($server, 'mine', 30);

    $transport = removedAppsRun($server);

    expect(removedAppsRemoves($transport, 'mine'))->toBeTrue()
        ->and(removedAppsRemoves($transport, 'elsewhere'))->toBeFalse();
});

test('a slug that is not safe is reported and never reaches a command, and the next app is still cleared', function () {
    Exceptions::fake();
    $server = Server::factory()->create();
    $unsafe = removedAppsApp($server, 'fine', 30);
    $unsafe->forceFill(['slug' => '../etc'])->saveQuietly();
    removedAppsApp($server, 'also-fine', 30);

    $transport = removedAppsRun($server);

    expect(removedAppsRemoves($transport, 'also-fine'))->toBeTrue()
        ->and(implode("\n", $transport->commands()))->not->toContain('etc')
        ->and($unsafe->fresh()->folder_removed_at)->toBeNull();

    Exceptions::assertReported(UnsafeNameException::class);
});

test('clearing folders is recorded and described in the feed', function () {
    $server = Server::factory()->create();
    removedAppsApp($server, 'old-app', 30);

    removedAppsRun($server);

    $activity = Activity::query()->where('description', 'removed_apps_cleared')->sole();

    expect($activity->subject?->is($server))->toBeTrue()
        ->and($activity->properties['apps'])->toBe(1);
});

test('nothing due asks the server nothing and records nothing', function () {
    $server = Server::factory()->create();
    removedAppsApp($server, 'recent', 2);

    expect(removedAppsRun($server)->commands())->toBe([])
        ->and(Activity::query()->where('description', 'removed_apps_cleared')->count())->toBe(0);
});

test('prune old releases clears removed apps too', function () {
    $server = Server::factory()->create();
    removedAppsApp($server, 'old-app', 30);
    removedAppsApp($server, 'live-app', null);

    $transport = removedAppsRun($server, PruneReleases::class);

    expect(removedAppsRemoves($transport, 'old-app'))->toBeTrue()
        ->and(removedAppsRemoves($transport, 'live-app'))->toBeFalse();
});

test('prune old releases with nothing to clear records nothing about removed apps', function () {
    $server = Server::factory()->create();
    removedAppsApp($server, 'live-app', null);

    removedAppsRun($server, PruneReleases::class);

    expect(Activity::query()->where('description', 'removed_apps_cleared')->count())->toBe(0);
});

test('the daily schedule queues only reachable servers with something due', function () {
    Queue::fake();
    $due = Server::factory()->create(['status' => ServerStatus::Active]);
    removedAppsApp($due, 'old-app', 30);
    $unreachable = Server::factory()->create(['status' => ServerStatus::Active, 'unreachable_since' => now()]);
    removedAppsApp($unreachable, 'old-app-2', 30);
    $blocked = Server::factory()->create(['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:changed']);
    removedAppsApp($blocked, 'old-app-3', 30);
    $nothing = Server::factory()->create(['status' => ServerStatus::Active]);
    removedAppsApp($nothing, 'recent', 2);

    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:prune-removed-apps');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('45 3 * * *');

    $event->run(app());

    Queue::assertPushed(PruneRemovedApplications::class, 1);
    Queue::assertPushed(PruneRemovedApplications::class, fn(PruneRemovedApplications $job): bool => $job->server->is($due));
});

test('the job runs on the default queue', function () {
    expect((new PruneRemovedApplications(new Server))->queue)->toBe('default');
});
