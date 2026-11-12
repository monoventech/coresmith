<?php

declare(strict_types=1);

use App\Enums\BillingState;
use App\Enums\Plan;
use App\Enums\ReadOnlyReason;
use App\Models\Team;
use App\Services\Billing\TeamBilling;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;

function freePlanTeam(array $attributes = []): Team
{
    return Team::factory()->create(['name' => 'Acme', 'slug' => 'acme', ...$attributes]);
}

function freePlanActivity(string $event): ?Activity
{
    return Activity::query()->where('description', $event)->latest('id')->first();
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00'));
});

test('a team gets a plan with nothing to pay', function () {
    $team = freePlanTeam();

    $this->artisan('coresmith:free-plan', ['team' => 'acme', '--plan' => 'business'])
        ->expectsOutput('Acme is on Business for free, with no end.')
        ->assertSuccessful();

    $status = app(TeamBilling::class)->status($team->fresh());

    expect($team->fresh()->free_plan)->toBe(Plan::Business)
        ->and($team->fresh()->free_until)->toBeNull()
        ->and($status->state)->toBe(BillingState::Free)
        ->and(freePlanActivity('free_plan_granted')?->properties->all())->toBe(['plan' => 'business', 'until' => null])
        ->and(freePlanActivity('free_plan_granted')?->subject_id)->toBe($team->id);
});

test('a free plan can run until a last day', function () {
    $team = freePlanTeam();

    $this->artisan('coresmith:free-plan', ['team' => 'acme', '--plan' => 'pro', '--until' => '2026-11-30'])
        ->expectsOutput('Acme is on Pro for free until Nov 30, 2026.')
        ->assertSuccessful();

    expect($team->fresh()->free_until?->toDateTimeString())->toBe('2026-11-30 23:59:59')
        ->and(freePlanActivity('free_plan_granted')?->properties->all())->toBe(['plan' => 'pro', 'until' => '2026-11-30']);
});

test('a free plan can be moved to a new last day', function () {
    $team = freePlanTeam();
    $team->forceFill(['free_plan' => Plan::Pro, 'free_until' => now()->addDays(3)])->save();

    $this->artisan('coresmith:free-plan', ['team' => 'acme', '--until' => '2027-01-31'])
        ->expectsOutput('Acme is on Pro for free until Jan 31, 2027.')
        ->assertSuccessful();

    expect($team->fresh()->free_plan)->toBe(Plan::Pro)
        ->and($team->fresh()->free_until?->toDateString())->toBe('2027-01-31');
});

test('a free plan can run out today', function () {
    $team = freePlanTeam();

    $this->artisan('coresmith:free-plan', ['team' => 'acme', '--plan' => 'starter', '--until' => '2026-11-07'])
        ->expectsOutput('Acme is on Starter for free until Nov 7, 2026.')
        ->assertSuccessful();

    expect(app(TeamBilling::class)->status($team->fresh())->state)->toBe(BillingState::Free);
});

test('a free plan ends when asked', function () {
    $team = freePlanTeam();
    $team->forceFill(['free_plan' => Plan::Business])->save();

    $this->artisan('coresmith:free-plan', ['team' => 'acme', '--end' => true])
        ->expectsOutput("Acme's free plan has ended.")
        ->assertSuccessful();

    $status = app(TeamBilling::class)->status($team->fresh());

    expect($team->fresh()->free_until?->toDateTimeString())->toBe('2026-11-07 12:00:00')
        ->and($status->state)->toBe(BillingState::ReadOnly)
        ->and($status->reason)->toBe(ReadOnlyReason::FreePlanEnded)
        ->and(freePlanActivity('free_plan_ended')?->properties->all())->toBe(['plan' => 'business']);
});

test('a team can be named by its id', function () {
    $team = freePlanTeam();

    $this->artisan('coresmith:free-plan', ['team' => $team->id, '--plan' => 'pro'])
        ->expectsOutput('Acme is on Pro for free, with no end.')
        ->assertSuccessful();
});

