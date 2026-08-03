<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;

function memberOf(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('the role matrix matches the spec', function (TeamRole $role, Ability $ability, bool $expected) {
    expect($role->can($ability))->toBe($expected);
})->with([
    [TeamRole::Owner, Ability::DeleteTeam, true],
    [TeamRole::Owner, Ability::TransferOwnership, true],
    [TeamRole::Owner, Ability::ManageBilling, true],
    [TeamRole::Admin, Ability::DeleteTeam, false],
    [TeamRole::Admin, Ability::TransferOwnership, false],
    [TeamRole::Admin, Ability::ManageBilling, false],
    [TeamRole::Admin, Ability::CreateServer, true],
    [TeamRole::Admin, Ability::ManageMembers, true],
    [TeamRole::Member, Ability::CreateServer, false],
    [TeamRole::Member, Ability::DeleteServer, false],
    [TeamRole::Member, Ability::DeleteApplication, false],
    [TeamRole::Member, Ability::DeployApplication, true],
    [TeamRole::Member, Ability::ViewApplicationLogs, true],
    [TeamRole::Member, Ability::ViewServer, true],
    [TeamRole::Member, Ability::ManageApplicationEnv, false],
    [TeamRole::Member, Ability::ViewServerCredentials, false],
]);

test('a member cannot see another teams server', function () {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();
    $user = memberOf($mine, TeamRole::Owner);
    $server = Server::factory()->for($theirs)->create();

    expect($user->can('view', $server))->toBeFalse();
});

test('a member can see their own teams server', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, TeamRole::Member);
    $server = Server::factory()->for($team)->create();

    expect($user->can('view', $server))->toBeTrue();
});

test('a user in no team can do nothing', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create();

    expect($user->can('view', $server))->toBeFalse()
        ->and($user->can('update', $server))->toBeFalse()
        ->and($user->can('delete', $server))->toBeFalse();
});

test('cross team isolation holds for every resource', function (string $model) {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();
    $owner = memberOf($mine, TeamRole::Owner);
    $resource = $model::factory()->for($theirs)->create();

    expect($owner->can('view', $resource))->toBeFalse()
        ->and($owner->can('update', $resource))->toBeFalse()
        ->and($owner->can('delete', $resource))->toBeFalse();
})->with([Server::class, Application::class, ManagedDatabase::class, SourceProvider::class, CloudProvider::class]);

test('a member cannot delete a server but an admin can', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    expect(memberOf($team, TeamRole::Member)->can('delete', $server))->toBeFalse()
        ->and(memberOf($team, TeamRole::Admin)->can('delete', $server))->toBeTrue();
});

test('only an owner can delete the team', function () {
    $team = Team::factory()->create();

    expect(memberOf($team, TeamRole::Owner)->can('delete', $team))->toBeTrue()
        ->and(memberOf($team, TeamRole::Admin)->can('delete', $team))->toBeFalse()
        ->and(memberOf($team, TeamRole::Member)->can('delete', $team))->toBeFalse();
});

test('only an owner can manage billing or transfer ownership', function () {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);

    expect($admin->can('manageBilling', $team))->toBeFalse()
        ->and($admin->can('transferOwnership', $team))->toBeFalse()
        ->and(memberOf($team, TeamRole::Owner)->can('manageBilling', $team))->toBeTrue();
});

test('a member can deploy but cannot read the environment', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->create();
    $member = memberOf($team, TeamRole::Member);

    expect($member->can('deploy', $application))->toBeTrue()
        ->and($member->can('manageEnvironment', $application))->toBeFalse();
});

test('a member cannot read server ssh credentials', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    expect(memberOf($team, TeamRole::Member)->can('viewCredentials', $server))->toBeFalse()
        ->and(memberOf($team, TeamRole::Admin)->can('viewCredentials', $server))->toBeTrue();
});

test('a member cannot read database credentials or restore a backup', function () {
    $team = Team::factory()->create();
    $database = ManagedDatabase::factory()->for($team)->create();
    $member = memberOf($team, TeamRole::Member);

    expect($member->can('viewCredentials', $database))->toBeFalse()
        ->and($member->can('restore', $database))->toBeFalse();
});

test('a deployment inherits the applications team', function () {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();
    $deployment = Deployment::factory()->for(Application::factory()->for($theirs))->create();

    expect(memberOf($mine, TeamRole::Owner)->can('view', $deployment))->toBeFalse()
        ->and(memberOf($theirs, TeamRole::Member)->can('view', $deployment))->toBeTrue();
});

test('an ssh key belongs only to its own user', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $key = SshKey::factory()->for($theirs)->create();

    expect($mine->can('view', $key))->toBeFalse()
        ->and($theirs->fresh()->can('view', $key))->toBeTrue();
});

test('an unknown pivot role grants nothing', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'superadmin']);
    $server = Server::factory()->for($team)->create();

    expect($user->fresh()->can('view', $server))->toBeFalse();
});

test('role resolution returns null outside the team', function () {
    $user = memberOf(Team::factory()->create(), TeamRole::Owner);

    expect($user->roleIn(Team::factory()->create()))->toBeNull()
        ->and($user->belongsToTeam(Team::factory()->create()))->toBeFalse();
});
