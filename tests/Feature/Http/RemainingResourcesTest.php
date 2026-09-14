<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\TeamRole;
use App\Jobs\BackupDatabase;
use App\Jobs\CreateDatabase;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function joined(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function databasePayload(array $overrides = []): array
{
    return array_merge([
        'type' => DatabaseType::Mysql->value,
        'name' => 'shop_production',
        'db_user' => 'shop_user',
        'privilege_level' => 'standard',
    ], $overrides);
}

test('a member cannot create a database', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(joined($team, TeamRole::Member))
        ->post(route('servers.databases.store', $server), databasePayload())
        ->assertForbidden();

    Queue::assertNothingPushed();
    expect(ManagedDatabase::count())->toBe(0);
});

test('an admin creating a database queues the work with the right default port', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(joined($team, TeamRole::Admin))
        ->post(route('servers.databases.store', $server), databasePayload(['type' => 'postgresql']))
        ->assertRedirect();

    expect(ManagedDatabase::first()->port)->toBe(5432);
    Queue::assertPushed(CreateDatabase::class);
});

test('a database name carrying sql metacharacters is rejected at the http layer', function (string $name) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(joined($team, TeamRole::Admin))
        ->post(route('servers.databases.store', $server), databasePayload(['name' => $name]))
        ->assertSessionHasErrors('name');
})->with(['shop`; DROP DATABASE mysql; --', "shop'; --", 'shop-production', 'shop production']);

test('a database user carrying sql metacharacters is rejected', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(joined($team, TeamRole::Admin))
        ->post(route('servers.databases.store', $server), databasePayload(['db_user' => "evil'@'%"]))
        ->assertSessionHasErrors('db_user');
});

test('a member cannot back up or delete a database', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create();
    $member = joined($team, TeamRole::Member);

    $this->actingAs($member)->post(route('servers.databases.backup', [$server, $database]))->assertForbidden();
    $this->actingAs($member)->delete(route('servers.databases.destroy', [$server, $database]))->assertForbidden();

    Queue::assertNothingPushed();
});

test('an admin can back up a database', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create();

    $this->actingAs(joined($team, TeamRole::Admin))
        ->post(route('servers.databases.backup', [$server, $database]))
        ->assertRedirect();

    Queue::assertPushed(BackupDatabase::class);
});

test('a database from another server returns 404', function () {
    $team = Team::factory()->create();
    $serverA = Server::factory()->for($team)->create();
    $serverB = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($serverB)->create();

    $this->actingAs(joined($team, TeamRole::Admin))
        ->get(route('servers.databases.show', [$serverA, $database]))
        ->assertNotFound();
});

test('the database password is never sent to the browser', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['db_password' => 'hunter2-secret']);

    $response = $this->actingAs(joined($team, TeamRole::Admin))
        ->get(route('servers.databases.show', [$server, $database]));

    expect($response->getContent())->not->toContain('hunter2-secret');
});

test('a user can add an ssh key and it is fingerprinted', function () {
    $user = User::factory()->create();
    $key = 'ssh-ed25519 ' . base64_encode('fake-key-material') . ' me@laptop';

    $this->actingAs($user)->post(route('ssh-keys.store'), ['name' => 'Laptop', 'public_key' => $key])->assertRedirect();

    expect($user->sshKeys()->count())->toBe(1)
        ->and($user->sshKeys()->first()->fingerprint)->toStartWith('SHA256:');
});

test('a malformed ssh key is rejected', function (string $key) {
    $this->actingAs(User::factory()->create())
        ->post(route('ssh-keys.store'), ['name' => 'Bad', 'public_key' => $key])
        ->assertSessionHasErrors('public_key');
})->with(['not a key', 'ssh-ed25519', '-----BEGIN OPENSSH PRIVATE KEY-----']);

test('a user cannot delete another users ssh key', function () {
    $mine = User::factory()->create();
    $key = SshKey::factory()->for(User::factory()->create())->create();

    $this->actingAs($mine)->delete(route('ssh-keys.destroy', $key))->assertForbidden();

    expect(SshKey::find($key->id))->not->toBeNull();
});

