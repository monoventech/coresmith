<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\DeploymentStatus;
use App\Enums\MonitorKind;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\MonitorAlert;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\Formats;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, web: Server, db: Server, project: Project, shop: Application, shopStaging: Application, blog: Application, shopDb: ManagedDatabase, deploy: Deployment}
 */
function projectPagesFleet(): array
{
    $team = Team::factory()->owned()->create();
    $owner = $team->owner->fresh();
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $db = Server::factory()->for($team)->create(['name' => 'db']);
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'project_id' => $project->id, 'tags' => ['production'], 'domain' => 'shop.example.com']);
    $deploy = Deployment::factory()->for($shop)->create([
        'status' => DeploymentStatus::Succeeded,
        'commit_message' => "Add the checkout page\n\nWith card and wallet payments.",
        'commit_author' => 'Maya Lindqvist',
        'triggered_by' => $owner->id,
        'created_at' => now()->subHours(2),
        'started_at' => now()->subHours(2),
        'finished_at' => now()->subHours(2)->addMinutes(2),
    ]);

    return [
        'team' => $team,
        'owner' => $owner,
        'web' => $web,
        'db' => $db,
        'project' => $project,
        'shop' => $shop,
        'shopStaging' => Application::factory()->for($team)->for($db)->create(['name' => 'shop-staging', 'project_id' => $project->id, 'tags' => ['staging'], 'domain' => null]),
        'blog' => Application::factory()->for($team)->for($web)->create(['name' => 'blog']),
        'shopDb' => ManagedDatabase::factory()->for($team)->for($web)->create(['type' => DatabaseType::Postgresql, 'name' => 'shop_db', 'project_id' => $project->id]),
        'deploy' => $deploy,
    ];
}

function projectPagesPerson(Team $team, TeamRole $role, bool $everything): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

test('the projects tab lists each project with its apps, and apps in no project', function () {
    $fleet = projectPagesFleet();

    $this->actingAs($fleet['owner'])->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->component('projects/Index')
            ->where('counts', ['servers' => 2, 'applications' => 3])
            ->where('projects.0.name', 'Storefront')
            ->where('projects.0.href', route('projects.show', $fleet['project']))
            ->where('projects.0.meta', '2 apps · 1 database')
            ->where('projects.0.apps', fn($apps): bool => collect($apps)->map(fn(array $app): array => [$app['name'], $app['tags']])->all() === [['shop', ['production']], ['shop-staging', ['staging']]])
            ->where('projects.0.latest.application', 'shop')
            ->where('projects.0.latest.when', Formats::when($fleet['deploy']->started_at))
            ->where('unfiled', [['id' => $fleet['blog']->id, 'name' => 'blog', 'server' => 'web', 'href' => route('servers.applications.show', [$fleet['web'], $fleet['blog']])]])
            ->where('narrowed', false)
            ->where('can.create', true));
});

test('the project page shows each app with its stack, server, domain and last deploy, then databases, deploys, servers and who can see it', function () {
    $fleet = projectPagesFleet();
    $id = $fleet['project']->id;

    $this->actingAs($fleet['owner'])->get(route('projects.show', $fleet['project']))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->component('projects/Show')
            ->where('project.name', 'Storefront')
            ->where('project.meta', '2 apps · 1 database · on 2 servers')
            ->where('applications.0.name', 'shop')
            ->where('applications.0.tags', ['production'])
            ->where('applications.0.server', ['name' => 'web', 'href' => route('servers.show', $fleet['web'])])
            ->where('applications.0.domain', ['label' => 'shop.example.com', 'href' => 'https://shop.example.com'])
            ->where('applications.0.last_deploy.commit', 'Add the checkout page')
            ->where('applications.0.last_deploy.by', $fleet['owner']->name)
            ->where('applications.0.last_deploy.state.key', 'deployed')
            ->where('applications.0.last_deploy.when', Formats::when($fleet['deploy']->started_at))
            ->where('applications.0.deploy_href', route('servers.applications.deploy', [$fleet['web'], $fleet['shop']]))
            ->where('applications.0.remove_href', route('projects.applications.destroy', [$fleet['project'], $fleet['shop']]))
            ->where('applications.1.name', 'shop-staging')
            ->where('applications.1.domain', null)
            ->where('applications.1.last_deploy', null)
            ->where('databases.0.name', 'shop_db')
            ->where('databases.0.type', 'postgresql')
            ->where('databases.0.remove_href', route('projects.databases.destroy', [$fleet['project'], $fleet['shopDb']]))
            ->where('deploys.0.application', 'shop')
            ->where('deploys_href', route('deployments.index', ['project' => $id]))
            ->where('servers', fn($servers): bool => collect($servers)->map(fn(array $server): array => [$server['name'], $server['href']])->all() === [['db', route('servers.show', $fleet['db'])], ['web', route('servers.show', $fleet['web'])]])
            ->where('people', [['name' => $fleet['owner']->name, 'role' => 'Owner', 'reach' => 'Sees everything']])
            ->where('access_href', route('teams.index'))
            ->where('add', [
                ['name' => 'db', 'href' => route('servers.applications.create', [$fleet['db'], 'project' => $id])],
                ['name' => 'web', 'href' => route('servers.applications.create', [$fleet['web'], 'project' => $id])],
            ])
            ->where('can', ['manage' => true, 'fill' => true])
            ->where('shared_part', false)
            ->where('adds_stay_private', false)
            ->where('candidates.applications', [['id' => $fleet['blog']->id, 'name' => 'blog', 'where' => 'No project']])
            ->where('candidates.databases', []));
});

