<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, developer: User, other: User, server: Server}
 */
function developerProjectsSetup(): array
{
    $team = Team::factory()->owned()->create();
    $people = [];

    foreach (['developer', 'other'] as $key) {
        $people[$key] = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($people[$key], ['role' => TeamRole::Developer->value, 'sees_everything' => false]);
        $people[$key] = $people[$key]->fresh();
    }

    $server = Server::factory()->for($team)->create(['name' => 'devbox', 'created_by' => $people['developer']->id]);

    return ['team' => $team, 'owner' => $team->owner->fresh(), 'server' => $server, ...$people];
}

function developerProjectsCreate(User $developer, string $name): Project
{
    test()->actingAs($developer)->post(route('projects.store'), ['name' => $name])->assertSessionHasNoErrors();

    return Project::query()->where('name', $name)->sole();
}

function developerProjectsAccess(): TeamAccess
{
    app()->forgetInstance(TeamAccess::class);

    return app(TeamAccess::class);
}

test('a project a developer creates records it as the one who added it, and it manages the whole of it', function () {
    ['developer' => $developer] = developerProjectsSetup();
    $project = developerProjectsCreate($developer, 'Side project');

    expect($project->created_by)->toBe($developer->id)
        ->and(developerProjectsAccess()->seesWholeProject($developer, $project))->toBeTrue();

    $this->actingAs($developer)->put(route('projects.update', $project), ['name' => 'Side quest'])->assertSessionHasNoErrors();

    expect($project->fresh()->name)->toBe('Side quest');

    $this->actingAs($developer)->delete(route('projects.destroy', $project))->assertSessionHasNoErrors();

    expect(Project::query()->find($project->id))->toBeNull();
});

test('a developer keeps seeing the projects it created when an owner takes the share away', function () {
    ['developer' => $developer] = developerProjectsSetup();
    $project = developerProjectsCreate($developer, 'Side project');

    AccessGrant::query()->where('user_id', $developer->id)->delete();

    expect(developerProjectsAccess()->canSeeProject($developer, $project))->toBeTrue()
        ->and(developerProjectsAccess()->seesWholeProject($developer, $project))->toBeTrue()
        ->and(developerProjectsAccess()->projects($developer, $project->team)->pluck('name')->all())->toBe(['Side project']);
});

test('a developer cannot rename or delete a project shared with it, but adds its own apps to it', function () {
    ['team' => $team, 'owner' => $owner, 'developer' => $developer, 'server' => $server] = developerProjectsSetup();
    $project = Project::factory()->for($team)->create(['name' => 'Storefront', 'created_by' => $owner->id]);
    $mine = Application::factory()->for($team)->for($server)->create(['name' => 'notes', 'created_by' => $developer->id]);
    developerProjectsAccess()->grant($team, $developer, $project);

    $this->actingAs($developer)->put(route('projects.update', $project), ['name' => 'Mine now'])->assertForbidden();
    $this->actingAs($developer)->delete(route('projects.destroy', $project))->assertForbidden();
    $this->actingAs($developer)->post(route('projects.contents.store', $project), ['applications' => [$mine->id]])->assertSessionHasNoErrors();

    expect($project->fresh()->name)->toBe('Storefront')
        ->and($mine->fresh()->project_id)->toBe($project->id);
});

test("another developer never sees a developer's project unless it is shared", function () {
    ['developer' => $developer, 'other' => $other] = developerProjectsSetup();
    $project = developerProjectsCreate($developer, 'Side project');

    expect(developerProjectsAccess()->projects($other, $project->team)->pluck('name')->all())->toBe([]);

    $this->actingAs($other)->get(route('projects.show', $project))->assertNotFound();
});

test('a developer files only the apps and databases it added', function () {
    ['team' => $team, 'owner' => $owner, 'developer' => $developer, 'server' => $server] = developerProjectsSetup();
    $project = developerProjectsCreate($developer, 'Side project');
    $mine = Application::factory()->for($team)->for($server)->create(['name' => 'notes', 'created_by' => $developer->id]);
    $mineDb = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'notes_db', 'created_by' => $developer->id]);
    $theirs = Application::factory()->for($team)->for($server)->create(['name' => 'miagraph', 'created_by' => $owner->id]);
    developerProjectsAccess()->grant($team, $developer, $theirs);

    $this->actingAs($developer)->post(route('projects.contents.store', $project), ['applications' => [$theirs->id]])
        ->assertSessionHasErrors(['applications' => 'You can only move apps you added. Reload the page and choose again.']);
    $this->actingAs($developer)->post(route('projects.contents.store', $project), ['applications' => [$mine->id], 'databases' => [$mineDb->id]])
        ->assertSessionHasNoErrors();

    expect($theirs->fresh()->project_id)->toBeNull()
        ->and($mine->fresh()->project_id)->toBe($project->id)
        ->and($mineDb->fresh()->project_id)->toBe($project->id);
});

test('a developer cannot take an app it did not add out of a project', function () {
    ['team' => $team, 'owner' => $owner, 'developer' => $developer, 'server' => $server] = developerProjectsSetup();
    $project = Project::factory()->for($team)->create(['name' => 'Storefront', 'created_by' => $owner->id]);
    $theirs = Application::factory()->for($team)->for($server)->create(['name' => 'miagraph', 'created_by' => $owner->id, 'project_id' => $project->id]);
    developerProjectsAccess()->grant($team, $developer, $project);

    $this->actingAs($developer)->delete(route('projects.applications.destroy', [$project, $theirs]))->assertForbidden();

    expect($theirs->fresh()->project_id)->toBe($project->id);
});

test('the add list in a project offers a developer only what it added', function () {
    ['team' => $team, 'owner' => $owner, 'developer' => $developer, 'server' => $server] = developerProjectsSetup();
    $project = developerProjectsCreate($developer, 'Side project');
    Application::factory()->for($team)->for($server)->create(['name' => 'notes', 'created_by' => $developer->id]);
    $theirs = Application::factory()->for($team)->for($server)->create(['name' => 'miagraph', 'created_by' => $owner->id]);
    developerProjectsAccess()->grant($team, $developer, $theirs);

    $this->actingAs($developer)->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('candidates.applications', fn($apps): bool => collect($apps)->pluck('name')->all() === ['notes']));
});
