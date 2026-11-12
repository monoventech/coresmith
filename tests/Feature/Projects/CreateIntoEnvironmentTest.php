<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DatabaseType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, server: Server, project: Project, production: Environment, staging: Environment}
 */
function createIntoFixture(): array
{
    $team = Team::factory()->owned()->create();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    [$production, $staging] = $project->environments()->get()->all();

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'server' => Server::factory()->for($team)->create(['name' => 'web']),
        'project' => $project,
        'production' => $production,
        'staging' => $staging,
    ];
}

/**
 * @param  array<string, mixed>  $extra
 */
function createIntoAppPost(User $user, Server $server, array $extra = [])
{
    return test()->actingAs($user)->post(route('servers.applications.store', $server), [
        'name' => 'Shop',
        'type' => ApplicationType::Php->value,
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
        ...$extra,
    ]);
}

test('an app created into an environment shows there, and its page names the project', function () {
    ['owner' => $owner, 'server' => $server, 'project' => $project, 'staging' => $staging] = createIntoFixture();

    createIntoAppPost($owner, $server, ['environment_id' => $staging->id])->assertSessionHasNoErrors();

    $application = Application::query()->sole();

    expect($application->environment_id)->toBe($staging->id);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $application]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('place', ['label' => 'Storefront › Staging', 'href' => route('projects.show', $project)]));
});

test('an app without an environment starts in no project', function () {
    ['owner' => $owner, 'server' => $server] = createIntoFixture();

    createIntoAppPost($owner, $server, ['environment_id' => ''])->assertSessionHasNoErrors();

    $application = Application::query()->sole();

    expect($application->environment_id)->toBeNull();

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $application]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('place', null));
});

test("an environment from another team, or one the member can't fill, is refused", function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'production' => $production, 'staging' => $staging] = createIntoFixture();
    $elsewhere = Project::factory()->withEnvironments(['Production'])->create()->environments()->sole();
    $admin = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($team, $admin, $server);
    app(TeamAccess::class)->grant($team, $admin, $staging);

    createIntoAppPost($owner, $server, ['environment_id' => $elsewhere->id])
        ->assertSessionHasErrors(['environment_id' => 'Pick a project environment you can see, or No project.']);
    createIntoAppPost($admin, $server, ['environment_id' => $production->id])
        ->assertSessionHasErrors(['environment_id' => 'Pick a project environment you can see, or No project.']);
    createIntoAppPost($admin, $server, ['environment_id' => $staging->id])->assertSessionHasNoErrors();

    expect(Application::query()->sole()->environment_id)->toBe($staging->id);
});

test('a database created into an environment shows there, and its page names the project', function () {
    Queue::fake();
    ['owner' => $owner, 'server' => $server, 'project' => $project, 'production' => $production] = createIntoFixture();

    $this->actingAs($owner)->post(route('servers.databases.store', $server), [
        'type' => DatabaseType::Mysql->value,
        'name' => 'shop_db',
        'db_user' => 'shop_user',
        'privilege_level' => 'standard',
        'environment_id' => $production->id,
    ])->assertSessionHasNoErrors();

    $database = ManagedDatabase::query()->sole();

    expect($database->environment_id)->toBe($production->id);

    $this->actingAs($owner)->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('place', ['label' => 'Storefront › Production', 'href' => route('projects.show', $project)]));
});

test('the create pages offer the environments the member can fill', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'project' => $project, 'production' => $production, 'staging' => $staging] = createIntoFixture();
    $admin = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($team, $admin, $server);
    app(TeamAccess::class)->grant($team, $admin, $staging);

    $everything = [['id' => $project->id, 'name' => 'Storefront', 'environments' => [['id' => $production->id, 'name' => 'Production'], ['id' => $staging->id, 'name' => 'Staging']]]];

    $this->actingAs($owner)->get(route('servers.applications.create', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('projects', $everything));
    $this->actingAs($owner)->get(route('servers.databases.create', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('projects', $everything));
    $this->actingAs($admin)->get(route('servers.applications.create', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('projects', [['id' => $project->id, 'name' => 'Storefront', 'environments' => [['id' => $staging->id, 'name' => 'Staging']]]]));
});
