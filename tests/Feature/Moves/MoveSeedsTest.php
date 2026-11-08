<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Models\Team;
use Database\Seeders\CloudE2ESeeder;
use Tests\Support\CloudBilling;

test('the cloud browser seed gives every browser a team to move and one that is already moving', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    foreach (CloudE2ESeeder::PROJECTS as $code) {
        $move = Team::query()->where('slug', "move-{$code}")->firstOrFail();
        $moving = Team::query()->where('slug', "moving-{$code}")->firstOrFail();

        expect($move->moving_at)->toBeNull()
            ->and($move->servers()->pluck('status')->all())->toBe([ServerStatus::Active])
            ->and($move->applications()->count())->toBe(1)
            ->and($moving->moving_to)->toBe('https://panel.example.com')
            ->and($moving->servers()->pluck('status')->all())->toBe([ServerStatus::Moving]);
    }

    expect(Team::query()->where('slug', 'moved-away')->firstOrFail()->moving_at)->not->toBeNull();
});
