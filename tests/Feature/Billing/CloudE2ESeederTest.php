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
        ->and($owner->teams()->pluck('slug')->reject(fn(string $slug): bool => str_contains($slug, '-'))->sort()->values()->all())->toBe(['ending', 'expired', 'fleet', 'monoven', 'overdue', 'solo', 'trial'])
        ->and($owner->teams()->count())->toBe(7 + 4 * count(CloudE2ESeeder::PROJECTS))
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

test('the cloud browser seed has a full starter team', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $solo = Team::query()->where('slug', 'solo')->firstOrFail();
    $status = app(TeamBilling::class)->status($solo);

    expect($status->plan)->toBe(Plan::Starter)
        ->and($status->serverLimit())->toBe(3)
        ->and($solo->servers()->count())->toBe(3)
        ->and($solo->members()->count())->toBe(1);
});

test('the cloud browser seed gives monoven four paid invoices', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $monoven = Team::query()->where('slug', 'monoven')->firstOrFail();

    expect($monoven->billingInvoices()->pluck('number')->all())->toBe(['MNV-0004', 'MNV-0003', 'MNV-0002', 'MNV-0001'])
        ->and($monoven->billingInvoices()->where('status', 'paid')->count())->toBe(4);
});

test('the cloud browser seed gives every browser its own plan change teams', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $status = fn(string $slug) => app(TeamBilling::class)->status(Team::query()->where('slug', $slug)->firstOrFail());

    foreach (CloudE2ESeeder::PROJECTS as $code) {
        $swap = Team::query()->where('slug', "swap-{$code}")->firstOrFail();

        expect($status("pay-{$code}")->state)->toBe(BillingState::Trialing)
            ->and($status("pay-{$code}")->plan)->toBe(Plan::Trial)
            ->and($status("swap-{$code}")->state)->toBe(BillingState::Active)
            ->and($status("end-{$code}")->plan)->toBe(Plan::Pro)
            ->and($status("keep-{$code}")->state)->toBe(BillingState::Ending)
            ->and($swap->stripe_id)->toBe("cus_e2e_swap_{$code}")
            ->and($swap->subscription('default')->stripe_id)->toBe("sub_e2e_swap_{$code}")
            ->and($swap->subscription('default')->items()->pluck('stripe_id')->all())->toBe(["si_e2e_swap_{$code}"]);
    }
});

test('the cloud browser seed has a fleet too big for starter', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $fleet = Team::query()->where('slug', 'fleet')->firstOrFail();

    expect(app(TeamBilling::class)->status($fleet)->plan)->toBe(Plan::Pro)
        ->and($fleet->servers()->count())->toBe(5)
        ->and($fleet->members()->count())->toBe(3)
        ->and($fleet->domain?->hostname)->toBe('deploy.bigfleet.test')
        ->and($fleet->domain?->isVerified())->toBeTrue();
});
