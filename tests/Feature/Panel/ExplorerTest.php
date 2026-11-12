<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ExplorerPresenter;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

function explorerMember(Team $team, TeamRole $role = TeamRole::Admin, bool $everything = false): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

/**
 * @return array<string, mixed>
 */
function explorerFleet(Team $team): array
{
    $web = Server::factory()->for($team)->create(['name' => 'web', 'created_at' => now()->subMinutes(2)]);
    $db = Server::factory()->for($team)->create(['name' => 'db', 'created_at' => now()->subMinute()]);
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
 * @return array<string, mixed>
 */
function explorerFor(User $user, Team $team): array
{
    app()->forgetScopedInstances();

    return app(ExplorerPresenter::class)->present($user, $team);
}

test('the owner sees every project with its environments, the other apps and every server', function () {
    $team = Team::factory()->owned()->create(['name' => 'Monoven Ltd']);
    $fleet = explorerFleet($team);

    $explorer = explorerFor($team->owner, $team);
    $storefront = $explorer['projects'][0];

    expect($explorer['team'])->toBe(['name' => 'Monoven Ltd', 'detail' => 'Owner · 1 person'])
        ->and($explorer['projects'])->toHaveCount(1)
        ->and($storefront)->toMatchArray(['name' => 'Storefront', 'href' => route('projects.show', $fleet['storefront']), 'deploying' => false, 'count' => 4])
        ->and(collect($storefront['environments'])->pluck('name')->all())->toBe(['Production', 'Staging'])
        ->and(collect($storefront['environments'][0]['apps'])->pluck('name')->all())->toBe(['shop'])
        ->and(collect($storefront['environments'][0]['databases'])->pluck('name')->all())->toBe(['shop_db'])
        ->and(collect($storefront['environments'][1]['apps'])->pluck('name')->all())->toBe(['shop-staging'])
        ->and($storefront['environments'][0]['apps'][0])->toMatchArray(['server' => 'web', 'href' => route('servers.applications.show', [$fleet['web'], $fleet['shop']])])
        ->and($storefront['environments'][0]['databases'][0]['href'])->toBe(route('servers.databases.show', [$fleet['web'], $fleet['shopDb']]))
        ->and(collect($explorer['unfiled'])->pluck('name')->all())->toBe(['blog'])
        ->and(collect($explorer['servers'])->pluck('name')->all())->toBe(['web', 'db'])
        ->and($explorer['servers'][0]['href'])->toBe(route('servers.show', $fleet['web']))
        ->and($explorer['running'])->toBeNull()
        ->and($explorer['can'])->toBe(['createProject' => true, 'createServer' => true]);
});

test('an environment-only member sees that environment alone and no servers', function () {
    $team = Team::factory()->owned()->create();
    $fleet = explorerFleet($team);
    $member = explorerMember($team);
    app(TeamAccess::class)->grant($team, $member, $fleet['staging']);

    $explorer = explorerFor($member, $team);

    expect(collect($explorer['projects'])->pluck('name')->all())->toBe(['Storefront'])
        ->and(collect($explorer['projects'][0]['environments'])->pluck('name')->all())->toBe(['Staging'])
        ->and(collect($explorer['projects'][0]['environments'][0]['apps'])->pluck('name')->all())->toBe(['shop-staging'])
        ->and(collect($explorer['projects'][0]['environments'][0]['databases'])->pluck('name')->all())->toBe(['staging_db'])
        ->and($explorer['projects'][0]['count'])->toBe(2)
        ->and($explorer['unfiled'])->toBe([])
        ->and($explorer['servers'])->toBe([]);
});

test('a member narrowed to a server sees it and its apps, with apps of projects they cannot open among the other apps', function () {
    $team = Team::factory()->owned()->create();
    $fleet = explorerFleet($team);
    $member = explorerMember($team);
    app(TeamAccess::class)->grant($team, $member, $fleet['web']);

    $explorer = explorerFor($member, $team);

    expect($explorer['projects'])->toBe([])
        ->and(collect($explorer['unfiled'])->pluck('name')->all())->toBe(['blog', 'shop'])
        ->and(collect($explorer['servers'])->pluck('name')->all())->toBe(['web']);
});

test('a project with no environment the member can see is left out', function () {
    $team = Team::factory()->owned()->create();
    explorerFleet($team);
    Project::factory()->for($team)->withEnvironments(['Production'])->create(['name' => 'Atlas']);
    $member = explorerMember($team);

    expect(explorerFor($member, $team)['projects'])->toBe([]);
});

test('a running deploy shows its app, server, phase states and link', function () {
    $team = Team::factory()->owned()->create();
    $fleet = explorerFleet($team);
    $deployment = Deployment::factory()->for($fleet['shop'], 'application')->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinute(),
        'log' => "[10:00:00] clone: cloning the release\n[10:00:04] deps: composer install\n",
    ]);

    $explorer = explorerFor($team->owner, $team);

    expect($explorer['running'])->toMatchArray([
        'application' => 'shop',
        'server' => 'web',
        'href' => route('servers.applications.deployments.show', [$fleet['web'], $fleet['shop'], $deployment]),
        'phase' => 'installing dependencies',
        'states' => ['done', 'running', 'pending', 'pending', 'pending', 'pending'],
        'more' => 0,
    ])->and($explorer['projects'][0]['deploying'])->toBeTrue();
});

test('a cloud team with no plan yet shows the role instead', function () {
    config(['coresmith.edition' => 'cloud']);
    $team = Team::factory()->owned()->create();

    expect(explorerFor($team->owner, $team)['team']['detail'])->toBe('Owner · 1 person');
});

test('what a member may create follows their role', function () {
    $team = Team::factory()->owned()->create();
    explorerFleet($team);
    $member = explorerMember($team, TeamRole::Member, everything: true);

    expect(explorerFor($member, $team)['can'])->toBe(['createProject' => false, 'createServer' => false]);
});

test('the explorer costs the same queries for one app as for five', function () {
    $queries = function (int $apps): int {
        $team = Team::factory()->owned()->create();
        $server = Server::factory()->for($team)->create();
        $project = Project::factory()->for($team)->withEnvironments(['Production'])->create();
        $environment = $project->environments()->first();
        Application::factory()->count($apps)->for($team)->for($server)->create(['environment_id' => $environment->id]);
        Application::factory()->count($apps)->for($team)->for($server)->create();
        ManagedDatabase::factory()->count($apps)->for($team)->for($server)->create(['environment_id' => $environment->id]);

        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ExplorerPresenter::class)->present($team->owner, $team);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queries(5))->toBe($queries(1));
});

test('the shared prop carries the explorer for a member and nothing for a guest', function () {
    $team = Team::factory()->owned()->create(['name' => 'Monoven Ltd']);
    explorerFleet($team);

    $this->actingAs($team->owner)->get(route('dashboard'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('explorer.team.name', 'Monoven Ltd')
            ->has('explorer.projects', 1)
            ->has('explorer.servers', 2));

    auth()->logout();

    $this->get(route('login'))->assertInertia(fn(AssertableInertia $page) => $page->where('explorer', null));
});
