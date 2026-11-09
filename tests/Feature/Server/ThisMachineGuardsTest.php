<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Database\CoresmithDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

function thisMachineGuardOwner(array $server = []): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return [$user->fresh(), Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'runs_coresmith' => true, 'ip_address' => '127.0.0.1', ...$server])];
}

function thisMachineGuardDatabase(array $connection): void
{
    app()->instance(CoresmithDatabase::class, new CoresmithDatabase($connection));
}

function thisMachineGuardPayload(array $overrides = []): array
{
    return ['type' => 'postgresql', 'name' => 'shop', 'db_user' => 'shop_user', 'privilege_level' => 'standard', ...$overrides];
}

test("on Coresmith's own machine, a database can't take the name or user of the one Coresmith itself uses there", function (array $payload, string $field, string $message) {
    Queue::fake();
    thisMachineGuardDatabase(['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'coresmith', 'username' => 'coresmith']);
    [$owner, $server] = thisMachineGuardOwner();

    $this->actingAs($owner)->post(route('servers.databases.store', $server), thisMachineGuardPayload($payload))->assertSessionHasErrors([$field => $message]);

    expect(ManagedDatabase::query()->count())->toBe(0);
})->with([
    'its name' => [['name' => 'coresmith'], 'name', "That's the database Coresmith itself uses on this machine. Pick another name."],
    'its name in capitals' => [['name' => 'CORESMITH'], 'name', "That's the database Coresmith itself uses on this machine. Pick another name."],
    'its user' => [['db_user' => 'coresmith'], 'db_user', "That's the user Coresmith's own database uses on this machine. Pick another user."],
]);

test('the same names are fine where they cannot collide with Coresmith', function (array $connection, array $server, array $payload) {
    Queue::fake();
    thisMachineGuardDatabase($connection);
    [$owner, $machine] = thisMachineGuardOwner($server);

    $this->actingAs($owner)->post(route('servers.databases.store', $machine), thisMachineGuardPayload($payload))->assertSessionHasNoErrors();
})->with([
    'another server' => [['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'coresmith', 'username' => 'coresmith'], ['runs_coresmith' => false], ['name' => 'coresmith', 'db_user' => 'coresmith']],
    'Coresmith on SQLite' => [['driver' => 'sqlite', 'database' => '/var/lib/coresmith/database.sqlite'], [], ['name' => 'coresmith', 'db_user' => 'coresmith']],
    'Coresmith on another engine' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'coresmith', 'username' => 'coresmith'], [], ['name' => 'coresmith', 'db_user' => 'coresmith']],
    'Coresmith on a database server elsewhere' => [['driver' => 'pgsql', 'host' => 'db.internal', 'database' => 'coresmith', 'username' => 'coresmith'], [], ['name' => 'coresmith', 'db_user' => 'coresmith']],
]);

function thisMachineGuardHelper(?string $refusal): string
{
    $folder = sys_get_temp_dir() . '/coresmith-guard-' . bin2hex(random_bytes(4));
    test()->scratchFolders = [...test()->scratchFolders, $folder];
    File::ensureDirectoryExists($folder);
    config(['coresmith.this_machine.state_path' => $folder]);

    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(function () use ($folder, $refusal): void {
        $request = @file_get_contents("{$folder}/request");

        if ($request === false) {
            return;
        }

        [$id, $what] = explode(' ', trim($request), 2);
        unlink("{$folder}/request");
        file_put_contents("{$folder}/asked", $what);
        file_put_contents("{$folder}/result.json", json_encode($refusal === null ? ['id' => $id, 'ok' => true] : ['id' => $id, 'ok' => false, 'error' => $refusal]));
    });

    return $folder;
}

beforeEach(function () {
    $this->scratchFolders = [];
});

afterEach(function () {
    foreach ($this->scratchFolders as $folder) {
        File::deleteDirectory($folder);
    }
});

test("detaching Coresmith's own machine takes Coresmith's key off it first, and leaves the apps and Coresmith running", function () {
    $folder = thisMachineGuardHelper(null);
    [$owner, $server] = thisMachineGuardOwner();

    $this->actingAs($owner)->delete(route('servers.destroy', $server))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Detached. Coresmith took its key off this machine and stopped managing it. Apps keep running, and so does Coresmith.');

    expect(file_get_contents("{$folder}/asked"))->toBe('remove')
        ->and($server->fresh()->trashed())->toBeTrue();
});

test("a helper that can't take the key off still lets the machine go, and says what to remove by hand", function () {
    thisMachineGuardHelper('sshd refused the new settings, so the old ones are back. Run sshd -t to see why.');
    [$owner, $server] = thisMachineGuardOwner();

    $this->actingAs($owner)->delete(route('servers.destroy', $server))
        ->assertSessionHas('error', "Detached, but Coresmith couldn't take its key off this machine: sshd refused the new settings, so the old ones are back. Run sshd -t to see why. Remove the coresmith-this-machine lines from /root/.ssh/authorized_keys and /etc/ssh/sshd_config, and delete /etc/ssh/sshd_config.d/00-coresmith-local.conf.");

    expect($server->fresh()->trashed())->toBeTrue();
});
