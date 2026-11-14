<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\Eloquent\Relations\HasMany;

function teamAccessMember(Team $team, TeamRole $role = TeamRole::Member, bool $everything = true): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

/**
 * @return array{web: Server, db: Server, shop: Application, blog: Application, api: Application}
 */
function teamAccessFleet(Team $team): array
{
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $db = Server::factory()->for($team)->create(['name' => 'db']);

    return [
        'web' => $web,
        'db' => $db,
        'shop' => Application::factory()->for($team)->for($web)->create(['name' => 'shop']),
        'blog' => Application::factory()->for($team)->for($web)->create(['name' => 'blog']),
        'api' => Application::factory()->for($team)->for($db)->create(['name' => 'api']),
    ];
}

/**
 * @return list<string>
 */
function teamAccessNames(HasMany $relation): array
{
    return $relation->orderBy('name')->pluck('name')->all();
}

test('owners see every server and app whatever their flag says', function () {
    $team = Team::factory()->create();
    teamAccessFleet($team);
    $owner = teamAccessMember($team, TeamRole::Owner, everything: false);
    $access = app(TeamAccess::class);

    expect($access->seesEverything($owner, $team))->toBeTrue()
        ->and(teamAccessNames($access->servers($owner, $team)))->toBe(['db', 'web'])
        ->and(teamAccessNames($access->applications($owner, $team)))->toBe(['api', 'blog', 'shop']);
});

test('a member who sees everything gets the whole team', function () {
    $team = Team::factory()->create();
    ['db' => $db] = teamAccessFleet($team);
    $member = teamAccessMember($team);
    $access = app(TeamAccess::class);

    expect($access->seesEverything($member, $team))->toBeTrue()
        ->and(teamAccessNames($access->servers($member, $team)))->toBe(['db', 'web'])
        ->and(teamAccessNames($access->applications($member, $team)))->toBe(['api', 'blog', 'shop'])
        ->and($access->canSeeServer($member, $db))->toBeTrue();
});

test('a narrowed member gets granted servers with all their apps, plus apps granted on their own', function () {
    $team = Team::factory()->create();
    ['web' => $web, 'db' => $db, 'shop' => $shop, 'api' => $api] = teamAccessFleet($team);
    $member = teamAccessMember($team, everything: false);
    $access = app(TeamAccess::class);
    $access->grant($team, $member, $web);
    $access->grant($team, $member, $api);
    $access->grant($team, $member, $api);

    expect($access->seesEverything($member, $team))->toBeFalse()
        ->and(teamAccessNames($access->servers($member, $team)))->toBe(['web'])
        ->and(teamAccessNames($access->applications($member, $team)))->toBe(['api', 'blog', 'shop'])
        ->and($access->canSeeServer($member, $web))->toBeTrue()
        ->and($access->canSeeServer($member, $db))->toBeFalse()
        ->and($access->canSeeApplication($member, $shop))->toBeTrue()
        ->and($access->canSeeApplication($member, $api))->toBeTrue()
        ->and(AccessGrant::query()->count())->toBe(2);
});

test('an app granted on its own does not bring its server', function () {
    $team = Team::factory()->create();
    ['db' => $db, 'api' => $api] = teamAccessFleet($team);
    $member = teamAccessMember($team, everything: false);
    $access = app(TeamAccess::class);
    $access->grant($team, $member, $api);

    expect(teamAccessNames($access->servers($member, $team)))->toBe([])
        ->and(teamAccessNames($access->applications($member, $team)))->toBe(['api'])
        ->and($access->canSeeServer($member, $db))->toBeFalse()
        ->and($access->canSeeApplication($member, $api))->toBeTrue();
});

test('a server added later is not seen until granted, while an app added later to a granted server is', function () {
    $team = Team::factory()->create();
    ['web' => $web] = teamAccessFleet($team);
    $member = teamAccessMember($team, everything: false);
    $access = app(TeamAccess::class);
    $access->grant($team, $member, $web);

    $cache = Server::factory()->for($team)->create(['name' => 'cache']);
    $later = Application::factory()->for($team)->for($web)->create(['name' => 'later']);

    expect(teamAccessNames($access->servers($member, $team)))->toBe(['web'])
        ->and($access->canSeeServer($member, $cache))->toBeFalse()
        ->and($access->canSeeApplication($member, $later))->toBeTrue();
});

test('deleting a server or app takes its grants with it', function () {
    $team = Team::factory()->create();
    ['web' => $web, 'db' => $db, 'api' => $api] = teamAccessFleet($team);
    $member = teamAccessMember($team, everything: false);
    $access = app(TeamAccess::class);
    $access->grant($team, $member, $web);
    $access->grant($team, $member, $db);
    $access->grant($team, $member, $api);

    $web->delete();
    $api->delete();

    expect(teamAccessNames($access->servers($member, $team)))->toBe(['db'])
        ->and(teamAccessNames($access->applications($member, $team)))->toBe([])
        ->and($access->summary($team, $member))->toBe(['everything' => false, 'servers' => [$db->id], 'applications' => [], 'projects' => [], 'environments' => [], 'withheld_applications' => [], 'withheld_databases' => []]);

    $api->forceDelete();
    $db->forceDelete();

    expect(AccessGrant::query()->pluck('server_id')->all())->toBe([$web->id]);
});

test('sync replaces what is shared and ignores servers and apps from another team', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    ['web' => $web, 'shop' => $shop] = teamAccessFleet($team);
    ['web' => $theirs, 'api' => $theirApp] = teamAccessFleet($other);
    $member = teamAccessMember($team);
    $access = app(TeamAccess::class);
    $access->grant($team, $member, $shop);

    $access->sync($team, $member, false, [$web->id, $theirs->id], [$theirApp->id]);

    expect($access->summary($team, $member))->toBe(['everything' => false, 'servers' => [$web->id], 'applications' => [], 'projects' => [], 'environments' => [], 'withheld_applications' => [], 'withheld_databases' => []])
        ->and($access->seesEverything($member, $team))->toBeFalse();

    $access->sync($team, $member, true, [], []);

    expect($access->seesEverything($member, $team))->toBeTrue();
});

test('members already in a team see everything after the migration', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    expect(app(TeamAccess::class)->seesEverything($user->fresh(), $team))->toBeTrue();
});

test('someone outside the team sees nothing in it', function () {
    $team = Team::factory()->create();
    ['web' => $web] = teamAccessFleet($team);
    $stranger = User::factory()->create();
    $access = app(TeamAccess::class);

    expect($access->seesEverything($stranger, $team))->toBeFalse()
        ->and($access->canSeeServer($stranger, $web))->toBeFalse()
        ->and(teamAccessNames($access->servers($stranger, $team)))->toBe([]);
});