test('when nothing needs attention the page says everything is running', function () {
    $fleet = projectPagesFleet();

    $this->actingAs($fleet['owner'])->get(route('projects.show', $fleet['project']))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('attention', [])->where('project.healthy', true));
});

test('what needs attention comes first: an app that does not answer, a failed deploy and a failing offsite backup', function () {
    $fleet = projectPagesFleet();
    MonitorAlert::open($fleet['db'], MonitorKind::Apps, $fleet['shopStaging']);
    $failed = Deployment::factory()->for($fleet['shop'])->create(['status' => DeploymentStatus::Failed, 'created_at' => now()->subMinutes(5), 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
    $fleet['shopDb']->forceFill(['last_offsite_error' => 'The bucket refused the upload.'])->save();

    $this->actingAs($fleet['owner'])->get(route('projects.show', $fleet['project']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('project.healthy', false)
            ->where('attention', [
                ['severity' => 'critical', 'subject' => 'shop-staging', 'summary' => "isn't answering", 'href' => route('servers.applications.show', [$fleet['db'], $fleet['shopStaging']])],
                ['severity' => 'warning', 'subject' => 'shop', 'summary' => 'last deploy failed', 'href' => route('servers.applications.deployments.show', [$fleet['web'], $fleet['shop'], $failed])],
                ['severity' => 'warning', 'subject' => 'shop_db', 'summary' => 'offsite backup failing', 'href' => route('servers.databases.show', [$fleet['web'], $fleet['shopDb']])],
            ])
            ->where('databases.0.offsite', ['label' => 'Offsite backup failing', 'tone' => 'destructive']));
});

test('a member the project is shared with in part sees only what is shared, and cannot change the project', function () {
    $fleet = projectPagesFleet();
    $admin = projectPagesPerson($fleet['team'], TeamRole::Admin, everything: false);
    app(TeamAccess::class)->sync($fleet['team'], $admin, false, [], [], [$fleet['project']->id], [], [$fleet['shop']->id], [$fleet['shopDb']->id]);

    $this->actingAs($admin)->get(route('projects.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('projects.0.meta', '1 app')
            ->where('projects.0.latest', null)
            ->where('unfiled', [])
            ->where('narrowed', true));

    $this->actingAs($admin)->get(route('projects.show', $fleet['project']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('applications', fn($apps): bool => collect($apps)->pluck('name')->all() === ['shop-staging'])
            ->where('databases', [])
            ->where('servers.0', ['id' => $fleet['db']->id, 'name' => 'db', 'href' => null, 'state' => null, 'cpu' => null, 'memory' => null, 'disk' => null])
            ->where('people', null)
            ->where('add', [])
            ->where('can', ['manage' => false, 'fill' => true])
            ->where('shared_part', true));
});

test('a project shared with everything held back shows nothing in it', function () {
    $fleet = projectPagesFleet();
    $dev = projectPagesPerson($fleet['team'], TeamRole::Developer, everything: false);
    app(TeamAccess::class)->sync($fleet['team'], $dev, false, [], [], [$fleet['project']->id], [], [$fleet['shop']->id, $fleet['shopStaging']->id], [$fleet['shopDb']->id]);

    $this->actingAs($dev)->get(route('projects.show', $fleet['project']))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('project.meta', '0 apps')
            ->where('applications', [])
            ->where('databases', [])
            ->where('shared_part', true)
            ->where('adds_stay_private', true)
            ->where('can.fill', true));
});

test('deployers browse projects but cannot change them', function () {
    $fleet = projectPagesFleet();
    $deployer = projectPagesPerson($fleet['team'], TeamRole::Member, everything: true);

    $this->actingAs($deployer)->get(route('projects.index'))->assertInertia(fn(AssertableInertia $page) => $page->where('can.create', false));
    $this->actingAs($deployer)->get(route('projects.show', $fleet['project']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('can', ['manage' => false, 'fill' => false])
            ->where('applications.0.remove_href', null)
            ->where('add', [])
            ->where('candidates', ['applications' => [], 'databases' => []]));
});

test('a project nobody shared with the member is not found', function () {
    $fleet = projectPagesFleet();
    $admin = projectPagesPerson($fleet['team'], TeamRole::Admin, everything: false);

    $this->actingAs($admin)->get(route('projects.show', $fleet['project']))->assertNotFound();
    $this->actingAs($admin)->get(route('projects.index'))->assertInertia(fn(AssertableInertia $page) => $page->where('projects', []));
});
