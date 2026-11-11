<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\BackupDatabase;
use App\Jobs\ListDatabaseBackups;
use App\Jobs\RestoreDatabase;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Database\DatabaseBackups;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Teams\TeamAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function databaseRestoreMember(Team $team, TeamRole $role = TeamRole::Admin, bool $seesEverything = true): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $seesEverything]);

    return $user;
}

function databaseRestoreFixture(): ManagedDatabase
{
    $team = Team::factory()->create(['slug' => 'monoven']);
    $server = Server::factory()->for($team)->create(['name' => 'anvil-01', 'host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'status' => ServerStatus::Active, 'last_connected_at' => now()]);

    return ManagedDatabase::factory()->for($team)->for($server)->create(['type' => DatabaseType::Mysql, 'name' => 'shop', 'db_user' => 'shop_user', 'status' => 'active']);
}

function databaseRestoreListing(string $output = "shop-20261110023000.sql.gz\t2048\n"): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo('find ', $output));
}

function databaseRestorePost(User $user, ManagedDatabase $database, array $payload = [])
{
    return test()->actingAs($user)->post(route('servers.databases.restore', [$database->server, $database]), ['stamp' => '20261110023000', 'name' => 'shop', 'stop' => [], ...$payload]);
}

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 14:00:00', 'UTC'));
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('an admin restores a backup by typing the database name, and the job is queued', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    $app = Application::factory()->for($database->team)->for($database->server)->create(['power_state' => PowerState::Active]);
    $admin = databaseRestoreMember($database->team);
    databaseRestoreListing();

    databaseRestorePost($admin, $database, ['stop' => [$app->id]])
        ->assertRedirect()
        ->assertSessionHas('success', 'Restoring shop from Nov 10, 02:30.');

    Queue::assertPushed(RestoreDatabase::class, fn(RestoreDatabase $job): bool => $job->database->is($database)
        && $job->stamp === '20261110023000'
        && $job->key === null
        && $job->size === 2048
        && $job->stop === [$app->id]
        && $job->by === (string) $admin->getKey());

    expect($database->fresh()->restore)->toMatchArray(['state' => 'running', 'stamp' => '20261110023000', 'step' => 'queued'])
        ->and($database->fresh()->restoring())->toBeTrue();
});

test('the typed name must match', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    databaseRestoreListing();

    databaseRestorePost(databaseRestoreMember($database->team), $database, ['name' => 'shop_old'])
        ->assertSessionHasErrors(['name' => 'Type shop to confirm.']);

    Queue::assertNothingPushed();
});

test('a backup that is gone is refused', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    databaseRestoreListing("shop-20261109000000.sql.gz\t10\n");

    databaseRestorePost(databaseRestoreMember($database->team), $database)
        ->assertSessionHas('error', "That backup isn't there anymore. Pick another one.");

    Queue::assertNothingPushed();
    expect($database->fresh()->restore)->toBeNull();
});

test('only apps on this server can be stopped', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    $elsewhere = Application::factory()->for($database->team)->for(Server::factory()->for($database->team))->create();
    databaseRestoreListing();

    databaseRestorePost(databaseRestoreMember($database->team), $database, ['stop' => [$elsewhere->id]])
        ->assertSessionHasErrors(['stop.0' => 'Only apps on anvil-01 can be stopped.']);

    Queue::assertNothingPushed();
});

test('a second restore while one runs is refused', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    $database->forceFill(['restore' => ['state' => 'running', 'stamp' => '20261109000000', 'step' => 'restoring']])->save();
    databaseRestoreListing();

    databaseRestorePost(databaseRestoreMember($database->team), $database)
        ->assertSessionHas('error', 'shop is already being restored.');

    Queue::assertNothingPushed();
    expect($database->fresh()->restore['stamp'])->toBe('20261109000000');
});

test('members cannot restore', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    databaseRestoreListing();

    databaseRestorePost(databaseRestoreMember($database->team, TeamRole::Member), $database)->assertForbidden();

    Queue::assertNothingPushed();
});

test('a narrowed admin gets 404 for a database they cannot see', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    databaseRestoreListing();

    databaseRestorePost(databaseRestoreMember($database->team, TeamRole::Admin, seesEverything: false), $database)->assertNotFound();

    Queue::assertNothingPushed();
});

test('Back up now waits while a restore runs', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    $database->forceFill(['restore' => ['state' => 'running', 'stamp' => '20261110023000', 'step' => 'restoring']])->save();

    $this->actingAs(databaseRestoreMember($database->team))
        ->post(route('servers.databases.backup', [$database->server, $database]))
        ->assertSessionHas('error', 'Wait for the restore of shop to finish, then back up.');

    Queue::assertNotPushed(BackupDatabase::class);
});

