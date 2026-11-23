<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Models\MoveCode;
use App\Models\Team;
use App\Models\User;
use App\Services\Moves\MoveChecks;
use App\Services\Moves\MoveFile;
use App\Services\Moves\TeamImporter;
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

test('the cloud browser seed gives every browser a move file to bring in, and a team too big for the trial', function () {
    CloudBilling::enable();
    $this->seed(CloudE2ESeeder::class);

    foreach ([...array_values(CloudE2ESeeder::PROJECTS), 'big'] as $code) {
        $slug = $code === 'big' ? 'arriving-big' : "arriving-{$code}";
        $team = Team::query()->where('slug', $slug)->firstOrFail();
        $move = MoveCode::query()->where('team_id', $team->id)->sole();
        $payload = app(MoveFile::class)->open((string) $move->file, $move);
        $preview = app(TeamImporter::class)->preview($payload, $team, User::query()->findOrFail($team->owner_id));

        expect(app(MoveChecks::class)->problems($payload))->toBe([])
            ->and($team->servers()->count())->toBe(0)
            ->and($preview['plan'])->toBe($code === 'big' ? 'Arrivals big has 2 servers, and the trial includes 1. Choose a plan that fits in Billing, then move the team.' : null);
    }

    foreach (CloudE2ESeeder::PROJECTS as $code) {
        expect(Team::query()->where('slug', "fresh-{$code}")->firstOrFail()->servers()->count())->toBe(0);
    }
});
