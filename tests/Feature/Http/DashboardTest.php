<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\DashboardController;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

function dashboardUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('the dashboard counts what the team actually has', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    Server::factory()->for($team)->count(2)->create();
    Application::factory()->for($team)->for($server)->count(4)->create();
    ManagedDatabase::factory()->for($team)->for($server)->count(2)->create();

    $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.servers', 3)
            ->where('counts.applications', 4)
            ->where('counts.databases', 2));
});

test('another teams resources are not counted', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();

    Server::factory()->for($team)->create();
    Server::factory()->for($other)->count(5)->create();

    $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('counts.servers', 1));
});

test('a server whose host key changed is raised for attention', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create([
        'name' => 'web-1',
        'pending_host_key_fingerprint' => 'SHA256:something-new',
    ]);

    $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('attention.0.severity', 'critical')
            ->where('attention.0.title', 'web-1 presented a different host key'));
});

test('a stopped application is raised for attention', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    Application::factory()->for($team)->for($server)->create([
        'name' => 'Storefront',
        'power_state' => PowerState::Stopped,
    ]);

    $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('attention.0.title', 'Storefront is stopped'));
});

test('a failed deployment is raised for attention', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Storefront']);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed]);

    $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('attention.0.title', 'The last deployment of Storefront failed'));
});

test('a healthy team is told nothing needs attention', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('attention', 0)->where('attentionTotal', 0));
});

test('the attention list is capped so it cannot bury the rest of the page', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->count(9)->create(['pending_host_key_fingerprint' => 'SHA256:x']);

    $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('attention', DashboardController::ATTENTION_LIMIT)
            ->where('attentionTotal', 9));
});

test('recent deployments are the teams own, newest first', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Storefront']);

    Deployment::factory()->for($application)->create(['commit_message' => 'older']);
    Deployment::factory()->for($application)->create(['commit_message' => 'newer']);

    $other = Application::factory()->for(Team::factory())->create();
    Deployment::factory()->for($other)->create(['commit_message' => 'not ours']);

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page->has('deployments', 2));

    expect($response->getContent())->not->toContain('not ours');
});

test('a member is not offered the connect-a-server action', function () {
    $team = Team::factory()->create();

    $this->actingAs(dashboardUser($team, TeamRole::Member))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('can.createServer', false));
});

test('a user with no team gets an empty dashboard rather than an error', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasTeam', false)->where('counts.servers', 0));
});

test('the global applications list covers every server in the team', function () {
    $team = Team::factory()->create();
    $first = Server::factory()->for($team)->create(['name' => 'web-1']);
    $second = Server::factory()->for($team)->create(['name' => 'web-2']);

    Application::factory()->for($team)->for($first)->create(['name' => 'Storefront']);
    Application::factory()->for($team)->for($second)->create(['name' => 'Internal API']);
    Application::factory()->for(Team::factory())->create(['name' => 'Someone Elses']);

    $response = $this->actingAs(dashboardUser($team, TeamRole::Member))
        ->get(route('applications.index'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->component('applications/Overview')->has('applications', 2));

    expect($response->getContent())->not->toContain('Someone Elses');
});

test('the global databases list covers every server in the team', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_production']);
    ManagedDatabase::factory()->for(Team::factory())->create(['name' => 'not_ours']);

    $response = $this->actingAs(dashboardUser($team, TeamRole::Member))
        ->get(route('databases.index'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->component('databases/Overview')->has('databases', 1));

    expect($response->getContent())->not->toContain('not_ours');
});

test('the overview pages are behind authentication', function (string $name) {
    $this->get(route($name))->assertRedirect(route('login'));
})->with(['dashboard', 'applications.index', 'databases.index']);
