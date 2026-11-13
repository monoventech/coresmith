<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Events\FleetChanged;
use App\Exceptions\NoPreviousReleaseException;
use App\Jobs\DeployApplication;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Panel\ActivityFeed;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

const GUARD_LIVE = '20260913-120000';

const GUARD_OLDER = '20260912-090000';

function guardApplication(array $attributes = []): Application
{
    $team = Team::factory()->create();

    return Application::factory()->for($team)->for(Server::factory()->for($team)->create(['name' => 'web-1']))->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'type' => ApplicationType::Php,
        'php_version' => '8.5.5',
        'current_release' => GUARD_LIVE,
        ...$attributes,
    ]);
}

function guardMember(Application $application): User
{
    $user = User::factory()->create();
    $application->team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user->fresh();
}

function guardTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('ls -1dt', '/var/coresmith/apps/shop/releases/' . GUARD_LIVE . "/\n/var/coresmith/apps/shop/releases/" . GUARD_OLDER . "/\n");
}

function guardRollback(Application $application, FakeSSHTransport $transport, ?string $release = GUARD_OLDER): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new RollbackApplication($application, $release))->handle(app(SSHConnectionManager::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function guardLockFree(Application $application): bool
{
    $lock = Cache::lock('deploy:' . $application->getKey(), 10);
    $free = $lock->get();
    $lock->release();

    return $free;
}

function guardPings(): array
{
    return Event::dispatched(FleetChanged::class)
        ->map(fn(array $dispatch): array => [$dispatch[0]->kind, $dispatch[0]->subjectId])
        ->values()
        ->all();
}

test('a rollback is refused while a deploy runs or waits', function (DeploymentStatus $status) {
    Queue::fake();
    $application = guardApplication();
    Deployment::factory()->for($application)->create(['status' => $status]);

    $this->actingAs(guardMember($application))
        ->post(route('servers.applications.rollback', [$application->server_id, $application]))
        ->assertSessionHas('error', 'A deploy is running for Shop. Cancel it or let it finish, then roll back.');

    Queue::assertNotPushed(RollbackApplication::class);
})->with([
    'waiting' => [DeploymentStatus::Pending],
    'running' => [DeploymentStatus::Running],
]);

test('a rollback is queued once every deploy of the app has finished, whatever a neighbour does', function (DeploymentStatus $status) {
    Queue::fake();
    $application = guardApplication();
    Deployment::factory()->for($application)->create(['status' => $status]);
    $neighbour = Application::factory()->for($application->team)->for($application->server)->create(['slug' => 'blog']);
    Deployment::factory()->for($neighbour)->create(['status' => DeploymentStatus::Running]);

    $this->actingAs(guardMember($application))
        ->post(route('servers.applications.rollback', [$application->server_id, $application]))
        ->assertSessionHas('success', 'Rolling back to the previous release. Nothing is rebuilt.');

    Queue::assertPushed(RollbackApplication::class, 1);
})->with([
    'succeeded' => [DeploymentStatus::Succeeded],
    'failed' => [DeploymentStatus::Failed],
    'cancelled' => [DeploymentStatus::Cancelled],
]);

test("a rollback that meets a deploy's lock does nothing and says so", function () {
    Event::fake([FleetChanged::class]);
    $application = guardApplication();
    Cache::lock('deploy:' . $application->getKey(), 600)->get();
    $transport = guardTransport();

    guardRollback($application, $transport);

    $row = collect(app(ActivityFeed::class)->forTeam($application->team))->sole();

    expect($transport->commands())->toBe([])
        ->and($transport->calls())->toBe([])
        ->and($application->fresh()->current_release)->toBe(GUARD_LIVE)
        ->and(Activity::query()->where('description', 'rollback_blocked')->count())->toBe(1)
        ->and($row['parts'])->toBe([
            ['text' => 'Shop', 'mono' => true],
            ['text' => ' wasn\'t rolled back, because a deploy was running. Roll back again once it finishes.', 'mono' => false],
        ])
        ->and($row['href'])->toBe(route('servers.applications.show', [$application->server_id, $application]))
        ->and(guardLockFree($application))->toBeFalse()
        ->and(guardPings())->toBe([['application', $application->id]]);
});

test('a rollback holds the deploy lock while it runs and frees it when it finishes', function () {
    Event::fake([FleetChanged::class]);
    $application = guardApplication();
    $held = null;
    $transport = guardTransport()->onCommand('mv -Tf', function () use ($application, &$held): void {
        $held = !guardLockFree($application);
    });

    guardRollback($application, $transport);

    expect($application->fresh()->current_release)->toBe(GUARD_OLDER)
        ->and($held)->toBeTrue()
        ->and(guardLockFree($application))->toBeTrue()
        ->and(guardPings())->toBe([['application', $application->id]]);
});

test('a rollback frees the lock when it fails', function () {
    Event::fake([FleetChanged::class]);
    $application = guardApplication();

    expect(fn() => guardRollback($application, guardTransport(), '20260101-000000'))->toThrow(NoPreviousReleaseException::class);

    expect(guardLockFree($application))->toBeTrue()
        ->and($application->fresh()->current_release)->toBe(GUARD_LIVE)
        ->and(guardPings())->toBe([['application', $application->id]]);
});

test('the deploy lock a rollback takes lasts five minutes, past the job that holds it', function () {
    expect(RollbackApplication::LOCK_SECONDS)->toBe(300)
        ->and(RollbackApplication::LOCK_SECONDS)->toBeGreaterThan((new RollbackApplication(guardApplication()))->timeout);
});

test('a deploy that meets a rollback says a deploy or a rollback is running, and pings the team', function () {
    Event::fake([FleetChanged::class]);
    $application = guardApplication();
    Cache::lock('deploy:' . $application->getKey(), RollbackApplication::LOCK_SECONDS)->get();
    $deployment = Deployment::factory()->for($application)->create();

    (new DeployApplication($deployment, '20260914-080000'))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->toBe('Another deploy or a rollback is running for this app, so this one did not start.')
        ->and(guardPings())->toBe([['deployment', $deployment->id]]);
});

test("a person's cancel of a queued deploy is kept when its worker then meets a held lock", function () {
    Event::fake([FleetChanged::class]);
    $application = guardApplication();
    Cache::lock('deploy:' . $application->getKey(), RollbackApplication::LOCK_SECONDS)->get();
    $deployment = Deployment::factory()->for($application)->create();
    $cancelled = "cancel: Maya Lindqvist asked to stop the deploy\n[14:05:09] deploy cancelled. the current release is untouched.";

    Deployment::query()->whereKey($deployment->getKey())->update(['status' => DeploymentStatus::Cancelled->value, 'log' => $cancelled]);

    (new DeployApplication($deployment, '20260914-080000'))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->toBe($cancelled)
        ->and(guardPings())->toBe([]);
});

test('a push that met the rollback is deployed once the rollback frees the lock, and the head it rolled back from is not', function (bool $pushedSince) {
    Queue::fake();
    $head = str_repeat('a', 40);
    $newer = str_repeat('b', 40);
    $application = guardApplication(['quick_deploy' => true, 'repository_head' => $pushedSince ? $newer : $head, 'repository_head_source' => 'push']);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => GUARD_LIVE, 'commit_hash' => $head]);
    $lockFree = null;

    Deployment::created(function (Deployment $created) use (&$lockFree): void {
        $lockFree = guardLockFree($created->application);
    });

    guardRollback($application, guardTransport());

    expect($application->fresh()->current_release)->toBe(GUARD_OLDER)
        ->and(Deployment::query()->where('requested_commit', $newer)->count())->toBe($pushedSince ? 1 : 0)
        ->and(Deployment::query()->where('requested_commit', $head)->count())->toBe(0)
        ->and($lockFree)->toBe($pushedSince ? true : null)
        ->and(Queue::pushed(DeployApplication::class))->toHaveCount($pushedSince ? 1 : 0);
})->with([
    'a push landed meanwhile' => [true],
    'nothing new was pushed' => [false],
]);
