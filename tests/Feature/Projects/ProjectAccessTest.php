<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

function projectAccessMember(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);

    return $user->fresh();
}

/**
 * @return array{web: Server, db: Server, storefront: Project, shop: Application, shopStaging: Application, blog: Application, shopDb: ManagedDatabase, stagingDb: ManagedDatabase, blogDb: ManagedDatabase}
 */
function projectAccessFleet(Team $team): array
{
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $db = Server::factory()->for($team)->create(['name' => 'db']);
    $storefront = Project::factory()->for($team)->create(['name' => 'Storefront']);

    return [
        'web' => $web,
        'db' => $db,
        'storefront' => $storefront,
        'shop' => Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'project_id' => $storefront->id, 'tags' => ['production']]),
        'shopStaging' => Application::factory()->for($team)->for($db)->create(['name' => 'shop-staging', 'project_id' => $storefront->id, 'tags' => ['staging']]),
        'blog' => Application::factory()->for($team)->for($web)->create(['name' => 'blog']),
        'shopDb' => ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'shop_db', 'project_id' => $storefront->id]),
        'stagingDb' => ManagedDatabase::factory()->for($team)->for($db)->create(['name' => 'staging_db', 'project_id' => $storefront->id]),
        'blogDb' => ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'blog_db']),
    ];
}

/**
 * @return list<string>
 */
function projectAccessNames(HasMany $relation): array
{
    return $relation->orderBy('name')->pluck('name')->all();
}

test('a project share shows every app and database in it, including ones added later', function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $member = projectAccessMember($team);
    $access = app(TeamAccess::class);

    $access->grant($team, $member, $fleet['storefront']);

    expect(projectAccessNames($access->applications($member, $team)))->toBe(['shop', 'shop-staging'])
        ->and(projectAccessNames($access->databases($member, $team)))->toBe(['shop_db', 'staging_db'])
        ->and($access->canSeeProject($member, $fleet['storefront']))->toBeTrue()
        ->and($access->seesWholeProject($member, $fleet['storefront']))->toBeTrue();

    $fleet['blog']->update(['project_id' => $fleet['storefront']->id]);
    app()->forgetScopedInstances();

    $later = app(TeamAccess::class);

    expect(projectAccessNames($later->applications($member, $team)))->toBe(['blog', 'shop', 'shop-staging'])
        ->and($later->canSeeApplication($member, $fleet['blog']->fresh()))->toBeTrue();
});

test('a project share holds back the apps and databases left unticked', function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $member = projectAccessMember($team);
    $access = app(TeamAccess::class);

    $access->sync($team, $member, false, [], [], [$fleet['storefront']->id], [], [$fleet['shop']->id], [$fleet['shopDb']->id]);

    expect(projectAccessNames($access->applications($member, $team)))->toBe(['shop-staging'])
        ->and(projectAccessNames($access->databases($member, $team)))->toBe(['staging_db'])
        ->and($access->canSeeApplication($member, $fleet['shop']))->toBeFalse()
        ->and($access->hidesDatabase($member, $fleet['shopDb']))->toBeTrue()
        ->and($access->canSeeProject($member, $fleet['storefront']))->toBeTrue()
        ->and($access->seesWholeProject($member, $fleet['storefront']))->toBeFalse()
        ->and(projectAccessNames($access->projects($member, $team)))->toBe(['Storefront']);
});

test('a project grant does not reveal the servers its apps run on', function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $member = projectAccessMember($team);
    $access = app(TeamAccess::class);

    $access->grant($team, $member, $fleet['storefront']);

    expect($access->servers($member, $team)->count())->toBe(0)
        ->and($access->canSeeServer($member, $fleet['web']))->toBeFalse()
        ->and($access->hidesServer($member, $fleet['web']))->toBeTrue();
});

test('members who see nothing of a project are kept out of it', function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $member = projectAccessMember($team);
    $access = app(TeamAccess::class);
    $access->grant($team, $member, $fleet['web']);

    expect($access->canSeeProject($member, $fleet['storefront']))->toBeFalse()
        ->and($access->hidesProject($member, $fleet['storefront']))->toBeTrue()
        ->and($access->projects($member, $team)->count())->toBe(0)
        ->and(projectAccessNames($access->databases($member, $team)))->toBe(['blog_db', 'shop_db'])
        ->and($access->hidesProject(User::factory()->create(), $fleet['storefront']))->toBeFalse();
});

test('sync keeps project and database grants and drops ones from another team', function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $member = projectAccessMember($team);
    $elsewhere = Project::factory()->create();
    $theirs = ManagedDatabase::factory()->create();
    $access = app(TeamAccess::class);

    $access->sync($team, $member, false, [], [], [$fleet['storefront']->id, $elsewhere->id], [$fleet['blogDb']->id, $theirs->id]);

    expect($access->summary($team, $member))->toBe([
        'everything' => false,
        'servers' => [],
        'applications' => [],
        'databases' => [$fleet['blogDb']->id],
        'projects' => [$fleet['storefront']->id],
        'withheld_applications' => [],
        'withheld_databases' => [],
    ])->and(projectAccessNames($access->databases($member, $team)))->toBe(['blog_db', 'shop_db', 'staging_db']);
});

test('an owner sees every project in full', function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'sees_everything' => false]);
    $access = app(TeamAccess::class);

    expect(projectAccessNames($access->projects($owner, $team)))->toBe(['Storefront'])
        ->and($access->seesWholeProject($owner, $fleet['storefront']))->toBeTrue()
        ->and(projectAccessNames($access->databases($owner, $team)))->toBe(['blog_db', 'shop_db', 'staging_db']);
});

test('checking many shared projects asks the database once', function () {
    $team = Team::factory()->create();
    $member = projectAccessMember($team);
    $projects = collect(['One', 'Two', 'Three'])->map(fn(string $name): Project => Project::factory()->for($team)->create(['name' => $name]));
    $projects->each(fn(Project $project) => app(TeamAccess::class)->grant($team, $member, $project));
    app()->forgetScopedInstances();
    $access = app(TeamAccess::class);
    $access->canSeeProject($member, $projects->first());
    $projects->each(fn(Project $project) => $project->loadMissing('team'));

    DB::enableQueryLog();
    $seen = $projects->every(fn(Project $project): bool => $access->canSeeProject($member, $project));

    expect($seen)->toBeTrue()->and(DB::getQueryLog())->toBe([]);
});
