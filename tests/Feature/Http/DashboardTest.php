<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function dashboardUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function dashboardTile(TestResponse $response, string $id): ?array
{
    return collect($response->inertiaProps('tiles'))->firstWhere('id', $id);
}

test('the dashboard counts what the team actually has', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    Server::factory()->for($team)->count(2)->create();
    Application::factory()->for($team)->for($server)->count(4)->create();

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('Dashboard')
            ->where('hasTeam', true)
            ->where('counts', ['servers' => 3, 'applications' => 4])
            ->has('servers', 3)
            ->has('tiles', 4));

    expect(dashboardTile($response, 'applications'))->toMatchArray(['value' => '4', 'sub' => 'across 3 servers']);
});

test('another teams resources are not counted', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();

    Server::factory()->for($team)->create();
    $theirs = Server::factory()->for($other)->count(5)->create();
    Application::factory()->for($other)->for($theirs->first())->count(2)->create();

    $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->where('counts.servers', 1)
            ->where('counts.applications', 0)
            ->has('servers', 1));
});

test('a server whose host key changed is raised for attention', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'name' => 'web-1',
        'pending_host_key_fingerprint' => 'SHA256:something-new',
    ]);

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('servers.0.state.key', 'host_key'));

    expect(dashboardTile($response, 'attention'))->toMatchArray([
        'value' => '1',
        'sub' => 'web-1 · host key changed',
        'tone' => 'warning',
        'href' => route('servers.show', $server),
    ]);
});

test('a stopped application is raised for attention', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create([
        'name' => 'Storefront',
        'power_state' => PowerState::Stopped,
    ]);

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))->get(route('dashboard'));

    expect(dashboardTile($response, 'attention'))->toMatchArray([
        'value' => '1',
        'sub' => 'Storefront · stopped',
        'tone' => 'warning',
        'href' => route('servers.applications.show', [$server, $application]),
    ]);
});

test('a failed deployment is raised for attention', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Storefront']);
    $deployment = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed]);

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))->get(route('dashboard'));

    expect(dashboardTile($response, 'attention'))->toMatchArray([
        'value' => '1',
        'sub' => 'Storefront · last deploy failed',
        'tone' => 'warning',
        'href' => route('servers.applications.deployments.show', [$server, $application, $deployment]),
    ]);
});

test('a failed deployment that a later one fixed is not raised', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    $application = Application::factory()->for($team)->for($server)->create();

    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'created_at' => now()->subHour()]);
    Deployment::factory()->for($application)->succeeded()->create();

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))->get(route('dashboard'));

    expect(dashboardTile($response, 'attention'))->toMatchArray(['value' => '0', 'sub' => 'all clear']);
});

test('a healthy team is told nothing needs attention', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))->get(route('dashboard'));

    expect(dashboardTile($response, 'attention'))->toMatchArray([
        'value' => '0',
        'sub' => 'all clear',
        'tone' => 'default',
    ]);
});

test('the attention tile counts every problem but spells out only the most serious', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    Application::factory()->for($team)->for($server)->count(8)->create(['power_state' => PowerState::Stopped]);
    Server::factory()->for($team)->create(['name' => 'web-9', 'pending_host_key_fingerprint' => 'SHA256:x']);

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))->get(route('dashboard'));

    expect(dashboardTile($response, 'attention'))->toMatchArray([
        'value' => '9',
        'sub' => 'web-9 · host key changed',
    ]);
});

test('recent deployments are the teams own, newest first', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Storefront']);

    Deployment::factory()->for($application)->succeeded()->create([
        'started_at' => now()->subHours(2),
        'finished_at' => now()->subHours(2),
    ]);
    $newer = Deployment::factory()->for($application)->succeeded()->create([
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    $other = Application::factory()->for(Team::factory())->create(['name' => 'Someone Elses']);
    Deployment::factory()->for($other)->succeeded()->create();

    $response = $this->actingAs(dashboardUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->has('activity', 2)
            ->where('activity.0.at', $newer->fresh()->finished_at->toIso8601String()));

    expect(dashboardTile($response, 'deploys'))->toMatchArray(['value' => '2'])
        ->and($response->getContent())->not->toContain('Someone Elses');
});

test('the live banner shows a running deployment of the team and nobody elses', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Storefront']);
    $user = dashboardUser($team, TeamRole::Member);

    Deployment::factory()->for($application)->succeeded()->create();
    Deployment::factory()->for(Application::factory()->for(Team::factory()))->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('live', null));

    $running = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->where('live.application', 'Storefront')
            ->where('live.server', 'web-1')
            ->where('live.href', route('servers.applications.deployments.show', [$server, $application, $running])));
});

test('a member is not offered the connect-a-server action', function () {
    $team = Team::factory()->create();

    $this->actingAs(dashboardUser($team, TeamRole::Member))
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('can.createServer', false));
});

test('a user with no team gets an empty dashboard rather than an error', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('hasTeam', false)
            ->where('counts.servers', 0)
            ->where('live', null)
            ->has('tiles', 0)
            ->has('servers', 0)
            ->has('activity', 0));
});

test('the overview pages are behind authentication', function (string $name) {
    $this->get(route($name))->assertRedirect(route('login'));
})->with(['dashboard', 'deployments.index', 'releases.index']);
