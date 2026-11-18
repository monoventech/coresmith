<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\ExistingDatabaseServerRefused;
use App\Jobs\CreateDatabase;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\User;
use App\Services\Database\DatabaseCreator;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function creationServer(ServerStatus $status = ServerStatus::Active): Server
{
    return Server::factory()->create(['name' => 'forge-09', 'status' => $status, 'host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
}

function creationMember(Server $server, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create(['current_team_id' => $server->team_id]);
    $server->team->members()->attach($user, ['role' => $role->value, 'sees_everything' => true]);

    return $user->fresh();
}

function creationFailed(Server $server, ?string $error = null): ManagedDatabase
{
    return ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'ledger', 'db_password' => null, 'status' => ManagedDatabase::FAILED, 'creation_error' => $error]);
}

test('a database Coresmith creates is creating until the job gives it a password, then ready', function () {
    Queue::fake();
    $server = creationServer();
    $database = app(DatabaseCreator::class)->create($server, ['type' => DatabaseType::Postgresql, 'name' => 'ledger', 'db_user' => 'ledger', 'privilege_level' => 'standard']);

    expect($database->fresh()->status)->toBe(ManagedDatabase::CREATING);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 3));

    try {
        app()->call([new CreateDatabase($database), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($database->fresh()->status)->toBe(ManagedDatabase::READY)
        ->and($database->fresh()->db_password)->toHaveLength(32);
});

test('a creation that gives up is marked not created, keeping a refusal that says what to change', function (Throwable $exception, ?string $error) {
    $server = creationServer();
    $database = ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'ledger', 'db_password' => null, 'status' => ManagedDatabase::CREATING]);

    (new CreateDatabase($database))->failed($exception);

    expect($database->fresh()->status)->toBe(ManagedDatabase::FAILED)
        ->and($database->fresh()->creation_error)->toBe($error)
        ->and(Activity::query()->where('description', 'database_creation_failed')->sole()->subject_id)->toBe($database->id);
})->with([
    'a refusal' => [new ExistingDatabaseServerRefused('MySQL is already running on forge-09, but Coresmith cannot create databases on it. Let root sign in through the local socket, then try again.'), 'MySQL is already running on forge-09, but Coresmith cannot create databases on it. Let root sign in through the local socket, then try again.'],
    'anything else' => [new RuntimeException('ssh: connect to host 203.0.113.9 port 22: Connection timed out'), null],
]);

test('the database page explains a database Coresmith could not create, and offers to try again', function () {
    $server = creationServer();
    $database = creationFailed($server, 'MySQL is already running on forge-09, but Coresmith cannot create databases on it.');

    $this->actingAs(creationMember($server))->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('database.status', ManagedDatabase::FAILED)
            ->where('database.creation_error', 'MySQL is already running on forge-09, but Coresmith cannot create databases on it.')
            ->where('can.retry', true));
});

test('trying again creates it again, for people who may create databases on a ready server', function () {
    Queue::fake();
    $server = creationServer();
    $database = creationFailed($server, 'Refused.');

    $this->actingAs(creationMember($server, TeamRole::Member))->post(route('servers.databases.retry', [$server, $database]))->assertForbidden();

    $this->actingAs(creationMember($server))
        ->post(route('servers.databases.retry', [$server, $database]))
        ->assertSessionHas('success', 'Creating ledger again.');

    expect($database->fresh()->status)->toBe(ManagedDatabase::CREATING)
        ->and($database->fresh()->creation_error)->toBeNull();
    Queue::assertPushed(CreateDatabase::class, fn(CreateDatabase $job): bool => $job->database->is($database));
});

test('a database that is ready, or a server that is not, has nothing to try again', function () {
    $server = creationServer();
    $ready = ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'ready_db']);
    $offline = creationServer(ServerStatus::Disconnected);
    $stuck = creationFailed($offline);

    $this->actingAs(creationMember($server))->post(route('servers.databases.retry', [$server, $ready]))->assertForbidden();
    $this->actingAs(creationMember($offline))->post(route('servers.databases.retry', [$offline, $stuck]))->assertForbidden();
});

test('the upgrade marks databases that never got a password as not created, and leaves the rest', function () {
    $server = creationServer();
    $stale = ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'stale', 'db_password' => null, 'created_at' => now()->subDay()]);
    $recent = ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'recent', 'db_password' => null, 'created_at' => now()->subMinutes(5)]);
    $shared = ManagedDatabase::factory()->redis()->for($server)->for($server->team)->create(['name' => 'cache', 'port' => 6379, 'db_password' => null, 'created_at' => now()->subDay()]);
    $ready = ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'ready_db', 'created_at' => now()->subDay()]);
    DB::table('databases')->update(['status' => 'active']);

    $migration = require database_path('migrations/2026_11_17_180000_add_creation_error_to_databases.php');
    $migration->down();
    $migration->up();

    expect($stale->fresh()->status)->toBe(ManagedDatabase::FAILED)
        ->and($recent->fresh()->status)->toBe(ManagedDatabase::READY)
        ->and($shared->fresh()->status)->toBe(ManagedDatabase::READY)
        ->and($ready->fresh()->status)->toBe(ManagedDatabase::READY);
});
