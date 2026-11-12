<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\TeamRole;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Teams\TeamAccess;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function databaseDeletionSetup(TeamRole $role = TeamRole::Admin, DatabaseType $type = DatabaseType::Mysql): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'forge-07', 'host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create([
        'type' => $type,
        'name' => 'shop_production',
        'db_user' => 'shop_app',
        'port' => match ($type) {
            DatabaseType::Redis => 6379,
            DatabaseType::Postgresql => 5432,
            default => 3306,
        },
    ]);
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return [$server, $database, $user->fresh()];
}

function databaseDeletionTransport(?FakeSSHTransport $transport = null): FakeSSHTransport
{
    $transport ??= new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return $transport;
}

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('deleting a MySQL database drops it and its user from the server, then removes it', function () {
    $transport = databaseDeletionTransport();
    [$server, $database, $admin] = databaseDeletionSetup();

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
        ->assertRedirect(route('servers.databases.index', $server))
        ->assertSessionHas('success', 'Deleted shop_production and its user from forge-07.');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('SET SESSION lock_wait_timeout = 30; DROP DATABASE IF EXISTS `shop_production`;')
        ->and($commands)->toContain("DROP USER IF EXISTS '\\''shop_app'\\''@'\\''localhost'\\'';")
        ->and($database->fresh()->trashed())->toBeTrue()
        ->and(Activity::query()->where('description', 'database_deleted')->sole()->subject_id)->toBe($database->id);
});

test('deleting a PostgreSQL database ends its sessions first, so the drop is not refused', function () {
    $transport = databaseDeletionTransport();
    [$server, $database, $admin] = databaseDeletionSetup(type: DatabaseType::Postgresql);

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
        ->assertSessionHas('success');

    $commands = $transport->commands();
    $find = fn(string $needle): int|false => array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, $needle))) ?? false;

    expect($find('pg_terminate_backend'))->toBeInt()
        ->and($find('pg_terminate_backend'))->toBeLessThan($find('DROP DATABASE IF EXISTS "shop_production"'))
        ->and($find('DROP DATABASE IF EXISTS "shop_production"'))->toBeLessThan($find('DROP ROLE IF EXISTS "shop_app"'))
        ->and($database->fresh()->trashed())->toBeTrue();
});

test('a Redis database on the shared instance has nothing of its own to drop, so only Coresmith forgets it', function () {
    $transport = databaseDeletionTransport();
    [$server, $database, $admin] = databaseDeletionSetup(type: DatabaseType::Redis);

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
        ->assertSessionHas('success', "Removed shop_production. The shared Redis on forge-07 keeps what's in it.");

    expect($transport->calls())->toBe([])
        ->and($database->fresh()->trashed())->toBeTrue();
});

test('deleting a Redis database on its own instance stops and removes the instance', function () {
    $transport = databaseDeletionTransport();
    [$server, $database, $admin] = databaseDeletionSetup(type: DatabaseType::Redis);
    $database->forceFill(['port' => 6380])->save();

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
        ->assertSessionHas('success', 'Deleted shop_production and its Redis instance from forge-07.');

    expect($transport->commands())->toContain('systemctl disable --now coresmith-redis-6380.service || true')
        ->and($database->fresh()->trashed())->toBeTrue();
});

test('the name has to be typed exactly, or nothing happens', function (?string $typed) {
    $transport = databaseDeletionTransport();
    [$server, $database, $admin] = databaseDeletionSetup();

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$server, $database]), $typed === null ? [] : ['confirm' => $typed])
        ->assertSessionHasErrors(['confirm' => 'Type shop_production exactly to confirm.']);

    expect($transport->calls())->toBe([])
        ->and($database->fresh()->trashed())->toBeFalse();
})->with(['missing' => [null], 'different' => ['shop_staging'], 'other case' => ['SHOP_PRODUCTION']]);

test('a drop the server refuses keeps the database and says it is still there', function () {
    databaseDeletionTransport((new FakeSSHTransport)->respondTo('DROP DATABASE', 'ERROR 1205 (HY000): Lock wait timeout exceeded', 1));
    [$server, $database, $admin] = databaseDeletionSetup();

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
        ->assertSessionHas('error', "Coresmith couldn't delete shop_production from forge-07, so it's still there. Check the server is online and nothing is using the database, then try again.");

    expect($database->fresh()->trashed())->toBeFalse()
        ->and(Activity::query()->where('description', 'database_deleted')->count())->toBe(0);
});

test('a drop on a server Coresmith cannot reach keeps the database', function () {
    databaseDeletionTransport((new FakeSSHTransport)->refuseConnections());
    [$server, $database, $admin] = databaseDeletionSetup();

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
        ->assertSessionHas('error');

    expect($database->fresh()->trashed())->toBeFalse();
});

test('a deletion while Coresmith is still working on the database says to try again', function () {
    $transport = databaseDeletionTransport();
    [$server, $database, $admin] = databaseDeletionSetup();
    $held = $database->credentialsLock();
    $held->get();

    try {
        $this->actingAs($admin)
            ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
            ->assertSessionHas('error', 'Coresmith is still working on this database. Try again in a minute.');
    } finally {
        $held->release();
    }

    expect($transport->calls())->toBe([])
        ->and($database->fresh()->trashed())->toBeFalse();
});

test('a deployer cannot delete a database, and Coresmith never dials the server', function () {
    $transport = databaseDeletionTransport();
    [$server, $database, $member] = databaseDeletionSetup(TeamRole::Member);

    $this->actingAs($member)
        ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
        ->assertForbidden();

    expect($transport->calls())->toBe([])
        ->and($database->fresh()->trashed())->toBeFalse();
});

test('the database page says whether deleting drops it from the server', function (DatabaseType $type, bool $shared) {
    [$server, $database, $admin] = databaseDeletionSetup(type: $type);

    $this->actingAs($admin)
        ->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn($page) => $page->where('database.shared_redis', $shared));
})->with([
    'mysql' => [DatabaseType::Mysql, false],
    'postgres' => [DatabaseType::Postgresql, false],
    'shared redis' => [DatabaseType::Redis, true],
]);

test('a database from another server is not found for deletion', function () {
    [$server, $database, $admin] = databaseDeletionSetup();
    $other = Server::factory()->for($server->team)->create();

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$other, $database]), ['confirm' => 'shop_production'])
        ->assertNotFound();

    expect($database->fresh()->trashed())->toBeFalse();
});

test('deleting a database seen only through its environment goes back to the fleet', function () {
    databaseDeletionTransport();
    [$server, $database, $admin] = databaseDeletionSetup();
    $staging = Project::factory()->for($database->team)->withEnvironments(['Staging'])->create()->environments()->sole();
    $database->update(['environment_id' => $staging->id]);
    $database->team->members()->updateExistingPivot($admin->id, ['sees_everything' => false]);
    app(TeamAccess::class)->grant($database->team, $admin, $staging);

    $this->actingAs($admin)
        ->delete(route('servers.databases.destroy', [$server, $database]), ['confirm' => 'shop_production'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Deleted shop_production and its user from forge-07.');
});
