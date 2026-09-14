<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

function deploymentsIndexMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function deploymentsIndexApp(Team $team, string $name = 'Shop'): Application
{
    return Application::factory()->for($team)->for(Server::factory()->for($team)->create(['name' => 'web-1']))->create(['name' => $name]);
}

test('the deployments page lists only the teams own deployments', function () {
    $team = Team::factory()->create();
    $application = deploymentsIndexApp($team);
    Deployment::factory()->for($application)->count(2)->create(['status' => DeploymentStatus::Succeeded]);
    Deployment::factory()->for(deploymentsIndexApp(Team::factory()->create(), 'Not ours'))->create();

    $response = $this->actingAs(deploymentsIndexMember($team))
        ->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('deployments/Index')
            ->where('filter', 'all')
            ->has('deployments', 2)
            ->where('deployments.0.application', 'Shop')
            ->where('deployments.0.server', 'web-1'));

    expect($response->getContent())->not->toContain('Not ours');
});

test('running deployments are pinned above newer finished ones, the rest newest first', function () {
    $team = Team::factory()->create();
    $application = deploymentsIndexApp($team);
    $oldest = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'created_at' => now()->subHours(3)]);
    $running = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running, 'created_at' => now()->subHours(2)]);
    $newest = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'created_at' => now()->subHour()]);

    $this->actingAs(deploymentsIndexMember($team))
        ->get(route('deployments.index'))
        ->assertInertia(fn($page) => $page
            ->where('deployments.0.id', $running->id)
            ->where('deployments.0.state.key', 'running')
            ->where('deployments.1.id', $newest->id)
            ->where('deployments.2.id', $oldest->id));
});

test('a status filter narrows the list', function (string $filter, DeploymentStatus $status) {
    $team = Team::factory()->create();
    $application = deploymentsIndexApp($team);

    foreach ([DeploymentStatus::Running, DeploymentStatus::Succeeded, DeploymentStatus::Failed] as $each) {
        Deployment::factory()->for($application)->create(['status' => $each]);
    }

    $this->actingAs(deploymentsIndexMember($team))
        ->get(route('deployments.index', ['status' => $filter]))
        ->assertInertia(fn($page) => $page
            ->where('filter', $filter)
            ->has('deployments', 1)
            ->where('deployments.0.state.key', $filter === 'deployed' ? 'deployed' : $status->value));
})->with([
    ['running', DeploymentStatus::Running],
    ['deployed', DeploymentStatus::Succeeded],
    ['failed', DeploymentStatus::Failed],
]);

test('every filter carries its count and link whichever filter is active', function () {
    $team = Team::factory()->create();
    $application = deploymentsIndexApp($team);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running]);
    Deployment::factory()->for($application)->count(3)->create(['status' => DeploymentStatus::Succeeded]);
    Deployment::factory()->for($application)->count(2)->create(['status' => DeploymentStatus::Failed]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Cancelled]);
    Deployment::factory()->for(deploymentsIndexApp(Team::factory()->create()))->count(4)->create(['status' => DeploymentStatus::Failed]);

    $this->actingAs(deploymentsIndexMember($team))
        ->get(route('deployments.index', ['status' => 'failed']))
        ->assertInertia(fn($page) => $page->where('filters', [
            ['key' => 'all', 'label' => 'All', 'count' => 7, 'href' => route('deployments.index')],
            ['key' => 'running', 'label' => 'Running', 'count' => 1, 'href' => route('deployments.index', ['status' => 'running'])],
            ['key' => 'deployed', 'label' => 'Deployed', 'count' => 3, 'href' => route('deployments.index', ['status' => 'deployed'])],
            ['key' => 'failed', 'label' => 'Failed', 'count' => 2, 'href' => route('deployments.index', ['status' => 'failed'])],
        ]));
});

test('an unknown filter falls back to everything', function (string $status) {
    $team = Team::factory()->create();
    $application = deploymentsIndexApp($team);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Cancelled]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded]);

    $this->actingAs(deploymentsIndexMember($team))
        ->get(route('deployments.index', ['status' => $status]))
        ->assertInertia(fn($page) => $page->where('filter', 'all')->has('deployments', 2));
})->with(['cancelled', 'succeeded', 'everything']);

test('the list is capped so a busy fleet cannot flood the page', function () {
    $team = Team::factory()->create();
    Deployment::factory()->for(deploymentsIndexApp($team))->count(101)->create(['status' => DeploymentStatus::Succeeded]);

    $this->actingAs(deploymentsIndexMember($team))
        ->get(route('deployments.index'))
        ->assertInertia(fn($page) => $page->has('deployments', 100)->where('filters.0.count', 101));
});

test('a user without a team sees an empty history', function () {
    Deployment::factory()->for(deploymentsIndexApp(Team::factory()->create()))->create();

    $this->actingAs(User::factory()->create())
        ->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->has('deployments', 0)->where('filters.0.count', 0));
});

test('each row links to its deploy screen', function () {
    $team = Team::factory()->create();
    $application = deploymentsIndexApp($team);
    $deployment = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913140000']);

    $this->actingAs(deploymentsIndexMember($team))
        ->get(route('deployments.index'))
        ->assertInertia(fn($page) => $page
            ->where('deployments.0.release', '20260913-140000')
            ->where('deployments.0.href', route('servers.applications.deployments.show', [$application->server_id, $application, $deployment])));
});

test('the deployments page requires authentication', function () {
    $this->get(route('deployments.index'))->assertRedirect(route('login'));
});
