<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\TeamRole;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function databasePasswordSetup(TeamRole $role = TeamRole::Admin, DatabaseType $type = DatabaseType::Mysql): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create([
        'type' => $type,
        'name' => 'shop_production',
        'db_user' => 'shop_app',
        'db_password' => 'Old5ecretPasswordValue',
        'port' => $type === DatabaseType::Redis ? 6379 : 3306,
    ]);
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return [$server, $database, $user->fresh()];
}

function databasePasswordTransport(?FakeSSHTransport $transport = null): FakeSSHTransport
{
    $transport ??= new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return $transport;
}

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('an admin is shown a database password once, and the reveal is recorded', function () {
    [$server, $database, $admin] = databasePasswordSetup();

    $this->actingAs($admin)
        ->from(route('servers.databases.show', [$server, $database]))
        ->post(route('servers.databases.password.show', [$server, $database]))
        ->assertRedirect(route('servers.databases.show', [$server, $database]))
        ->assertSessionHas('databasePassword', 'Old5ecretPasswordValue');

    $activity = Activity::query()->where('description', 'database_password_shown')->sole();

    expect($activity->subject?->is($database))->toBeTrue()
        ->and($activity->causer?->is($admin))->toBeTrue()
        ->and(json_encode($activity->properties))->not->toContain('Old5ecretPasswordValue')
        ->and($database->fresh()->credentials_shown_at)->not->toBeNull();
});

test('a password already shown once is never shown again, only rotated', function () {
    [$server, $database, $admin] = databasePasswordSetup();
    $database->forceFill(['credentials_shown_at' => now()->subDay()])->save();

    $this->actingAs($admin)
        ->post(route('servers.databases.password.show', [$server, $database]))
        ->assertSessionHas('error', 'The password was shown once already. Rotate it to get a new one.')
        ->assertSessionMissing('databasePassword');

    expect(Activity::query()->where('description', 'database_password_shown')->count())->toBe(0);
});

test('a database Coresmith is still creating has no password to show yet', function () {
    [$server, $database, $admin] = databasePasswordSetup();
    $database->forceFill(['db_password' => null])->save();

    $this->actingAs($admin)
        ->post(route('servers.databases.password.show', [$server, $database]))
        ->assertSessionHas('error', 'Coresmith is still creating this database. Try again in a minute.')
        ->assertSessionMissing('databasePassword');

    expect($database->fresh()->credentials_shown_at)->toBeNull();
});

test('a deployer cannot see or rotate a database password, and Coresmith never dials the server', function () {
    $transport = databasePasswordTransport();
    [$server, $database, $member] = databasePasswordSetup(TeamRole::Member);

    $this->actingAs($member)->post(route('servers.databases.password.show', [$server, $database]))
        ->assertForbidden()
        ->assertSessionMissing('databasePassword');
    $this->actingAs($member)->put(route('servers.databases.password.update', [$server, $database]))->assertForbidden();

    expect($transport->calls())->toBe([])
        ->and($database->fresh()->db_password)->toBe('Old5ecretPasswordValue')
        ->and(Activity::query()->where('description', 'like', 'database_password_%')->count())->toBe(0);
});

test('a Redis database on the shared instance has no password to show or rotate', function () {
    $transport = databasePasswordTransport();
    [$server, $database, $admin] = databasePasswordSetup(type: DatabaseType::Redis);

    $this->actingAs($admin)->post(route('servers.databases.password.show', [$server, $database]))->assertNotFound();
    $this->actingAs($admin)->put(route('servers.databases.password.update', [$server, $database]))->assertNotFound();

    expect($transport->calls())->toBe([]);
});

test('a database from another server is not found for its password', function () {
    [$server, $database, $admin] = databasePasswordSetup();
    $other = Server::factory()->for($server->team)->create();

    $this->actingAs($admin)->post(route('servers.databases.password.show', [$other, $database]))->assertNotFound();
    $this->actingAs($admin)->put(route('servers.databases.password.update', [$other, $database]))->assertNotFound();
});

