<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use App\Services\Teams\TeamInvitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function developerRoleTable(): array
{
    return [
        'view the team' => [Ability::ViewTeam, true],
        'update the team' => [Ability::UpdateTeam, false],
        'delete the team' => [Ability::DeleteTeam, false],
        'transfer ownership' => [Ability::TransferOwnership, false],
        'manage billing' => [Ability::ManageBilling, false],
        'manage members' => [Ability::ManageMembers, false],
        'view a server' => [Ability::ViewServer, true],
        'add a server' => [Ability::CreateServer, true],
        'add a server on a cloud account' => [Ability::CreateCloudServer, true],
        'update a server' => [Ability::UpdateServer, true],
        'delete a server' => [Ability::DeleteServer, true],
        'read server credentials' => [Ability::ViewServerCredentials, false],
        'view an app' => [Ability::ViewApplication, true],
        'add an app' => [Ability::CreateApplication, true],
        'update an app' => [Ability::UpdateApplication, true],
        'delete an app' => [Ability::DeleteApplication, true],
        'deploy an app' => [Ability::DeployApplication, true],
        'read app logs' => [Ability::ViewApplicationLogs, true],
        'manage app environment' => [Ability::ManageApplicationEnv, true],
        'view a database' => [Ability::ViewDatabase, true],
        'add a database' => [Ability::CreateDatabase, true],
        'delete a database' => [Ability::DeleteDatabase, true],
        'back up a database' => [Ability::BackupDatabase, true],
        'restore a database' => [Ability::RestoreDatabase, true],
        'read database credentials' => [Ability::ViewDatabaseCredentials, true],
        'manage cloud accounts' => [Ability::ManageProviders, false],
        'manage a firewall' => [Ability::ManageFirewall, true],
        'manage projects' => [Ability::ManageProjects, true],
    ];
}

test('a developer can do what its row of the table gives it and nothing else', function (Ability $ability, bool $granted) {
    expect(TeamRole::Developer->can($ability))->toBe($granted);
})->with(developerRoleTable());

test('the developer table names every ability there is', function () {
    expect(collect(developerRoleTable())->map(fn(array $cell): string => $cell[0]->value)->sort()->values()->all())
        ->toBe(collect(Ability::cases())->map(fn(Ability $ability): string => $ability->value)->sort()->values()->all());
});

test('the developer role is stored as developer and reads Developer', function () {
    expect(TeamRole::Developer->value)->toBe('developer')
        ->and(TeamRole::Developer->label())->toBe('Developer');
});

/**
 * @return array{Team, User}
 */
function developerRoleTeam(): array
{
    $team = Team::factory()->create(['name' => 'Acme']);

    return [$team, developerRolePerson($team, TeamRole::Owner, true)];
}

function developerRolePerson(Team $team, TeamRole $role, bool $everything): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

/**
 * @return array{role: string, everything: bool}
 */
function developerRoleRow(Team $team, string $email): array
{
    $row = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')->where('team_id', $team->getKey())->where('users.email', $email)->first(['role', 'sees_everything']);

    return ['role' => (string) $row?->role, 'everything' => (bool) $row?->sees_everything];
}

test('a developer never sees everything, whatever its membership row says', function () {
    [$team] = developerRoleTeam();

    expect(app(TeamAccess::class)->seesEverything(developerRolePerson($team, TeamRole::Developer, true), $team))->toBeFalse();
});

test('a developer added straight away sees only what they add and what is shared, even when everything was asked for', function () {
    Notification::fake();
    [$team, $owner] = developerRoleTeam();
    $joiner = User::factory()->create(['email' => 'sam@example.com']);

    $this->actingAs($owner)->from(route('teams.index'))->post(route('teams.members.store', $team), ['email' => 'sam@example.com', 'role' => 'developer', 'access' => 'everything'])->assertSessionHasNoErrors();

    expect(developerRoleRow($team, $joiner->email))->toBe(['role' => 'developer', 'everything' => false]);
});

test('an invitation to a developer is narrowed even when it asks for everything', function () {
    [$team, $owner] = developerRoleTeam();

    [$invitation] = app(TeamInvitations::class)->invite($team, 'rowan@example.com', TeamRole::Developer, $owner, true);

    expect($invitation->fresh()->sees_everything)->toBeFalse();
});

test('a full admin changed to developer stops seeing everything', function () {
    [$team, $owner] = developerRoleTeam();
    $admin = developerRolePerson($team, TeamRole::Admin, true);

    $this->actingAs($owner)->from(route('teams.index'))->put(route('teams.members.update', [$team, $admin]), ['role' => 'developer'])->assertSessionHas('success');

    expect(developerRoleRow($team, $admin->email))->toBe(['role' => 'developer', 'everything' => false])
        ->and(app(TeamAccess::class)->seesEverything($admin->fresh(), $team))->toBeFalse();
});

test('the access editor cannot give a developer everything', function () {
    [$team, $owner] = developerRoleTeam();
    $developer = developerRolePerson($team, TeamRole::Developer, false);

    $this->actingAs($owner)->from(route('teams.index'))->put(route('teams.members.access.update', [$team, $developer]), ['everything' => true])
        ->assertSessionHasErrors(['everything' => 'A developer sees what they add and what you share with them, never everything.']);

    expect(developerRoleRow($team, $developer->email)['everything'])->toBeFalse();
});
