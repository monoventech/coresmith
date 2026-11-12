<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{0: Team, 1: User, 2: Project}
 */
function environmentControllerFixture(): array
{
    $team = Team::factory()->owned()->create();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);

    return [$team, $team->owner->fresh(), $project];
}

test('a new environment goes last', function () {
    [, $owner, $project] = environmentControllerFixture();

    $this->actingAs($owner)->post(route('projects.environments.store', $project), ['name' => ' Preview '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Added Preview.');

    expect($project->environments()->pluck('name')->all())->toBe(['Production', 'Staging', 'Preview'])
        ->and(Activity::query()->where('description', 'environment_created')->sole()->properties->all())->toEqual(['project' => 'Storefront', 'environment' => 'Preview']);
});

test('environment names are unique in a project whatever their case', function () {
    [, $owner, $project] = environmentControllerFixture();
    Project::factory()->withEnvironments(['Preview'])->create();

    $this->actingAs($owner)->post(route('projects.environments.store', $project), ['name' => 'staging'])
        ->assertSessionHasErrors(['name' => 'Storefront already has an environment called Staging.']);
    $this->actingAs($owner)->post(route('projects.environments.store', $project), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Name the environment.']);
    $this->actingAs($owner)->post(route('projects.environments.store', $project), ['name' => str_repeat('a', 33)])
        ->assertSessionHasErrors(['name' => 'Keep the name to 32 characters.']);
    $this->actingAs($owner)->post(route('projects.environments.store', $project), ['name' => 'Preview'])->assertSessionHasNoErrors();
});

test('renaming an environment says Saved.', function () {
    [, $owner, $project] = environmentControllerFixture();
    $staging = $project->environments()->where('name', 'Staging')->sole();

    $this->actingAs($owner)->put(route('projects.environments.update', [$project, $staging]), ['name' => 'Production'])
        ->assertSessionHasErrors(['name' => 'Storefront already has an environment called Production.']);
    $this->actingAs($owner)->put(route('projects.environments.update', [$project, $staging]), ['name' => 'QA'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved.');

    expect($staging->fresh()->name)->toBe('QA');
});

test('deleting an environment leaves what was in it running in no project', function () {
    [$team, $owner, $project] = environmentControllerFixture();
    $staging = $project->environments()->where('name', 'Staging')->sole();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['environment_id' => $staging->id]);

    $this->actingAs($owner)->delete(route('projects.environments.destroy', [$project, $staging]))
        ->assertSessionHas('success', 'Deleted Staging. What was in it keeps running, under No project.');

    expect($project->environments()->pluck('name')->all())->toBe(['Production'])
        ->and($application->fresh()->environment_id)->toBeNull();
});

test('deleting the last environment is refused', function () {
    [, $owner] = environmentControllerFixture();
    $solo = Project::factory()->for(User::query()->find($owner->id)->teams()->first())->withEnvironments(['Production'])->create(['name' => 'Solo']);

    $this->actingAs($owner)->delete(route('projects.environments.destroy', [$solo, $solo->environments()->sole()]))
        ->assertSessionHas('error', 'A project needs at least one environment. Delete the project instead.');

    expect($solo->environments()->count())->toBe(1);
});

test("another project's environment is not found here", function () {
    [$team, $owner, $project] = environmentControllerFixture();
    $other = Project::factory()->for($team)->withEnvironments(['Production'])->create(['name' => 'Blog']);

    $this->actingAs($owner)->put(route('projects.environments.update', [$project, $other->environments()->sole()]), ['name' => 'QA'])->assertNotFound();
});
