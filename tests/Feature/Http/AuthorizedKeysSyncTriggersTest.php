<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function keySyncJoin(Team $team, TeamRole $role, ?User $user = null): User
{
    $user ??= User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function keySyncServers(Team $team): array
{
    $active = Server::factory()->for($team)->active()->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Provisioning]);
    Server::factory()->for($team)->active()->create()->delete();
    Server::factory()->active()->create();

    return [$active];
}

function keySyncQueued(): array
{
    return Queue::pushed(SyncAuthorizedKeys::class)
        ->map(fn(SyncAuthorizedKeys $job): string => $job->uniqueId())
        ->sort()
        ->values()
        ->all();
}

function keySyncIds(Server ...$servers): array
{
    return collect($servers)->pluck('id')->sort()->values()->all();
}

function keySyncOverSsh(FakeSSHTransport $transport, callable $work): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        $work();
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('adding an ssh key syncs the active servers of every team its owner runs, and none they only deploy to', function () {
    Queue::fake();
    $owned = Team::factory()->create();
    $administered = Team::factory()->create();
    $deployed = Team::factory()->create();
    $user = keySyncJoin($owned, TeamRole::Owner);
    keySyncJoin($administered, TeamRole::Admin, $user);
    keySyncJoin($deployed, TeamRole::Member, $user);
    [$first] = keySyncServers($owned);
    [$second] = keySyncServers($administered);
    keySyncServers($deployed);

    $this->actingAs($user)
        ->post(route('ssh-keys.store'), ['name' => 'Laptop', 'public_key' => 'ssh-ed25519 ' . base64_encode('laptop-key') . ' me@laptop'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Saved. The key can log in to your servers in a moment.');

    expect(keySyncQueued())->toBe(keySyncIds($first, $second));
});

test('removing an ssh key syncs the active servers of every team its owner runs', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = keySyncJoin($team, TeamRole::Admin);
    [$server] = keySyncServers($team);
    $key = SshKey::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('ssh-keys.destroy', $key))
        ->assertRedirect()
        ->assertSessionHas('success', 'Removed.');

    expect(SshKey::find($key->id))->toBeNull()
        ->and(keySyncQueued())->toBe(keySyncIds($server));
});

