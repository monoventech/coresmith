<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\Eloquent\Relations\HasMany;

function projectAccessMember(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);

    return $user->fresh();
}

/**
 * @return array{web: Server, db: Server, storefront: Project, production: Environment, staging: Environment, shop: Application, shopStaging: Application, blog: Application, shopDb: ManagedDatabase, stagingDb: ManagedDatabase, blogDb: ManagedDatabase}
 */
function projectAccessFleet(Team $team): array
{
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $db = Server::factory()->for($team)->create(['name' => 'db']);
    $storefront = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    [$production, $staging] = $storefront->environments()->get()->all();

    return [
        'web' => $web,
        'db' => $db,
        'storefront' => $storefront,
        'production' => $production,
        'staging' => $staging,
        'shop' => Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'environment_id' => $production->id]),
        'shopStaging' => Application::factory()->for($team)->for($db)->create(['name' => 'shop-staging', 'environment_id' => $staging->id]),
        'blog' => Application::factory()->for($team)->for($web)->create(['name' => 'blog']),
        'shopDb' => ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'shop_db', 'environment_id' => $production->id]),
        'stagingDb' => ManagedDatabase::factory()->for($team)->for($db)->create(['name' => 'staging_db', 'environment_id' => $staging->id]),
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

test("a whole project shows every environment's apps, including ones filed later", function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $member = projectAccessMember($team);
    $access = app(TeamAccess::class);

    $access->grant($team, $member, $fleet['storefront']);

    expect(projectAccessNames($access->applications($member, $team)))->toBe(['shop', 'shop-staging'])
        ->and(projectAccessNames($access->databases($member, $team)))->toBe(['shop_db', 'staging_db'])
        ->and($access->canSeeProject($member, $fleet['storefront']))->toBeTrue()
        ->and($access->seesWholeProject($member, $fleet['storefront']))->toBeTrue()
        ->and($access->environmentsOf($member, $fleet['storefront'])->pluck('name')->all())->toBe(['Production', 'Staging']);

    $preview = $fleet['storefront']->environments()->create(['name' => 'Preview', 'position' => 2]);
    $fleet['blog']->update(['environment_id' => $preview->id]);
    app()->forgetScopedInstances();

    $later = app(TeamAccess::class);

    expect(projectAccessNames($later->applications($member, $team)))->toBe(['blog', 'shop', 'shop-staging'])
        ->and($later->canSeeApplication($member, $fleet['blog']->fresh()))->toBeTrue()
        ->and($later->canSeeEnvironment($member, $preview))->toBeTrue();
});

test('one environment shows its apps and databases and nothing from the others', function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $member = projectAccessMember($team);
    $access = app(TeamAccess::class);

    $access->grant($team, $member, $fleet['staging']);

    expect(projectAccessNames($access->applications($member, $team)))->toBe(['shop-staging'])
        ->and(projectAccessNames($access->databases($member, $team)))->toBe(['staging_db'])
        ->and($access->canSeeApplication($member, $fleet['shop']))->toBeFalse()
        ->and($access->canSeeDatabase($member, $fleet['stagingDb']))->toBeTrue()
        ->and($access->hidesDatabase($member, $fleet['shopDb']))->toBeTrue()
        ->and($access->canSeeProject($member, $fleet['storefront']))->toBeTrue()
        ->and($access->seesWholeProject($member, $fleet['storefront']))->toBeFalse()
        ->and($access->canSeeEnvironment($member, $fleet['production']))->toBeFalse()
        ->and($access->environmentsOf($member, $fleet['storefront'])->pluck('name')->all())->toBe(['Staging'])
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

test('sync keeps project and environment grants and drops ones from another team', function () {
    $team = Team::factory()->create();
    $fleet = projectAccessFleet($team);
    $member = projectAccessMember($team);
    $elsewhere = Project::factory()->withEnvironments(['Production'])->create();
    $access = app(TeamAccess::class);

    $access->sync($team, $member, false, [], [], [$fleet['storefront']->id, $elsewhere->id], [$fleet['staging']->id, $elsewhere->environments()->sole()->id]);

    expect($access->summary($team, $member))->toBe([
        'everything' => false,
        'servers' => [],
        'applications' => [],
        'projects' => [$fleet['storefront']->id],
        'environments' => [$fleet['staging']->id],
    ]);
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