test('rotating sets a new password on the server, seals it, and shows it once', function () {
    $transport = databasePasswordTransport();
    [$server, $database, $admin] = databasePasswordSetup();

    $this->actingAs($admin)
        ->from(route('servers.databases.show', [$server, $database]))
        ->put(route('servers.databases.password.update', [$server, $database]))
        ->assertRedirect(route('servers.databases.show', [$server, $database]))
        ->assertSessionHas('success', 'Password rotated. Give the apps that use it the new one, then deploy.');

    $password = $database->fresh()->db_password;
    $command = Activity::query()->where('description', SSHConnection::COMMAND_ACTIVITY)->sole();

    expect($password)->not->toBe('Old5ecretPasswordValue')->toHaveLength(32)
        ->and(session('databasePassword'))->toBe($password)
        ->and($database->fresh()->credentials_shown_at)->not->toBeNull()
        ->and(implode("\n", $transport->commands()))->toContain("ALTER USER '\\''shop_app'\\''@'\\''localhost'\\'' IDENTIFIED BY '\\''{$password}'\\''")
        ->and(json_encode($command->properties))->not->toContain($password)
        ->and(Activity::query()->where('description', 'database_password_rotated')->sole()->subject?->is($database))->toBeTrue();
});

test('a rotation the server refuses keeps the old password and says so', function () {
    databasePasswordTransport((new FakeSSHTransport)->respondTo('ALTER USER', "ERROR 1396 (HY000): Operation ALTER USER failed for 'shop_app'@'localhost'", 1));
    [$server, $database, $admin] = databasePasswordSetup();

    $this->actingAs($admin)
        ->put(route('servers.databases.password.update', [$server, $database]))
        ->assertSessionHas('error', "Coresmith couldn't change the password on {$server->name}, so the old one still works. Check the server is online, then try again.")
        ->assertSessionMissing('databasePassword');

    expect($database->fresh()->db_password)->toBe('Old5ecretPasswordValue')
        ->and(Activity::query()->where('description', 'database_password_rotated')->count())->toBe(0);
});

test('a rotation on a server Coresmith cannot reach keeps the old password', function () {
    databasePasswordTransport((new FakeSSHTransport)->refuseConnections());
    [$server, $database, $admin] = databasePasswordSetup(type: DatabaseType::Postgresql);

    $this->actingAs($admin)
        ->put(route('servers.databases.password.update', [$server, $database]))
        ->assertSessionHas('error', "Coresmith couldn't change the password on {$server->name}, so the old one still works. Check the server is online, then try again.");

    expect($database->fresh()->db_password)->toBe('Old5ecretPasswordValue');
});

test('the database page says whether the password can be shown, and to whom', function (TeamRole $role, DatabaseType $type, bool $credentials, bool $password) {
    [$server, $database, $user] = databasePasswordSetup($role, $type);

    $this->actingAs($user)
        ->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn($page) => $page
            ->where('can.viewCredentials', $credentials)
            ->where('database.has_password', $password)
            ->where('database.password_shown', false));
})->with([
    'an admin, mysql' => [TeamRole::Admin, DatabaseType::Mysql, true, true],
    'an admin, postgres' => [TeamRole::Admin, DatabaseType::Postgresql, true, true],
    'an admin, shared redis' => [TeamRole::Admin, DatabaseType::Redis, true, false],
    'a deployer, mysql' => [TeamRole::Member, DatabaseType::Mysql, false, true],
]);

test('a rotation while Coresmith is still working on the database says to try again, and changes nothing', function () {
    $transport = databasePasswordTransport();
    [$server, $database, $admin] = databasePasswordSetup();
    $held = $database->credentialsLock();
    $held->get();

    try {
        $this->actingAs($admin)
            ->put(route('servers.databases.password.update', [$server, $database]))
            ->assertSessionHas('error', 'Coresmith is still working on this database. Try again in a minute.')
            ->assertSessionMissing('databasePassword');
    } finally {
        $held->release();
    }

    expect($transport->calls())->toBe([])
        ->and($database->fresh()->db_password)->toBe('Old5ecretPasswordValue');
});

test('a rotation lets go of the database when it is done, even when the server refused it', function () {
    databasePasswordTransport((new FakeSSHTransport)->respondTo('ALTER USER', 'ERROR 1396 (HY000)', 1));
    [$server, $database, $admin] = databasePasswordSetup();

    $this->actingAs($admin)->put(route('servers.databases.password.update', [$server, $database]));

    expect($database->credentialsLock()->get())->toBeTrue();
});

test('the database page says once its password has been shown', function () {
    [$server, $database, $admin] = databasePasswordSetup();
    $database->forceFill(['credentials_shown_at' => now()])->save();

    $this->actingAs($admin)
        ->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn($page) => $page->where('database.password_shown', true));
});
