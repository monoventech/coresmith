<?php

declare(strict_types=1);

use App\Enums\BillingState;
use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamBilling;
use Database\Seeders\CloudE2ESeeder;
use Tests\Support\CloudBilling;

test('the cloud browser seed has a paying team and a team on its trial', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $monoven = Team::query()->where('slug', 'monoven')->firstOrFail();
    $trial = Team::query()->where('slug', 'trial')->firstOrFail();
    $owner = User::query()->where('email', CloudE2ESeeder::OWNER_EMAIL)->firstOrFail();
    $paying = app(TeamBilling::class)->status($monoven);
    $trying = app(TeamBilling::class)->status($trial);

    expect($paying->state)->toBe(BillingState::Active)
        ->and($paying->plan)->toBe(Plan::Pro)
        ->and($monoven->pm_last_four)->toBe('4242')
        ->and($monoven->pm_expires)->toBe('08/28')
        ->and($trying->state)->toBe(BillingState::Trialing)
        ->and($trying->plan)->toBe(Plan::Trial)
        ->and($trying->trialDaysLeft())->toBe(9)
        ->and($trial->servers()->pluck('name')->all())->toBe(['forge-trial-01'])
        ->and($owner->teams()->pluck('slug')->sort()->values()->all())->toBe(['monoven', 'trial'])
        ->and($owner->current_team_id)->toBe($monoven->id);
});