test('a team on its own shows what it has', function () {
    freePlanTeam();
    Team::factory()->create(['name' => 'Globex', 'slug' => 'globex', 'free_plan' => Plan::Pro, 'free_until' => now()->addDays(10)]);

    $this->artisan('coresmith:free-plan', ['team' => 'acme'])->expectsOutput('Acme has no free plan.')->assertSuccessful();
    $this->artisan('coresmith:free-plan', ['team' => 'globex'])->expectsOutput('Globex is on Pro for free until Nov 17, 2026.')->assertSuccessful();
});

test('every free plan is listed', function () {
    Team::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'free_plan' => Plan::Business]);
    Team::factory()->create(['name' => 'Globex', 'slug' => 'globex', 'free_plan' => Plan::Pro, 'free_until' => CarbonImmutable::parse('2026-12-01 23:59:59')]);
    Team::factory()->create(['name' => 'Initech', 'slug' => 'initech', 'free_plan' => Plan::Starter, 'free_until' => CarbonImmutable::parse('2026-11-01 23:59:59')]);
    Team::factory()->create(['name' => 'Umbrella', 'slug' => 'umbrella']);

    $this->artisan('coresmith:free-plan')
        ->expectsTable(['Team', 'Address', 'Plan', 'Until'], [
            ['Acme', 'acme', 'Business', 'No end'],
            ['Globex', 'globex', 'Pro', 'Dec 1, 2026'],
            ['Initech', 'initech', 'Starter', 'Ended Nov 1, 2026'],
        ])
        ->assertSuccessful();
});

test('an empty list says so', function () {
    freePlanTeam();

    $this->artisan('coresmith:free-plan')->expectsOutput('No team has a free plan.')->assertSuccessful();
});

test('a free plan is refused when it cannot be given', function (array $options, string $message) {
    freePlanTeam();

    $this->artisan('coresmith:free-plan', $options)->expectsOutput($message)->assertFailed();

    expect(Team::query()->where('slug', 'acme')->sole()->free_plan)->toBeNull()
        ->and(freePlanActivity('free_plan_granted'))->toBeNull();
})->with([
    'an unknown team' => [['team' => 'nobody', '--plan' => 'pro'], 'No team has the address or ID nobody.'],
    'an unknown plan' => [['team' => 'acme', '--plan' => 'gold'], 'Choose --plan=starter, --plan=pro or --plan=business.'],
    'the trial' => [['team' => 'acme', '--plan' => 'trial'], 'Choose --plan=starter, --plan=pro or --plan=business.'],
    'self-hosted' => [['team' => 'acme', '--plan' => 'self-hosted'], 'Choose --plan=starter, --plan=pro or --plan=business.'],
    'a date that is not one' => [['team' => 'acme', '--plan' => 'pro', '--until' => 'next week'], '--until takes a date like 2027-01-31.'],
    'a day that has passed' => [['team' => 'acme', '--plan' => 'pro', '--until' => '2026-11-06'], '--until has to be today or later.'],
    'a plan and an end' => [['team' => 'acme', '--plan' => 'pro', '--end' => true], 'Pass --plan or --end, not both.'],
    'an end with nothing running' => [['team' => 'acme', '--end' => true], 'Acme has no free plan to end.'],
    'a last day with no plan' => [['team' => 'acme', '--until' => '2027-01-31'], 'Acme has no free plan. Pass --plan to give it one.'],
]);

test('a team that pays through stripe is refused', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->create(['name' => 'Acme', 'slug' => 'acme']);

    $this->artisan('coresmith:free-plan', ['team' => 'acme', '--plan' => 'business'])
        ->expectsOutput('Acme pays for Pro through Stripe. End that plan first, then give it a free one.')
        ->assertFailed();

    expect($team->fresh()->free_plan)->toBeNull();
});

test('a self-hosted panel has nothing to give', function () {
    config(['coresmith.edition' => 'self-hosted']);
    freePlanTeam();

    $this->artisan('coresmith:free-plan', ['team' => 'acme', '--plan' => 'pro'])
        ->expectsOutput('Free plans are part of Coresmith Cloud, not self-hosted Coresmith.')
        ->assertFailed();
});
