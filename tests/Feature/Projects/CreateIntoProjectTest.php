<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DatabaseType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, server: Server, project: Project, other: Project}
 */
function createIntoFixture(): array
{
    $team = Team::factory()->owned()->create();

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'server' => Server::factory()->for($team)->create(['name' => 'web']),
        'project' => Project::factory()->for($team)->create(['name' => 'Storefront']),
        'other' => Project::factory()->for($team)->create(['name' => 'Atlas']),
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

function createIntoAdmin(Team $team, Server $server, Project $project): User
{
    $admin = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($team, $admin, $server);
    app(TeamAccess::class)->grant($team, $admin, $project);

    return $admin->fresh();
}

test('an app created into a project shows there with its tags, and its page names the project', function () {
    ['owner' => $owner, 'server' => $server, 'project' => $project] = createIntoFixture();

    createIntoAppPost($owner, $server, ['project_id' => $project->id, 'tags' => ['Production', ' eu ', 'production', '']])->assertSessionHasNoErrors();

    $application = Application::query()->sole();

    expect($application->project_id)->toBe($project->id)
        ->and($application->tags)->toBe(['Production', 'eu']);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $application]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('place', ['label' => 'Storefront', 'href' => route('projects.show', $project)])
            ->where('application.tags', ['Production', 'eu']));
});

test('an app without a project starts in no project, with no tags', function () {
    ['owner' => $owner, 'server' => $server] = createIntoFixture();

    createIntoAppPost($owner, $server, ['project_id' => ''])->assertSessionHasNoErrors();

    $application = Application::query()->sole();

    expect($application->project_id)->toBeNull()
        ->and($application->tags)->toBe([]);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $application]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('place', null));
});

test("a project from another team, or one the member can't add to, is refused", function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'project' => $project, 'other' => $other] = createIntoFixture();
    $elsewhere = Project::factory()->create();
    $admin = createIntoAdmin($team, $server, $project);

    createIntoAppPost($owner, $server, ['project_id' => $elsewhere->id])
        ->assertSessionHasErrors(['project_id' => 'Pick a project you can add to, or No project.']);
    createIntoAppPost($admin, $server, ['project_id' => $other->id])
        ->assertSessionHasErrors(['project_id' => 'Pick a project you can add to, or No project.']);
    createIntoAppPost($admin, $server, ['project_id' => $project->id])->assertSessionHasNoErrors();

    expect(Application::query()->sole()->project_id)->toBe($project->id);
});

test('tags in Add an app are kept short and few', function () {
    ['owner' => $owner, 'server' => $server] = createIntoFixture();

    createIntoAppPost($owner, $server, ['tags' => ['a', 'b', 'c', 'd', 'e', 'f']])
        ->assertSessionHasErrors(['tags' => 'An app can have up to 5 tags.']);
    createIntoAppPost($owner, $server, ['tags' => [str_repeat('x', 25)]])
        ->assertSessionHasErrors(['tags.0' => 'Keep each tag to 24 characters or fewer.']);
    createIntoAppPost($owner, $server, ['tags' => ['-staging']])
        ->assertSessionHasErrors(['tags.0' => 'Start each tag with a letter or number, then use letters, numbers, spaces, dots, dashes or underscores.']);

    expect(Application::query()->count())->toBe(0);
});

test('a database created into a project shows there, and its page names the project', function () {
    Queue::fake();
    ['owner' => $owner, 'server' => $server, 'project' => $project] = createIntoFixture();

    $this->actingAs($owner)->post(route('servers.databases.store', $server), [
        'type' => DatabaseType::Mysql->value,
        'name' => 'shop_db',
        'db_user' => 'shop_user',
        'privilege_level' => 'standard',
        'project_id' => $project->id,
    ])->assertSessionHasNoErrors();

    $database = ManagedDatabase::query()->sole();

    expect($database->project_id)->toBe($project->id);

    $this->actingAs($owner)->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('place', ['label' => 'Storefront', 'href' => route('projects.show', $project)]));
});

test('the create pages offer the projects the member can add to, and Add an app picks the one in the address', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'project' => $project, 'other' => $other] = createIntoFixture();
    $admin = createIntoAdmin($team, $server, $project);
    $everything = [['id' => $other->id, 'name' => 'Atlas'], ['id' => $project->id, 'name' => 'Storefront']];

    $this->actingAs($owner)->get(route('servers.applications.create', [$server, 'project' => $project->id]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('projects', $everything)->where('project', $project->id));
    $this->actingAs($owner)->get(route('servers.databases.create', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('projects', $everything));
    $this->actingAs($admin)->get(route('servers.applications.create', [$server, 'project' => $other->id]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('projects', [['id' => $project->id, 'name' => 'Storefront']])->where('project', null));
});
