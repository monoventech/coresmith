<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;

function setupOwner(Team $team, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

beforeEach(function () {
    config(['services.github.app_id' => null]);
});

test('a user without a team is sent to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('setup.show'))
        ->assertRedirect(route('dashboard'));
});

test('a brand new team is shown the first step with nothing connected', function () {
    $team = Team::factory()->create();

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('setup/Show')
            ->where('steps', [
                ['label' => 'Connect GitHub', 'done' => false],
                ['label' => 'Add a server', 'done' => false],
                ['label' => 'Provision', 'done' => false],
                ['label' => 'First deploy', 'done' => false],
            ])
            ->where('current', 0)
            ->where('step', 0)
            ->where('complete', false)
            ->where('github', [
                'connected' => false,
                'name' => null,
                'label' => null,
                'configured' => false,
                'connect_href' => route('source-providers.connect', 'github_app'),
                'settings_href' => route('source-providers.index'),
            ])
            ->where('team', ['id' => $team->id])
            ->where('server', null)
            ->where('application', null)
            ->where('create_application_href', null));
});

test('a connected provider and a configured github app are described', function () {
    config(['services.github.app_id' => '123456']);
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create(['name' => 'Monoven GitHub', 'type' => 'github_app']);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('github.connected', true)
            ->where('github.name', 'Monoven GitHub')
            ->where('github.label', 'GitHub')
            ->where('github.configured', true)
            ->where('steps.0.done', true)
            ->where('current', 1));
});

test('a requested step is kept between the first step and the current one', function (string $requested, int $expected) {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Pending]);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show', ['step' => $requested]))
        ->assertInertia(fn($page) => $page->where('current', 2)->where('step', $expected));
})->with([
    'an earlier step' => ['1', 1],
    'the current step' => ['2', 2],
    'a step not reached yet' => ['3', 2],
    'far beyond the last' => ['99', 2],
    'negative' => ['-4', 0],
    'not a number' => ['next', 0],
]);

test('the server still being set up is shown in preference to an active one', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['name' => 'old-active', 'status' => ServerStatus::Active, 'created_at' => now()->subDay()]);
    Server::factory()->for($team)->create(['name' => 'newer-active', 'status' => ServerStatus::Active, 'created_at' => now()->subHour()]);
    $pending = Server::factory()->for($team)->create(['name' => 'in-progress', 'status' => ServerStatus::Provisioning, 'created_at' => now()->subDays(2)]);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('server.id', $pending->id)
            ->where('server.name', 'in-progress')
            ->where('server.href', route('servers.show', $pending))
            ->where('server.test_href', route('servers.test-connection', $pending))
            ->where('server.provision_href', route('servers.provision', $pending))
            ->has('server.provisioning.phases', 8)
            ->where('create_application_href', null));
});

test('once every server is active the newest one is shown and an application can be created on it', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'created_at' => now()->subDay()]);
    $newest = Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'created_at' => now()->subHour()]);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('server.id', $newest->id)
            ->where('server.active', true)
            ->where('create_application_href', route('servers.applications.create', $newest)));
});

test('the latest application is offered for its first deploy', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    Application::factory()->for($team)->for($server)->create(['name' => 'Older', 'created_at' => now()->subDay()]);
    $application = Application::factory()->for($team)->for($server)->create([
        'name' => 'Shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'git@github.com:monoven/shop.git',
        'created_at' => now(),
    ]);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page->where('application', [
            'id' => $application->id,
            'name' => 'Shop',
            'repository' => 'monoven/shop',
            'kind' => 'Laravel',
            'runtime' => 'PHP 8.5',
            'server' => 'web-1',
            'deploy_href' => route('servers.applications.deploy', [$server, $application]),
        ]));
});

test('a repository that cannot be shortened is shown as given', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    Application::factory()->for($team)->for($server)->create(['repository_url' => 'https://git.example.com/solo']);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page->where('application.repository', 'https://git.example.com/solo'));
});

test('a finished setup says so and stays on the last step', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    Deployment::factory()->for(Application::factory()->for($team)->for($server))->create(['status' => DeploymentStatus::Succeeded]);

    $this->actingAs(setupOwner($team, TeamRole::Member))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page->where('complete', true)->where('current', 3)->where('step', 3));
});

test('setup requires authentication', function () {
    $this->get(route('setup.show'))->assertRedirect(route('login'));
});
