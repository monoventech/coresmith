<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;

/**
 * @return array{team: Team, owner: User, developer: User, other: User, own: SourceProvider, team_connection: SourceProvider}
 */
function ownGitHubSetup(): array
{
    $team = Team::factory()->create();
    $people = [];

    foreach (['owner' => TeamRole::Owner, 'developer' => TeamRole::Developer, 'other' => TeamRole::Developer] as $key => $role) {
        $people[$key] = User::factory()->create();
        $team->members()->attach($people[$key], ['role' => $role->value, 'sees_everything' => $role === TeamRole::Owner]);
        $people[$key] = $people[$key]->fresh();
    }

    $app = GitHubApp::factory()->create(['team_id' => $team->id, 'user_id' => $people['developer']->id]);
    $own = SourceProvider::factory()->githubApp($app)->create(['team_id' => $team->id, 'user_id' => $people['developer']->id, 'name' => 'lena-b']);
    $teamConnection = SourceProvider::factory()->githubApp(GitHubApp::factory()->create(['team_id' => $team->id]))->create(['team_id' => $team->id, 'name' => 'monoventech']);

    return ['team' => $team, 'own' => $own, 'team_connection' => $teamConnection, ...$people];
}

test("a member's own GitHub connection is used only by that member, whatever the others' roles", function () {
    ['team' => $team, 'owner' => $owner, 'developer' => $developer, 'other' => $other, 'own' => $own, 'team_connection' => $teamConnection] = ownGitHubSetup();

    expect([$own->usableBy($developer, $team), $own->usableBy($owner, $team), $own->usableBy($other, $team)])->toBe([true, false, false])
        ->and([$teamConnection->usableBy($owner, $team), $teamConnection->usableBy($developer, $team)])->toBe([true, false]);
});

test("a member's own connection is not theirs in another team", function () {
    ['developer' => $developer, 'own' => $own] = ownGitHubSetup();
    $elsewhere = Team::factory()->create();
    $elsewhere->members()->attach($developer, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);

    expect($own->usableBy($developer, $elsewhere))->toBeFalse();
});