test('a member cannot manage team members', function () {
    $team = Team::factory()->create();
    $outsiderAccount = User::factory()->create();

    $this->actingAs(joined($team, TeamRole::Member))
        ->post(route('teams.members.store', $team), ['email' => $outsiderAccount->email, 'role' => 'member'])
        ->assertForbidden();
});

test('an admin can add a member', function () {
    $team = Team::factory()->create();
    $newcomer = User::factory()->create();

    $this->actingAs(joined($team, TeamRole::Admin))
        ->post(route('teams.members.store', $team), ['email' => $newcomer->email, 'role' => 'member'])
        ->assertRedirect();

    expect($team->members()->whereKey($newcomer->getKey())->exists())->toBeTrue();
});

test('an admin cannot grant the owner role', function () {
    $team = Team::factory()->create();
    $newcomer = User::factory()->create();

    $this->actingAs(joined($team, TeamRole::Admin))
        ->post(route('teams.members.store', $team), ['email' => $newcomer->email, 'role' => 'owner'])
        ->assertForbidden();

    expect($team->members()->count())->toBe(1);
});

test('an owner can grant the owner role', function () {
    $team = Team::factory()->create();
    $newcomer = User::factory()->create();

    $this->actingAs(joined($team, TeamRole::Owner))
        ->post(route('teams.members.store', $team), ['email' => $newcomer->email, 'role' => 'owner'])
        ->assertRedirect();

    expect($team->members()->whereKey($newcomer->getKey())->first()->pivot->role)->toBe('owner');
});

test('the last owner cannot be demoted', function () {
    $team = Team::factory()->create();
    $owner = joined($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->put(route('teams.members.update', [$team, $owner]), ['role' => 'member'])
        ->assertStatus(422);

    expect($team->members()->whereKey($owner->getKey())->first()->pivot->role)->toBe('owner');
});

test('the last owner cannot be removed', function () {
    $team = Team::factory()->create();
    $owner = joined($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $owner]))
        ->assertStatus(422);

    expect($team->members()->count())->toBe(1);
});

test('an owner can be demoted once another owner exists', function () {
    $team = Team::factory()->create();
    $first = joined($team, TeamRole::Owner);
    $second = joined($team, TeamRole::Owner);

    $this->actingAs($first)
        ->put(route('teams.members.update', [$team, $second]), ['role' => 'admin'])
        ->assertRedirect();

    expect($team->members()->whereKey($second->getKey())->first()->pivot->role)->toBe('admin');
});

test('adding someone twice is refused', function () {
    $team = Team::factory()->create();
    $existing = joined($team, TeamRole::Member);

    $this->actingAs(joined($team, TeamRole::Admin))
        ->post(route('teams.members.store', $team), ['email' => $existing->email, 'role' => 'member'])
        ->assertStatus(422);
});

test('the team page lists members with their roles', function () {
    $team = Team::factory()->create(['name' => 'Monoven']);
    $owner = joined($team, TeamRole::Owner);
    joined($team, TeamRole::Member);

    $this->actingAs($owner)
        ->get(route('teams.show', $team))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('settings/Team')
            ->where('team.name', 'Monoven')
            ->has('members', 2)
            ->where('can.manageMembers', true)
            ->where('can.grantOwner', true));
});

test('an admin sees member management but cannot grant owner', function () {
    $team = Team::factory()->create();

    $this->actingAs(joined($team, TeamRole::Admin))
        ->get(route('teams.show', $team))
        ->assertInertia(fn($page) => $page
            ->where('can.manageMembers', true)
            ->where('can.grantOwner', false));
});

test('a member sees the roster but no management controls', function () {
    $team = Team::factory()->create();

    $this->actingAs(joined($team, TeamRole::Member))
        ->get(route('teams.show', $team))
        ->assertInertia(fn($page) => $page->where('can.manageMembers', false));
});

test('another teams page cannot be opened', function () {
    $outsider = joined(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)
        ->get(route('teams.show', Team::factory()->create()))
        ->assertForbidden();
});

test('the team index resolves the users own team', function () {
    $team = Team::factory()->create(['name' => 'Monoven']);

    $this->actingAs(joined($team, TeamRole::Owner))
        ->get(route('teams.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('team.name', 'Monoven'));
});

test('a user with no team gets a not found rather than an error', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('teams.index'))
        ->assertNotFound();
});
