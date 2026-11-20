<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a signed-in visit records when someone was last active, at most every 10 minutes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
    $team = Team::factory()->owned()->create();
    $user = User::query()->findOrFail($team->owner_id);

    expect($user->last_active_at)->toBeNull();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->last_active_at?->toDateTimeString())->toBe('2026-11-20 09:00:00');

    $this->travel(9)->minutes();
    $this->get(route('dashboard'));

    expect($user->fresh()->last_active_at?->toDateTimeString())->toBe('2026-11-20 09:00:00');

    $this->travel(2)->minutes();
    $this->get(route('dashboard'));

    expect($user->fresh()->last_active_at?->toDateTimeString())->toBe('2026-11-20 09:11:00');
});

test('recording activity leaves the rest of the account alone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
    $team = Team::factory()->owned()->create();
    $user = User::query()->findOrFail($team->owner_id);
    $updated = $user->updated_at?->toDateTimeString();

    $this->travel(1)->hours();
    $this->actingAs($user)->get(route('dashboard'));

    expect($user->fresh()->updated_at?->toDateTimeString())->toBe($updated);
});
