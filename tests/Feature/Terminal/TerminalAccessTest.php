<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

function terminalAccessUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('owners and developers may open a terminal as an app, and only owners as root', function (TeamRole $role, bool $app, bool $root) {
    $team = Team::factory()->create();
    $user = terminalAccessUser($team, $role);
    $server = Server::factory()->for($team)->create(['created_by' => $user->id]);

    expect($user->can('openTerminal', $server))->toBe($app)
        ->and($user->can('openRootTerminal', $server))->toBe($root);
})->with([
    'owner' => [TeamRole::Owner, true, true],
    'admin' => [TeamRole::Admin, false, false],
    'developer' => [TeamRole::Developer, true, false],
    'member' => [TeamRole::Member, false, false],
]);

test('a developer opens a terminal only on servers they added', function () {
    $team = Team::factory()->create();
    $owner = terminalAccessUser($team, TeamRole::Owner);
    $developer = terminalAccessUser($team, TeamRole::Developer);
    $theirs = Server::factory()->for($team)->create(['created_by' => $owner->id]);
    $own = Server::factory()->for($team)->create(['created_by' => $developer->id]);

    expect($developer->can('openTerminal', $theirs))->toBeFalse()
        ->and($developer->can('openTerminal', $own))->toBeTrue();
});

test('with Root terminals off nobody opens a root shell, the owner included, and app shells still open', function () {
    $team = Team::factory()->create(['root_terminals' => false]);
    $owner = terminalAccessUser($team, TeamRole::Owner);
    $server = Server::factory()->for($team)->create(['created_by' => $owner->id]);

    expect($owner->can('openRootTerminal', $server))->toBeFalse()
        ->and($owner->can('openTerminal', $server))->toBeTrue();
});

test('teams allow root terminals until their owner turns them off', function () {
    expect(Team::factory()->create()->fresh()->root_terminals)->toBeTrue();
});

test('nobody opens a terminal on another team\'s server', function () {
    $server = Server::factory()->for(Team::factory()->create())->create();
    $outsider = terminalAccessUser(Team::factory()->create(), TeamRole::Owner);

    expect($outsider->can('openTerminal', $server))->toBeFalse()
        ->and($outsider->can('openRootTerminal', $server))->toBeFalse();
});
