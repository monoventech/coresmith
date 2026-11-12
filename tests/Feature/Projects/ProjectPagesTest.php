<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\Formats;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, web: Server, db: Server, project: Project, production: Environment, staging: Environment, shop: Application, shopStaging: Application, blog: Application, shopDb: ManagedDatabase}
 */
function projectPagesFleet(): array
{
    $team = Team::factory()->owned()->create();
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $db = Server::factory()->for($team)->create(['name' => 'db']);
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    [$production, $staging] = $project->environments()->get()->all();
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'environment_id' => $production->id]);

    Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::Succeeded, 'created_at' => now()->subHours(2), 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)->addMinutes(2)]);

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'web' => $web,
        'db' => $db,
        'project' => $project,
        'production' => $production,
        'staging' => $staging,
        'shop' => $shop,
        'shopStaging' => Application::factory()->for($team)->for($db)->create(['name' => 'shop-staging', 'environment_id' => $staging->id]),
        'blog' => Application::factory()->for($team)->for($web)->create(['name' => 'blog']),
        'shopDb' => ManagedDatabase::factory()->for($team)->for($web)->create(['type' => DatabaseType::Postgresql, 'name' => 'shop_db', 'environment_id' => $production->id]),
    ];
}

function projectPagesPerson(Team $team, TeamRole $role, bool $everything): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

test('the projects tab lists each project with its environments, and apps in no project', function () {
    $fleet = projectPagesFleet();

    $this->actingAs($fleet['owner'])->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->component('projects/Index')
            ->where('counts', ['servers' => 2, 'applications' => 3])
            ->where('projects.0.name', 'Storefront')
            ->where('projects.0.href', route('projects.show', $fleet['project']))
            ->where('projects.0.meta', '2 environments · 2 apps · 1 database')
            ->where('projects.0.environments', [
                ['id' => $fleet['production']->id, 'name' => 'Production', 'apps' => 1],
                ['id' => $fleet['staging']->id, 'name' => 'Staging', 'apps' => 1],
            ])
            ->where('projects.0.latest.application', 'shop')
            ->where('projects.0.latest.when', Formats::when(Deployment::query()->sole()->started_at))
            ->where('unfiled', [['id' => $fleet['blog']->id, 'name' => 'blog', 'server' => 'web', 'href' => route('servers.applications.show', [$fleet['web'], $fleet['blog']])]])
            ->where('narrowed', false)
            ->where('can.create', true));
});

test('the project page lists each environment with its apps, databases and latest deploys', function () {
    $fleet = projectPagesFleet();

    $this->actingAs($fleet['owner'])->get(route('projects.show', $fleet['project']))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->component('projects/Show')
            ->where('project.name', 'Storefront')
            ->where('project.meta', '2 environments · 2 apps · 1 database')
            ->where('environments.0.name', 'Production')
            ->where('environments.0.meta', '1 app · 1 database')
            ->where('environments.0.applications.0.name', 'shop')
            ->where('environments.0.applications.0.server', ['name' => 'web', 'href' => route('servers.show', $fleet['web'])])
            ->where('environments.0.applications.0.remove_href', route('projects.environments.applications.destroy', [$fleet['project'], $fleet['production'], $fleet['shop']]))
            ->where('environments.0.databases.0.name', 'shop_db')
            ->where('environments.0.databases.0.type', 'postgresql')
            ->where('environments.0.deploys.0.application', 'shop')
            ->where('environments.0.can.fill', true)
            ->where('environments.1.name', 'Staging')
            ->where('environments.1.applications.0.name', 'shop-staging')
            ->where('environments.1.deploys', [])
            ->where('can.manage', true)
            ->where('candidates.applications', fn($apps): bool => collect($apps)->pluck('where', 'name')->all() === ['blog' => 'No project', 'shop' => 'Storefront › Production', 'shop-staging' => 'Storefront › Staging'])
            ->where('candidates.databases', fn($databases): bool => collect($databases)->pluck('name')->all() === ['shop_db']));
});

test('an environment-only member sees the project with only their environment', function () {
    $fleet = projectPagesFleet();
    $admin = projectPagesPerson($fleet['team'], TeamRole::Admin, everything: false);
    app(TeamAccess::class)->grant($fleet['team'], $admin, $fleet['staging']);

    $this->actingAs($admin)->get(route('projects.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('projects.0.meta', '1 environment · 1 app')
            ->where('projects.0.environments', [['id' => $fleet['staging']->id, 'name' => 'Staging', 'apps' => 1]])
            ->where('projects.0.latest', null)
            ->where('unfiled', [])
            ->where('narrowed', true));

    $this->actingAs($admin)->get(route('projects.show', $fleet['project']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('environments', fn($environments): bool => collect($environments)->pluck('name')->all() === ['Staging'])
            ->where('environments.0.applications.0.server', ['name' => 'db', 'href' => null])
            ->where('environments.0.can.fill', true)
            ->where('can.manage', false)
            ->where('candidates.applications', fn($apps): bool => collect($apps)->pluck('name')->all() === ['shop-staging']));
});

test('deployers browse projects but cannot change them', function () {
    $fleet = projectPagesFleet();
    $deployer = projectPagesPerson($fleet['team'], TeamRole::Member, everything: true);

    $this->actingAs($deployer)->get(route('projects.index'))->assertInertia(fn(AssertableInertia $page) => $page->where('can.create', false));
    $this->actingAs($deployer)->get(route('projects.show', $fleet['project']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('can.manage', false)
            ->where('environments.0.can.fill', false)
            ->where('environments.0.applications.0.remove_href', null)
            ->where('candidates', ['applications' => [], 'databases' => []]));
});

test('a project nobody shared with the member is not found', function () {
    $fleet = projectPagesFleet();
    $admin = projectPagesPerson($fleet['team'], TeamRole::Admin, everything: false);

    $this->actingAs($admin)->get(route('projects.show', $fleet['project']))->assertNotFound();
    $this->actingAs($admin)->get(route('projects.index'))->assertInertia(fn(AssertableInertia $page) => $page->where('projects', []));
});