test('a key change by someone who only deploys, or deleting someone elses key, syncs nothing', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $deployer = keySyncJoin($team, TeamRole::Member);
    keySyncServers($team);
    $theirs = SshKey::factory()->for(keySyncJoin($team, TeamRole::Owner))->create();

    $this->actingAs($deployer)
        ->post(route('ssh-keys.store'), ['name' => 'Laptop', 'public_key' => 'ssh-ed25519 ' . base64_encode('deployer-key') . ' me@laptop'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Saved.');

    $this->actingAs($deployer)->delete(route('ssh-keys.destroy', $theirs))->assertForbidden();

    Queue::assertNothingPushed();
});

test('adding a member syncs the teams active servers', function () {
    Queue::fake();
    $team = Team::factory()->create();
    [$server] = keySyncServers($team);
    $newcomer = User::factory()->create();

    $this->actingAs(keySyncJoin($team, TeamRole::Owner))
        ->post(route('teams.members.store', $team), ['email' => $newcomer->email, 'role' => 'admin'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Member added.');

    expect(keySyncQueued())->toBe(keySyncIds($server));
});

test('changing a role syncs the teams active servers', function () {
    Queue::fake();
    $team = Team::factory()->create();
    [$server] = keySyncServers($team);
    $admin = keySyncJoin($team, TeamRole::Admin);

    $this->actingAs(keySyncJoin($team, TeamRole::Owner))
        ->put(route('teams.members.update', [$team, $admin]), ['role' => 'member'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Role updated.');

    expect(keySyncQueued())->toBe(keySyncIds($server));
});

test('removing a member syncs the teams active servers', function () {
    Queue::fake();
    $team = Team::factory()->create();
    [$server] = keySyncServers($team);
    $admin = keySyncJoin($team, TeamRole::Admin);

    $this->actingAs(keySyncJoin($team, TeamRole::Owner))
        ->delete(route('teams.members.destroy', [$team, $admin]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Member removed.');

    expect(keySyncQueued())->toBe(keySyncIds($server));
});

test('a membership change that is refused syncs nothing', function () {
    Queue::fake();
    $team = Team::factory()->create();
    keySyncServers($team);
    $owner = keySyncJoin($team, TeamRole::Owner);
    $admin = keySyncJoin($team, TeamRole::Admin);
    $deployer = keySyncJoin($team, TeamRole::Member);
    $outsider = User::factory()->create();
    $lastOwner = 'A team must keep at least one owner. Make someone else an owner first.';
    $ownerChange = 'Only an owner can change or remove an owner. Ask an owner of this team to do it.';
    $ownerGrant = 'Only an owner can grant the owner role. Ask an owner of this team to do it.';

    $this->actingAs($admin)->put(route('teams.members.update', [$team, $owner]), ['role' => 'member'])->assertRedirect()->assertSessionHas('error', $ownerChange);
    $this->actingAs($admin)->delete(route('teams.members.destroy', [$team, $owner]))->assertRedirect()->assertSessionHas('error', $ownerChange);
    $this->actingAs($admin)->put(route('teams.members.update', [$team, $deployer]), ['role' => 'owner'])->assertRedirect()->assertSessionHas('error', $ownerGrant);
    $this->actingAs($admin)->post(route('teams.members.store', $team), ['email' => $outsider->email, 'role' => 'owner'])->assertSessionHasErrors(['role' => $ownerGrant]);
    $this->actingAs($admin)->post(route('teams.members.store', $team), ['email' => $deployer->email, 'role' => 'member'])->assertSessionHasErrors(['email' => 'That person is already on this team.']);
    $this->actingAs($owner)->put(route('teams.members.update', [$team, $owner]), ['role' => 'member'])->assertRedirect()->assertSessionHas('error', $lastOwner);
    $this->actingAs($owner)->delete(route('teams.members.destroy', [$team, $owner]))->assertRedirect()->assertSessionHas('error', $lastOwner);
    $this->actingAs($deployer)->put(route('teams.members.update', [$team, $admin]), ['role' => 'member'])->assertForbidden();
    $this->actingAs($deployer)->delete(route('teams.members.destroy', [$team, $admin]))->assertForbidden();
    $this->actingAs($deployer)->post(route('teams.members.store', $team), ['email' => $outsider->email, 'role' => 'member'])->assertForbidden();

    expect($team->members()->count())->toBe(3)
        ->and($team->members()->whereKey($owner->getKey())->first()->pivot->role)->toBe('owner')
        ->and($team->members()->whereKey($admin->getKey())->first()->pivot->role)->toBe('admin')
        ->and($team->members()->whereKey($deployer->getKey())->first()->pivot->role)->toBe('member');

    Queue::assertNothingPushed();
});

test('deleting an account syncs the active servers of every team the user was on', function () {
    Queue::fake();
    $administered = Team::factory()->create();
    $deployed = Team::factory()->create();
    $user = keySyncJoin($administered, TeamRole::Admin);
    keySyncJoin($deployed, TeamRole::Member, $user);
    [$first] = keySyncServers($administered);
    [$second] = keySyncServers($deployed);
    SshKey::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull()
        ->and(SshKey::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(keySyncQueued())->toBe(keySyncIds($first, $second));

    $this->assertGuest();
});

test('an account that still owns a team is kept and syncs nothing', function () {
    Queue::fake();
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    keySyncJoin($team, TeamRole::Owner, $user);
    keySyncServers($team);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect(User::find($user->id))->not->toBeNull();

    Queue::assertNothingPushed();
});

test('removing a key takes it off the server straight away', function () {
    $team = Team::factory()->create();
    $owner = keySyncJoin($team, TeamRole::Owner);
    SshKey::factory()->for($owner)->create(['public_key' => 'ssh-ed25519 AAAAkept owner@desktop']);
    $removed = SshKey::factory()->for($owner)->create(['public_key' => 'ssh-ed25519 AAAAremoved owner@stolen-laptop']);
    Server::factory()->for($team)->active()->create();
    $transport = new FakeSSHTransport;

    keySyncOverSsh($transport, fn() => $this->actingAs($owner)->delete(route('ssh-keys.destroy', $removed))->assertRedirect());

    expect($transport->commands())->toHaveCount(1)
        ->and($transport->commands()[0])->toContain('ssh-ed25519 AAAAkept owner@desktop')
        ->and($transport->commands()[0])->not->toContain('AAAAremoved');
});

test('demoting an admin to deployer takes their key off the teams servers', function () {
    $team = Team::factory()->create();
    $owner = keySyncJoin($team, TeamRole::Owner);
    $admin = keySyncJoin($team, TeamRole::Admin);
    SshKey::factory()->for($owner)->create(['public_key' => 'ssh-ed25519 AAAAowner owner@laptop']);
    SshKey::factory()->for($admin)->create(['public_key' => 'ssh-ed25519 AAAAdemoted admin@laptop']);
    Server::factory()->for($team)->active()->count(2)->create();
    $transport = new FakeSSHTransport;

    keySyncOverSsh($transport, fn() => $this->actingAs($owner)->put(route('teams.members.update', [$team, $admin]), ['role' => 'member'])->assertRedirect());

    expect($transport->commands())->toHaveCount(2)
        ->each(fn($command) => $command->toContain('ssh-ed25519 AAAAowner owner@laptop')->not->toContain('AAAAdemoted'));
});

test('deleting an account takes its keys off the servers of every team it was on', function () {
    $team = Team::factory()->create();
    $owner = keySyncJoin($team, TeamRole::Owner);
    $leaver = keySyncJoin($team, TeamRole::Admin);
    SshKey::factory()->for($owner)->create(['public_key' => 'ssh-ed25519 AAAAowner owner@laptop']);
    SshKey::factory()->for($leaver)->create(['public_key' => 'ssh-ed25519 AAAAleaver leaver@laptop']);
    Server::factory()->for($team)->active()->create();
    $transport = new FakeSSHTransport;

    keySyncOverSsh($transport, fn() => $this->actingAs($leaver)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/'));

    expect(User::find($leaver->id))->toBeNull()
        ->and($transport->commands())->toHaveCount(1)
        ->and($transport->commands()[0])->toContain('ssh-ed25519 AAAAowner owner@laptop')
        ->and($transport->commands()[0])->not->toContain('AAAAleaver');
});
