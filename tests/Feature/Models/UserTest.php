<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('instance owners are users with the owner role on any team', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $nominal = User::factory()->create();
    $member = User::factory()->create();

    $first = Team::factory()->create();
    $second = Team::factory()->create(['owner_id' => $nominal->id]);
    $first->members()->attach([$owner->id => ['role' => TeamRole::Owner->value], $admin->id => ['role' => TeamRole::Admin->value]]);
    $second->members()->attach([$owner->id => ['role' => TeamRole::Member->value], $nominal->id => ['role' => TeamRole::Admin->value], $member->id => ['role' => TeamRole::Member->value]]);

    expect($owner->managesInstance())->toBeTrue()
        ->and($admin->managesInstance())->toBeFalse()
        ->and($nominal->managesInstance())->toBeFalse()
        ->and($member->managesInstance())->toBeFalse()
        ->and(User::query()->instanceOwners()->pluck('id')->all())->toBe([$owner->id]);
});
