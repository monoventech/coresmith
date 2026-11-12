<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

test('a project keeps its environments in order', function () {
    $project = Project::factory()->withEnvironments(['Production', 'Staging', 'Preview'])->create();

    expect($project->environments()->pluck('name')->all())->toBe(['Production', 'Staging', 'Preview'])
        ->and($project->team->projects()->sole()->is($project))->toBeTrue();
});

test('deleting an environment leaves its apps and databases in no project', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create();
    $staging = $project->environments()->where('name', 'Staging')->sole();
    $application = Application::factory()->for($team)->for($server)->create(['environment_id' => $staging->id]);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['environment_id' => $staging->id]);
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => User::factory()->create()->id, 'environment_id' => $staging->id]);

    $staging->delete();

    expect($application->fresh()->environment_id)->toBeNull()
        ->and($database->fresh()->environment_id)->toBeNull()
        ->and(AccessGrant::query()->count())->toBe(0)
        ->and($project->environments()->pluck('name')->all())->toBe(['Production']);
});

test('deleting a project removes its environments and the grants on them', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create();
    [$production, $staging] = $project->environments()->get()->all();
    $application = Application::factory()->for($team)->for($server)->create(['environment_id' => $production->id]);
    $user = User::factory()->create();
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'project_id' => $project->id]);
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'environment_id' => $staging->id]);
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'server_id' => $server->id]);

    $project->delete();

    expect(Environment::query()->count())->toBe(0)
        ->and($application->fresh()->environment_id)->toBeNull()
        ->and(AccessGrant::query()->pluck('server_id')->all())->toBe([$server->id]);
});

test('admins manage projects and deployers do not', function () {
    expect(TeamRole::Owner->can(Ability::ManageProjects))->toBeTrue()
        ->and(TeamRole::Admin->can(Ability::ManageProjects))->toBeTrue()
        ->and(TeamRole::Member->can(Ability::ManageProjects))->toBeFalse();
});
