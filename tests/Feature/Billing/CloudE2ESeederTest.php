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
        ->and($owner->teams()->pluck('slug')->sort()->values()->all())->toBe(['ending', 'expired', 'monoven', 'overdue', 'trial'])
        ->and($owner->current_team_id)->toBe($monoven->id);
});

test('the cloud browser seed has a lapsed team, one about to lapse and one that cannot pay', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $status = fn(string $slug) => app(TeamBilling::class)->status(Team::query()->where('slug', $slug)->firstOrFail());
    $expired = Team::query()->where('slug', 'expired')->firstOrFail();
    $member = User::query()->where('email', CloudE2ESeeder::MEMBER_EMAIL)->firstOrFail();

    expect($status('expired')->readOnly())->toBeTrue()
        ->and($expired->applications()->pluck('name')->all())->toBe(['lapsed-app'])
        ->and($expired->servers()->pluck('name')->all())->toBe(['lapsed-01'])
        ->and($expired->members()->whereKey($member->id)->first()?->pivot->role)->toBe('member')
        ->and($status('ending')->trialDaysLeft())->toBe(2)
        ->and($status('overdue')->state)->toBe(BillingState::PastDue)
        ->and(Team::query()->where('slug', 'overdue')->firstOrFail()->billingInvoices()->where('status', 'open')->count())->toBe(1);
});
