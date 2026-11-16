<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

test('a project groups the apps and databases filed in it', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $project = Project::factory()->for($team)->create();
    $shop = Application::factory()->for($team)->for($server)->create(['project_id' => $project->id]);
    Application::factory()->for($team)->for($server)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['project_id' => $project->id]);

    expect($project->applications()->pluck('id')->all())->toBe([$shop->id])
        ->and($project->databases()->pluck('id')->all())->toBe([$database->id])
        ->and($shop->project->is($project))->toBeTrue()
        ->and($project->team->projects()->sole()->is($project))->toBeTrue();
});

test('deleting a project leaves what was in it in no project, removed apps included, and drops only its own grants', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $project = Project::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['project_id' => $project->id]);
    $removed = Application::factory()->for($team)->for($server)->create(['project_id' => $project->id]);
    $removed->delete();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['project_id' => $project->id]);
    $user = User::factory()->create();
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'project_id' => $project->id]);
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'server_id' => $server->id]);

    $project->delete();

    expect($application->fresh()->project_id)->toBeNull()
        ->and(Application::withTrashed()->find($removed->id)->project_id)->toBeNull()
        ->and($database->fresh()->project_id)->toBeNull()
        ->and(AccessGrant::query()->pluck('server_id')->all())->toBe([$server->id]);
});

test('admins manage projects and deployers do not', function () {
    expect(TeamRole::Owner->can(Ability::ManageProjects))->toBeTrue()
        ->and(TeamRole::Admin->can(Ability::ManageProjects))->toBeTrue()
        ->and(TeamRole::Member->can(Ability::ManageProjects))->toBeFalse();
});
