<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function teamNameMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('an owner or admin renames the team, and the rename is recorded', function (TeamRole $role) {
    $team = Team::factory()->create(['name' => 'Coresmith']);

    $this->actingAs(teamNameMember($team, $role))
        ->put(route('settings.team-name.update'), ['name' => 'Monoven Ops'])
        ->assertSessionHas('success', 'Saved.');

    $activity = Activity::query()->where('description', 'team_renamed')->sole();

    expect($team->fresh()->name)->toBe('Monoven Ops')
        ->and($activity->properties->toArray())->toBe(['from' => 'Coresmith', 'to' => 'Monoven Ops']);
})->with([TeamRole::Owner, TeamRole::Admin]);

test('saving the same name changes nothing and records nothing, and a blank name says what to do', function () {
    $team = Team::factory()->create(['name' => 'Coresmith']);
    $owner = teamNameMember($team, TeamRole::Owner);

    $this->actingAs($owner)->put(route('settings.team-name.update'), ['name' => 'Coresmith'])->assertSessionHas('success', 'Saved.');
    $this->actingAs($owner)->put(route('settings.team-name.update'), ['name' => '   '])->assertSessionHasErrors(['name' => 'Give the team a name.']);

    expect($team->fresh()->name)->toBe('Coresmith')
        ->and(Activity::query()->where('description', 'team_renamed')->count())->toBe(0);
});

test('a deployer or developer cannot rename the team', function (TeamRole $role) {
    $team = Team::factory()->create(['name' => 'Coresmith']);

    $this->actingAs(teamNameMember($team, $role))->put(route('settings.team-name.update'), ['name' => 'Taken over'])->assertForbidden();

    expect($team->fresh()->name)->toBe('Coresmith');
})->with([TeamRole::Member, TeamRole::Developer]);
