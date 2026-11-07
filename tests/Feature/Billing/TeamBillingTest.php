<?php

declare(strict_types=1);

use App\Enums\BillingState;
use App\Enums\Plan;
use App\Enums\PlanFeature;
use App\Enums\ReadOnlyReason;
use App\Models\Subscription;
use App\Models\Team;
use App\Services\Billing\BillingStatus;
use App\Services\Billing\TeamBilling;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Support\Facades\Log;
use Tests\Support\CloudBilling;

function teamBillingStatus(Team $team): BillingStatus
{
    return app(TeamBilling::class)->status($team->fresh());
}

function teamBillingSubscription(Team $team): SubscriptionFactory
{
    return Subscription::factory()->for($team, 'owner');
}

test('a self-hosted panel is unlimited', function () {
    $status = teamBillingStatus(Team::factory()->trialEnded()->create());

    expect($status->state)->toBe(BillingState::Unlimited)
        ->and($status->plan)->toBe(Plan::SelfHosted)
        ->and($status->readOnly())->toBeFalse()
        ->and($status->serverLimit())->toBeNull()
        ->and($status->allows(PlanFeature::SingleSignOn))->toBeTrue();
});

test('a plan that was cancelled but has not ended yet is ending', function () {
    CloudBilling::enable();
    $team = Team::factory()->create();
    teamBillingSubscription($team)->plan(Plan::Business)->ending(now()->addDays(5))->create();

    $status = teamBillingStatus($team);

    expect($status->state)->toBe(BillingState::Ending)
        ->and($status->plan)->toBe(Plan::Business)
        ->and($status->endsAt?->toDateString())->toBe(now()->addDays(5)->toDateString());
});

test('a plan whose payment failed is past due but still works', function () {
    CloudBilling::enable();
    $team = Team::factory()->create();
    teamBillingSubscription($team)->plan(Plan::Pro)->status('past_due')->create();

    $status = teamBillingStatus($team);

    expect($status->state)->toBe(BillingState::PastDue)
        ->and($status->plan)->toBe(Plan::Pro)
        ->and($status->readOnly())->toBeFalse()
        ->and($status->renewsAt)->not->toBeNull();
});

test('a subscription on a stripe trial is trialing on its plan', function () {
    CloudBilling::enable();
    $team = Team::factory()->create();
    teamBillingSubscription($team)->plan(Plan::Starter)->trialing(now()->addDays(3))->create();

    $status = teamBillingStatus($team);

    expect($status->state)->toBe(BillingState::Trialing)
        ->and($status->plan)->toBe(Plan::Starter)
        ->and($status->trialEndsAt?->toDateString())->toBe(now()->addDays(3)->toDateString());
});

test('a paid plan is active', function () {
    CloudBilling::enable();
    $team = Team::factory()->subscribed(Plan::Pro)->create();

    $status = teamBillingStatus($team);

    expect($status->state)->toBe(BillingState::Active)
        ->and($status->plan)->toBe(Plan::Pro)
        ->and($status->renewsAt)->not->toBeNull()
        ->and($status->serverLimit())->toBeNull()
        ->and($status->allows(PlanFeature::CustomDomain))->toBeTrue()
        ->and($status->allows(PlanFeature::SingleSignOn))->toBeFalse();
});

test('a new team on its trial is trialing pro features on one server', function () {
    CloudBilling::enable();

    $status = teamBillingStatus(Team::factory()->onTrial(10)->create());

    expect($status->state)->toBe(BillingState::Trialing)
        ->and($status->plan)->toBe(Plan::Trial)
        ->and($status->trialDaysLeft())->toBe(10)
        ->and($status->serverLimit())->toBe(1)
        ->and($status->allows(PlanFeature::TeamRoles))->toBeTrue();
});

test('a subscription whose payment never went through is read-only', function (string $status) {
    CloudBilling::enable();
    $team = Team::factory()->create();
    teamBillingSubscription($team)->status($status)->create();

    $billing = teamBillingStatus($team);

    expect($billing->state)->toBe(BillingState::ReadOnly)
        ->and($billing->plan)->toBeNull()
        ->and($billing->reason)->toBe(ReadOnlyReason::PaymentFailed);
})->with(['unpaid', 'incomplete', 'incomplete_expired']);

test('a plan that ended is read-only', function () {
    CloudBilling::enable();
    $team = Team::factory()->create();
    teamBillingSubscription($team)->ended(now()->subDays(2))->create();

    $status = teamBillingStatus($team);

    expect($status->state)->toBe(BillingState::ReadOnly)
        ->and($status->reason)->toBe(ReadOnlyReason::PlanEnded)
        ->and($status->endsAt?->toDateString())->toBe(now()->subDays(2)->toDateString())
        ->and($status->serverLimit())->toBe(0)
        ->and($status->memberLimit())->toBeNull()
        ->and($status->allows(PlanFeature::TeamRoles))->toBeFalse();
});