test('the page lists apps on the server, ticking those whose environment names the database', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    $team = $database->team;
    $uses = Application::factory()->for($team)->for($database->server)->create(['name' => 'storefront', 'power_state' => PowerState::Active, 'env_variables' => "APP_NAME=Storefront\nDB_DATABASE=shop\n"]);
    $byUrl = Application::factory()->for($team)->for($database->server)->create(['name' => 'reports', 'power_state' => PowerState::Stopped, 'env_variables' => "DATABASE_URL=\"mysql://shop_user:secret@127.0.0.1:3306/shop\"\n"]);
    $other = Application::factory()->for($team)->for($database->server)->create(['name' => 'blog', 'power_state' => PowerState::Active, 'env_variables' => "DB_DATABASE=shop_old\n"]);
    Application::factory()->for($team)->for(Server::factory()->for($team))->create(['name' => 'elsewhere']);

    $this->actingAs(databaseRestoreMember($team))
        ->get(route('servers.databases.show', [$database->server, $database]))
        ->assertInertia(fn($page) => $page
            ->where('can.restore', true)
            ->where('restore', null)
            ->where('apps', [
                ['id' => $other->id, 'name' => 'blog', 'uses' => false, 'running' => true],
                ['id' => $byUrl->id, 'name' => 'reports', 'uses' => true, 'running' => false],
                ['id' => $uses->id, 'name' => 'storefront', 'uses' => true, 'running' => true],
            ]));
});

test('backups are listed in the background, and the page shows them once they are in', function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    $admin = databaseRestoreMember($database->team);
    databaseRestoreListing("shop-20261110023000.sql.gz\t2048\n");

    $this->actingAs($admin)
        ->get(route('servers.databases.show', [$database->server, $database]))
        ->assertInertia(fn($page) => $page->where('backups', null));

    Queue::assertPushed(ListDatabaseBackups::class, fn(ListDatabaseBackups $job): bool => $job->database->is($database));

    app()->call([new ListDatabaseBackups($database), 'handle']);

    $this->actingAs($admin)
        ->get(route('servers.databases.show', [$database->server, $database]))
        ->assertInertia(fn($page) => $page->where('backups', [
            'rows' => [['stamp' => '20261110023000', 'when' => 'Nov 10, 02:30', 'size' => "2\u{00A0}KB", 'on_server' => true, 'in_s3' => false]],
            'server_error' => null,
            's3_error' => null,
        ]));

    Queue::assertPushed(ListDatabaseBackups::class, 1);
});

test('a backup or a restore lists the backups afresh', function (Closure $work) {
    $database = databaseRestoreFixture();
    databaseRestoreListing("shop-20261110023000.sql.gz\t2048\n");
    app()->call([new ListDatabaseBackups($database), 'handle']);

    expect(app(DatabaseBackups::class)->cached($database))->not->toBeNull();

    $work($database);

    expect(app(DatabaseBackups::class)->cached($database))->toBeNull();
})->with([
    'a backup' => [fn(ManagedDatabase $database) => app()->call([new BackupDatabase($database, '20261111140000'), 'handle'])],
    'a restore' => [fn(ManagedDatabase $database) => app()->call([new RestoreDatabase($database, '20261110023000', null, 2048, [], ''), 'handle'])],
]);

test('listing backups never runs twice at once and fits its supervisor', function () {
    $job = new ListDatabaseBackups(ManagedDatabase::factory()->make(['id' => '01JC0000000000000000000000']));

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('01JC0000000000000000000000')
        ->and($job->queue)->toBe('server-tasks')
        ->and($job->timeout)->toBeLessThan((int) config('horizon.defaults.server-tasks.timeout'));
});

test('a restore that finished today is shown, and an old one is not', function (string $finished, bool $shown) {
    $database = databaseRestoreFixture();
    $database->forceFill(['restore' => ['state' => 'failed', 'stamp' => '20261110023000', 'step' => null, 'error' => 'It broke.', 'finished_at' => CarbonImmutable::parse($finished, 'UTC')->toIso8601String()]])->save();

    $this->actingAs(databaseRestoreMember($database->team))
        ->get(route('servers.databases.show', [$database->server, $database]))
        ->assertInertia(fn($page) => $shown
            ? $page->where('restore', ['state' => 'failed', 'when' => 'Nov 10, 02:30', 'step' => null, 'error' => 'It broke.'])
            : $page->where('restore', null));
})->with([
    'an hour ago' => ['2026-11-11 13:00:00', true],
    'two days ago' => ['2026-11-09 13:00:00', false],
]);

test("a narrowed member can't stop an app they can't see while restoring", function () {
    Queue::fake();
    $database = databaseRestoreFixture();
    $staging = Project::factory()->for($database->team)->withEnvironments(['Staging'])->create()->environments()->sole();
    $database->update(['environment_id' => $staging->id]);
    $seen = Application::factory()->for($database->team)->for($database->server)->create(['name' => 'shop-staging', 'environment_id' => $staging->id]);
    $hidden = Application::factory()->for($database->team)->for($database->server)->create(['name' => 'billing']);
    $admin = databaseRestoreMember($database->team, TeamRole::Admin, seesEverything: false);
    app(TeamAccess::class)->grant($database->team, $admin, $staging);
    databaseRestoreListing();

    databaseRestorePost($admin, $database, ['stop' => [$seen->id, $hidden->id]])
        ->assertSessionHasErrors(['stop.1' => "One of those apps isn't one you can see. Reload the page and choose again."]);

    Queue::assertNothingPushed();
});
