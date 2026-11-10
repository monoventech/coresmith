<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\DeployKeyManager;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

function narrowedSettingsPerson(Team $team, TeamRole $role, bool $everything): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return $user->fresh();
}

test("a narrowed admin can't change or export what covers the whole team", function () {
    $team = Team::factory()->create();
    $admin = narrowedSettingsPerson($team, TeamRole::Admin, false);

    expect($admin->can('update', $team))->toBeFalse()
        ->and($admin->hasTeamAbility($team, Ability::ManageProviders))->toBeFalse()
        ->and($admin->hasTeamAbility($team, Ability::CreateServer))->toBeTrue();

    $this->actingAs($admin)->get(route('settings.audit-log'))->assertForbidden();
    $this->actingAs($admin)->put(route('settings.team-url.update'), ['slug' => 'elsewhere'])->assertForbidden();
    $this->actingAs($admin)->put(route('settings.offsite-backups.update'), [
        'region' => 'eu-central-1',
        'bucket' => 'their-own-bucket',
        'access_key_id' => 'AKIATHEIRS',
        'secret_access_key' => 'theirs',
    ])->assertForbidden();

    $this->actingAs($admin)->get(route('teams.index'))->assertInertia(fn(AssertableInertia $page) => $page
        ->where('can.update', false)
        ->where('cloud.can_manage', false));
});

test('an admin who sees everything keeps the settings that cover the whole team', function () {
    $team = Team::factory()->create();
    $admin = narrowedSettingsPerson($team, TeamRole::Admin, true);

    expect($admin->can('update', $team))->toBeTrue()
        ->and($admin->hasTeamAbility($team, Ability::ManageProviders))->toBeTrue();
});

test('Settings, Git lists deploy keys only for apps a narrowed member can see', function () {
    $team = Team::factory()->create();
    $web = Server::factory()->for($team)->create();
    $db = Server::factory()->for($team)->create();
    $keys = app(DeployKeyManager::class);
    $keys->ensure(Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git']));
    $keys->ensure(Application::factory()->for($team)->for($db)->create(['name' => 'api', 'repository_url' => 'git@github.com:acme/api.git']));
    $member = narrowedSettingsPerson($team, TeamRole::Member, false);
    app(TeamAccess::class)->grant($team, $member, $web);

    $this->actingAs($member)
        ->get(route('source-providers.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('deployKeys', fn($deployKeys): bool => collect($deployKeys)->pluck('name')->all() === ['shop']));
});