test('a trial that ended is read-only', function () {
    CloudBilling::enable();

    $status = teamBillingStatus(Team::factory()->trialEnded(3)->create());

    expect($status->state)->toBe(BillingState::ReadOnly)
        ->and($status->reason)->toBe(ReadOnlyReason::TrialEnded)
        ->and($status->trialEndsAt)->not->toBeNull()
        ->and($status->trialDaysLeft())->toBe(0);
});

test('a team with no plan and no trial is read-only', function () {
    CloudBilling::enable();

    $status = teamBillingStatus(Team::factory()->create());

    expect($status->state)->toBe(BillingState::ReadOnly)
        ->and($status->reason)->toBe(ReadOnlyReason::NoPlan)
        ->and($status->trialDaysLeft())->toBeNull();
});

test('the newest subscription decides', function () {
    CloudBilling::enable();
    $team = Team::factory()->create();
    teamBillingSubscription($team)->ended(now()->subDays(10))->create(['created_at' => now()->subDay()]);
    teamBillingSubscription($team)->plan(Plan::Business)->create(['created_at' => now()]);

    $status = teamBillingStatus($team);

    expect($status->state)->toBe(BillingState::Active)
        ->and($status->plan)->toBe(Plan::Business);
});

test('an unknown price is served as starter and logged', function () {
    CloudBilling::enable();
    Log::spy();
    $team = Team::factory()->create();
    teamBillingSubscription($team)->create(['stripe_price' => 'price_from_somewhere_else']);

    expect(teamBillingStatus($team)->plan)->toBe(Plan::Starter);

    Log::shouldHaveReceived('warning')->once()->with('A subscription is on a price Coresmith does not know.', ['team' => $team->id, 'price' => 'price_from_somewhere_else']);
});

test('days left count calendar days', function (string $ends, int $days) {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00'));

    $status = new BillingStatus(BillingState::Trialing, Plan::Trial, trialEndsAt: CarbonImmutable::parse($ends));

    expect($status->trialDaysLeft())->toBe($days);
})->with([
    'thirteen days on' => ['2026-10-31 14:00:00', 13],
    'tomorrow morning' => ['2026-10-19 09:00:00', 1],
    'tonight' => ['2026-10-18 18:00:00', 0],
]);

test('a free plan works like its plan with nothing to pay', function () {
    CloudBilling::enable();

    $status = teamBillingStatus(Team::factory()->onTrial(5)->free(Plan::Business)->create());

    expect($status->state)->toBe(BillingState::Free)
        ->and($status->plan)->toBe(Plan::Business)
        ->and($status->readOnly())->toBeFalse()
        ->and($status->endsAt)->toBeNull()
        ->and($status->serverLimit())->toBeNull()
        ->and($status->allows(PlanFeature::SingleSignOn))->toBeTrue();
});

test('a free plan with a last day keeps its plan limits until then', function () {
    CloudBilling::enable();

    $status = teamBillingStatus(Team::factory()->free(Plan::Starter, now()->addDays(3))->create());

    expect($status->state)->toBe(BillingState::Free)
        ->and($status->plan)->toBe(Plan::Starter)
        ->and($status->serverLimit())->toBe(3)
        ->and($status->allows(PlanFeature::TeamRoles))->toBeFalse()
        ->and($status->endsAt?->toDateString())->toBe(now()->addDays(3)->toDateString());
});

test('a running stripe plan comes before a free plan', function () {
    CloudBilling::enable();

    $status = teamBillingStatus(Team::factory()->subscribed(Plan::Pro)->free(Plan::Business)->create());

    expect($status->state)->toBe(BillingState::Active)
        ->and($status->plan)->toBe(Plan::Pro);
});

test('a free plan that ended is read-only and says so', function () {
    CloudBilling::enable();

    $status = teamBillingStatus(Team::factory()->trialEnded(20)->free(Plan::Pro, now()->subDays(2))->create());

    expect($status->state)->toBe(BillingState::ReadOnly)
        ->and($status->reason)->toBe(ReadOnlyReason::FreePlanEnded)
        ->and($status->plan)->toBeNull()
        ->and($status->endsAt?->toDateString())->toBe(now()->subDays(2)->toDateString())
        ->and($status->reason->title())->toBe("This team's free plan has ended, so it is read-only.");
});

test('a trial still running outlasts a free plan that ended', function () {
    CloudBilling::enable();

    $status = teamBillingStatus(Team::factory()->onTrial(4)->free(Plan::Pro, now()->subDay())->create());

    expect($status->state)->toBe(BillingState::Trialing)
        ->and($status->plan)->toBe(Plan::Trial);
});
