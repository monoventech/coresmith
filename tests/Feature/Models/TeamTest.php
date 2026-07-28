<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('a team belongs to an owner', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->for($owner, 'owner')->create();

    expect($team->owner->id)->toBe($owner->id);
});

test('a team has members with roles', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Admin->value]);

    expect($team->members)->toHaveCount(1)
        ->and($team->members->first()->pivot->role)->toBe('admin');
});

test('a user can belong to many teams', function () {
    $user = User::factory()->create();
    Team::factory()->count(2)->create()->each(fn (Team $t) => $t->members()->attach($user, ['role' => 'member']));

    expect($user->teams)->toHaveCount(2);
});

test('a team uses a ulid primary key', function () {
    expect(Team::factory()->create()->id)->toHaveLength(26);
});

test('a team is soft deleted', function () {
    $team = Team::factory()->create();
    $team->delete();

    expect(Team::withTrashed()->find($team->id))->not->toBeNull()
        ->and(Team::find($team->id))->toBeNull();
});
