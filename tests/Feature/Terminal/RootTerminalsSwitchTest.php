<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function rootSwitchUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return $user->fresh();
}

test('an owner turns root terminals off and on again, and each change is recorded', function () {
    $team = Team::factory()->create();
    $owner = rootSwitchUser($team, TeamRole::Owner);

    $this->actingAs($owner)->put(route('settings.root-terminals.update'), ['root_terminals' => false])->assertRedirect()->assertSessionHas('success', 'Saved.');
    expect($team->fresh()->root_terminals)->toBeFalse();

    $this->actingAs($owner)->put(route('settings.root-terminals.update'), ['root_terminals' => true])->assertRedirect();
    expect($team->fresh()->root_terminals)->toBeTrue()
        ->and(Activity::query()->where('subject_id', $team->id)->pluck('description')->all())->toBe(['root_terminals_off', 'root_terminals_on']);
});

test('only an owner can change root terminals', function (TeamRole $role) {
    $team = Team::factory()->create();

    $this->actingAs(rootSwitchUser($team, $role))->put(route('settings.root-terminals.update'), ['root_terminals' => false])->assertForbidden();

    expect($team->fresh()->root_terminals)->toBeTrue();
})->with([TeamRole::Admin, TeamRole::Developer, TeamRole::Member]);

test('the switch needs a yes or a no', function () {
    $team = Team::factory()->create();

    $this->actingAs(rootSwitchUser($team, TeamRole::Owner))->put(route('settings.root-terminals.update'), [])->assertSessionHasErrors('root_terminals');
});

test('the Team tab shows the switch, and lets only an owner change it', function (TeamRole $role, bool $canChange) {
    $team = Team::factory()->create(['root_terminals' => false]);

    $this->actingAs(rootSwitchUser($team, $role))
        ->get(route('teams.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('terminals', ['root' => false, 'can_change' => $canChange]));
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, false],
]);
