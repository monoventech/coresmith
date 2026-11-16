<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;

function projectSharingPerson(Team $team, TeamRole $role, bool $everything = false): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

/**
 * @return array{team: Team, owner: User, web: Server, storefront: Project, shop: Application, admin: Application, ledger: ManagedDatabase}
 */
function projectSharingTeam(): array
{
    $team = Team::factory()->create(['name' => 'Acme']);
    $owner = projectSharingPerson($team, TeamRole::Owner);
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $storefront = Project::factory()->for($team)->create(['name' => 'Storefront']);

    return [
        'team' => $team,
        'owner' => $owner,
        'web' => $web,
        'storefront' => $storefront,
        'shop' => Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'project_id' => $storefront->id]),
        'admin' => Application::factory()->for($team)->for($web)->create(['name' => 'admin', 'project_id' => $storefront->id]),
        'ledger' => ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'ledger', 'project_id' => $storefront->id]),
    ];
}

function projectSharingVisible(User $user, Team $team): array
{
    $access = new TeamAccess;

    return [
        'applications' => $access->applications($user, $team)->orderBy('name')->pluck('name')->all(),
        'databases' => $access->databases($user, $team)->orderBy('name')->pluck('name')->all(),
    ];
}

test('a project share shows everything in the project, including what is added later', function () {
    $acme = projectSharingTeam();
    $ada = projectSharingPerson($acme['team'], TeamRole::Member);
    (new TeamAccess)->sync($acme['team'], $ada, false, [], [], [$acme['storefront']->id]);
    Application::factory()->for($acme['team'])->for($acme['web'])->create(['name' => 'blog', 'project_id' => $acme['storefront']->id]);

    expect(projectSharingVisible($ada, $acme['team']))->toBe(['applications' => ['admin', 'blog', 'shop'], 'databases' => ['ledger']])
        ->and((new TeamAccess)->canSeeProject($ada, $acme['storefront']))->toBeTrue()
        ->and((new TeamAccess)->seesWholeProject($ada, $acme['storefront']))->toBeTrue();
});

test('a project share holds back the apps and databases left unticked', function () {
    $acme = projectSharingTeam();
    $ada = projectSharingPerson($acme['team'], TeamRole::Member);
    (new TeamAccess)->sync($acme['team'], $ada, false, [], [], [$acme['storefront']->id], [], [$acme['admin']->id], [$acme['ledger']->id]);

    $access = new TeamAccess;

    expect(projectSharingVisible($ada, $acme['team']))->toBe(['applications' => ['shop'], 'databases' => []])
        ->and($access->canSeeApplication($ada, $acme['admin']))->toBeFalse()
        ->and($access->canSeeDatabase($ada, $acme['ledger']))->toBeFalse()
        ->and($access->canSeeProject($ada, $acme['storefront']))->toBeTrue()
        ->and($access->seesWholeProject($ada, $acme['storefront']))->toBeFalse()
        ->and(AccessGrant::query()->where('user_id', $ada->id)->where('withheld', true)->count())->toBe(2);
});

test('a project shared with everything held back shows the project and nothing in it, until the person adds their own', function () {
    $acme = projectSharingTeam();
    $dev = projectSharingPerson($acme['team'], TeamRole::Developer);
    (new TeamAccess)->sync($acme['team'], $dev, false, [], [], [$acme['storefront']->id], [], [$acme['shop']->id, $acme['admin']->id], [$acme['ledger']->id]);

    expect(projectSharingVisible($dev, $acme['team']))->toBe(['applications' => [], 'databases' => []])
        ->and((new TeamAccess)->canSeeProject($dev, $acme['storefront']))->toBeTrue();

    Application::factory()->for($acme['team'])->for($acme['web'])->create(['name' => 'dev-shop', 'project_id' => $acme['storefront']->id, 'created_by' => $dev->id]);

    expect(projectSharingVisible($dev, $acme['team'])['applications'])->toBe(['dev-shop']);
});

test('what a developer adds shows for them and the owner, not for people who see everything or shares', function () {
    $acme = projectSharingTeam();
    $dev = projectSharingPerson($acme['team'], TeamRole::Developer);
    $admin = projectSharingPerson($acme['team'], TeamRole::Admin, true);
    $ada = projectSharingPerson($acme['team'], TeamRole::Member);
    (new TeamAccess)->sync($acme['team'], $ada, false, [$acme['web']->id], [], [$acme['storefront']->id]);
    $side = Application::factory()->for($acme['team'])->for($acme['web'])->create(['name' => 'side', 'project_id' => $acme['storefront']->id, 'created_by' => $dev->id]);
    $notes = ManagedDatabase::factory()->for($acme['team'])->for($acme['web'])->create(['name' => 'notes', 'project_id' => $acme['storefront']->id, 'created_by' => $dev->id]);
    $access = new TeamAccess;

    expect($access->canSeeApplication($dev, $side))->toBeTrue()
        ->and($access->canSeeDatabase($dev, $notes))->toBeTrue()
        ->and($access->canSeeApplication($acme['owner'], $side))->toBeTrue()
        ->and($access->canSeeApplication($admin, $side))->toBeFalse()
        ->and($access->canSeeDatabase($admin, $notes))->toBeFalse()
        ->and($access->canSeeApplication($ada, $side))->toBeFalse()
        ->and($access->canSeeDatabase($ada, $notes))->toBeFalse()
        ->and(projectSharingVisible($admin, $acme['team']))->toBe(['applications' => ['admin', 'shop'], 'databases' => ['ledger']])
        ->and(projectSharingVisible($acme['owner'], $acme['team']))->toBe(['applications' => ['admin', 'shop', 'side'], 'databases' => ['ledger', 'notes']])
        ->and($access->applicationsOn($admin, $acme['web'])->pluck('name')->sort()->values()->all())->toBe(['admin', 'shop'])
        ->and($access->seesAllOf($admin, $acme['team']))->toBeFalse()
        ->and($access->seesAllOf($acme['owner'], $acme['team']))->toBeTrue();
});

test('a developer\'s app shared by a direct grant shows for that person', function () {
    $acme = projectSharingTeam();
    $dev = projectSharingPerson($acme['team'], TeamRole::Developer);
    $admin = projectSharingPerson($acme['team'], TeamRole::Admin, true);
    $side = Application::factory()->for($acme['team'])->for($acme['web'])->create(['name' => 'side', 'created_by' => $dev->id]);
    (new TeamAccess)->grant($acme['team'], $admin, $side);

    expect((new TeamAccess)->canSeeApplication($admin, $side))->toBeTrue()
        ->and(projectSharingVisible($admin, $acme['team'])['applications'])->toContain('side');
});

test('when a developer becomes an admin, what they added follows the ordinary rules', function () {
    $acme = projectSharingTeam();
    $dev = projectSharingPerson($acme['team'], TeamRole::Developer);
    $admin = projectSharingPerson($acme['team'], TeamRole::Admin, true);
    $side = Application::factory()->for($acme['team'])->for($acme['web'])->create(['name' => 'side', 'created_by' => $dev->id]);
    $acme['team']->members()->updateExistingPivot($dev->id, ['role' => TeamRole::Admin->value]);

    expect((new TeamAccess)->canSeeApplication($admin, $side))->toBeTrue()
        ->and((new TeamAccess)->seesAllOf($admin, $acme['team']))->toBeTrue();
});
