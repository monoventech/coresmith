<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{0: Team, 1: User}
 */
function projectControllerTeam(): array
{
    $team = Team::factory()->owned()->create();

    return [$team, $team->owner->fresh()];
}

function projectControllerPerson(Team $team, TeamRole $role, bool $everything = true): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

test('an owner creates a project that starts in Production', function () {
    [$team, $owner] = projectControllerTeam();

    $response = $this->actingAs($owner)->post(route('projects.store'), ['name' => '  Storefront ']);

    $project = Project::query()->sole();

    $response->assertRedirect(route('projects.show', $project))->assertSessionHas('success', 'Created Storefront.');

    expect($project->name)->toBe('Storefront')
        ->and($project->team_id)->toBe($team->id)
        ->and($project->environments()->pluck('name')->all())->toBe(['Production'])
        ->and(Activity::query()->where('description', 'project_created')->sole()->properties->all())->toBe(['project' => 'Storefront']);
});

test('project names are unique in a team whatever their case', function () {
    [$team, $owner] = projectControllerTeam();
    Project::factory()->for($team)->withEnvironments()->create(['name' => 'Storefront']);
    Project::factory()->withEnvironments()->create(['name' => 'Blog']);

    $this->actingAs($owner)->post(route('projects.store'), ['name' => 'storefront'])
        ->assertSessionHasErrors(['name' => "There's already a project called Storefront."]);
    $this->actingAs($owner)->post(route('projects.store'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Name the project.']);
    $this->actingAs($owner)->post(route('projects.store'), ['name' => str_repeat('a', 65)])
        ->assertSessionHasErrors(['name' => 'Keep the name to 64 characters.']);
    $this->actingAs($owner)->post(route('projects.store'), ['name' => 'Blog'])->assertSessionHasNoErrors();

    expect(Project::query()->where('team_id', $team->id)->count())->toBe(2);
});

test('a narrowed admin who creates a project sees all of it', function () {
    [$team] = projectControllerTeam();
    $admin = projectControllerPerson($team, TeamRole::Admin, everything: false);

    $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Launch'])->assertSessionHasNoErrors();

    expect(app(TeamAccess::class)->seesWholeProject($admin, Project::query()->sole()))->toBeTrue();
});

test('deployers cannot create projects', function () {
    [$team] = projectControllerTeam();
    $deployer = projectControllerPerson($team, TeamRole::Member);

    $this->actingAs($deployer)->post(route('projects.store'), ['name' => 'Launch'])->assertForbidden();

    expect(Project::query()->count())->toBe(0);
});

test('renaming a project keeps names unique and says Saved.', function () {
    [$team, $owner] = projectControllerTeam();
    $storefront = Project::factory()->for($team)->withEnvironments()->create(['name' => 'Storefront']);
    Project::factory()->for($team)->withEnvironments()->create(['name' => 'Blog']);

    $this->actingAs($owner)->put(route('projects.update', $storefront), ['name' => 'BLOG'])
        ->assertSessionHasErrors(['name' => "There's already a project called Blog."]);
    $this->actingAs($owner)->put(route('projects.update', $storefront), ['name' => 'StoreFront'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved.');

    expect($storefront->fresh()->name)->toBe('StoreFront')
        ->and(Activity::query()->where('description', 'project_renamed')->sole()->properties->all())->toEqual(['from' => 'Storefront', 'project' => 'StoreFront']);
});

test('deleting a project leaves its apps running in no project', function () {
    [$team, $owner] = projectControllerTeam();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['environment_id' => $project->environments()->first()->id]);

    $this->actingAs($owner)->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'))
        ->assertSessionHas('success', 'Deleted Storefront. What was in it keeps running, under No project.');

    expect(Project::query()->count())->toBe(0)
        ->and($application->fresh()->environment_id)->toBeNull()
        ->and(Activity::query()->where('description', 'project_deleted')->sole()->properties->all())->toBe(['project' => 'Storefront']);
});

test('an environment-only member cannot rename or delete the project, and others never find it', function () {
    [$team] = projectControllerTeam();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    $admin = projectControllerPerson($team, TeamRole::Admin, everything: false);
    $stranger = projectControllerPerson($team, TeamRole::Admin, everything: false);
    app(TeamAccess::class)->grant($team, $admin, $project->environments()->where('name', 'Staging')->sole());

    $this->actingAs($admin)->put(route('projects.update', $project), ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($admin)->delete(route('projects.destroy', $project))->assertForbidden();
    $this->actingAs($stranger)->put(route('projects.update', $project), ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($stranger)->delete(route('projects.destroy', $project))->assertNotFound();

    expect($project->fresh()->name)->toBe('Storefront');
});
