<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;

/**
 * @return array<string, string>
 */
function projectApiHeaders(User $user, Team $team): array
{
    $token = $user->createToken('projects', [Ability::ViewApplication->value]);
    ApiToken::whereKey($token->accessToken->getKey())->update(['team_id' => $team->getKey()]);

    return ['Authorization' => 'Bearer ' . $token->plainTextToken, 'Accept' => 'application/json'];
}

/**
 * @return array{team: Team, owner: User, project: Project, shop: Application}
 */
function projectApiFleet(): array
{
    $team = Team::factory()->owned()->create();
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    $shop = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['name' => 'shop', 'project_id' => $project->id, 'tags' => ['production']]);

    return ['team' => $team, 'owner' => $team->owner->fresh(), 'project' => $project, 'shop' => $shop];
}

test('the API lists the projects a token can see, with the apps and databases in them it can see', function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project, 'shop' => $shop] = projectApiFleet();
    $preview = Application::factory()->for($team)->for($shop->server)->create(['name' => 'shop-preview', 'project_id' => $project->id]);
    $database = ManagedDatabase::factory()->for($team)->for($shop->server)->create(['name' => 'shop_db', 'project_id' => $project->id]);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);
    app(TeamAccess::class)->sync($team, $member, false, [], [], [$project->id], [], [$shop->id], [$database->id]);

    $this->withHeaders(projectApiHeaders($owner, $team))->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Storefront')
        ->assertJsonPath('data.0.applications', [['id' => $shop->id, 'name' => 'shop'], ['id' => $preview->id, 'name' => 'shop-preview']])
        ->assertJsonPath('data.0.databases', [['id' => $database->id, 'name' => 'shop_db']])
        ->assertJsonMissingPath('data.0.environments')
        ->assertJsonPath('meta.total', 1);

    app('auth')->forgetGuards();

    $this->withHeaders(projectApiHeaders($member, $team))->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.applications', [['id' => $preview->id, 'name' => 'shop-preview']])
        ->assertJsonPath('data.0.databases', []);
});

test('a project the token cannot see is not found', function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project] = projectApiFleet();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);

    $this->withHeaders(projectApiHeaders($member, $team))->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    $this->withHeaders(projectApiHeaders($member, $team))->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data', []);

    app('auth')->forgetGuards();

    $this->withHeaders(projectApiHeaders($owner, $team))->getJson("/api/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.id', $project->id);
});

test("an app's payload says which project it belongs to and carries its tags", function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project, 'shop' => $shop] = projectApiFleet();

    $this->withHeaders(projectApiHeaders($owner, $team))->getJson("/api/v1/applications/{$shop->id}")
        ->assertOk()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.tags', ['production'])
        ->assertJsonMissingPath('data.environment_id');
});
