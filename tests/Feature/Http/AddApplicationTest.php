<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CommandPalette;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

function addAppPerson(Team $team, TeamRole $role, bool $everything = true): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

test('with one server ready, Add an app goes straight to its form and keeps the project', function () {
    $team = Team::factory()->owned()->create();
    $web = Server::factory()->for($team)->create(['name' => 'web', 'status' => ServerStatus::Active]);
    Server::factory()->for($team)->create(['name' => 'new-box', 'status' => ServerStatus::Pending]);
    $project = Project::factory()->for($team)->create();

    $this->actingAs($team->owner)->get(route('applications.new'))->assertRedirect(route('servers.applications.create', $web));
    $this->actingAs($team->owner)->get(route('applications.new', ['project' => $project->id]))
        ->assertRedirect(route('servers.applications.create', [$web, 'project' => $project->id]));
});

test('with several servers ready, Add an app asks which one runs it, listing only ready servers the person can see', function () {
    $team = Team::factory()->owned()->create();
    $web = Server::factory()->for($team)->create(['name' => 'web', 'status' => ServerStatus::Active]);
    $api = Server::factory()->for($team)->create(['name' => 'api', 'status' => ServerStatus::Active]);
    $hidden = Server::factory()->for($team)->create(['name' => 'vault', 'status' => ServerStatus::Active]);
    Server::factory()->for($team)->create(['name' => 'new-box', 'status' => ServerStatus::Pending]);
    Application::factory()->for($team)->for($web)->create(['name' => 'shop']);
    $admin = addAppPerson($team, TeamRole::Admin, everything: false);
    app(TeamAccess::class)->sync($team, $admin, false, [$web->id, $api->id], []);

    $this->actingAs($admin)->get(route('applications.new'))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->component('applications/ChooseServer')
            ->where('servers', fn($servers): bool => collect($servers)->map(fn(array $server): array => [$server['name'], $server['href'], $server['meta']])->all() === [
                ['api', route('servers.applications.create', $api), '0 apps'],
                ['web', route('servers.applications.create', $web), '1 app'],
            ])
            ->where('can.createServer', true));

    expect($hidden->exists)->toBeTrue();
});

test('with no server ready, it says so and offers provisioning to people who can', function () {
    $team = Team::factory()->owned()->create();
    Server::factory()->for($team)->create(['name' => 'new-box', 'status' => ServerStatus::Provisioning]);

    $this->actingAs($team->owner)->get(route('applications.new'))
        ->assertInertia(fn(AssertableInertia $page) => $page->component('applications/ChooseServer')->where('servers', [])->where('can.createServer', true));
});

test('people who cannot add apps are refused', function () {
    $team = Team::factory()->owned()->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    $this->actingAs(addAppPerson($team, TeamRole::Member))->get(route('applications.new'))->assertForbidden();
});

test('the command palette offers Add an app and Create a project to people who can do them', function (TeamRole $role, bool $apps, bool $projects) {
    $team = Team::factory()->owned()->create();
    $commands = collect((new CommandPalette(addAppPerson($team, $role), $team))->commands())->keyBy('label');

    expect($commands->get('Add an app'))->toBe($apps ? ['label' => 'Add an app', 'icon' => 'plus', 'hint' => 'from a repository', 'href' => route('applications.new')] : null)
        ->and($commands->get('Create a project'))->toBe($projects ? ['label' => 'Create a project', 'icon' => 'folder', 'hint' => 'group apps and databases', 'href' => route('projects.index', ['create' => 1])] : null);
})->with([
    'an admin' => [TeamRole::Admin, true, true],
    'a developer' => [TeamRole::Developer, true, true],
    'a deployer' => [TeamRole::Member, false, false],
]);
