<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvoice;
use App\Models\User;
use App\Services\Billing\BillingPresenter;
use Carbon\CarbonImmutable;
use Tests\Support\CloudBilling;

function billingBannerSummary(Team $team, ?User $user = null): array
{
    return app(BillingPresenter::class)->summary($team->fresh(), $user ?? User::query()->findOrFail($team->owner_id));
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00'));
});

test('a trial with time to spare shows no banner', function () {
    $team = Team::factory()->owned()->create(['trial_ends_at' => CarbonImmutable::parse('2026-10-22 12:00:00')]);

    expect(billingBannerSummary($team)['banner'])->toBeNull();
});

test('a trial about to end counts down', function (string $ends, string $title) {
    $team = Team::factory()->owned()->create(['trial_ends_at' => CarbonImmutable::parse($ends)]);

    expect(billingBannerSummary($team)['banner'])->toMatchArray(['tone' => 'warning', 'title' => $title, 'action' => 'Choose a plan']);
})->with([
    'two days' => ['2026-10-20 12:00:00', 'Your trial ends in 2 days.'],
    'tomorrow' => ['2026-10-19 09:00:00', 'Your trial ends tomorrow.'],
    'today' => ['2026-10-18 18:00:00', 'Your trial ends today.'],
]);

test('the countdown says what happens after the trial', function () {
    $team = Team::factory()->owned()->create(['trial_ends_at' => CarbonImmutable::parse('2026-10-20 12:00:00')]);
    $deployer = CloudBilling::member($team, TeamRole::Member);

    expect(billingBannerSummary($team)['banner']['body'])->toBe('After Oct 20 this team becomes read-only. Choose a plan to keep making changes.')
        ->and(billingBannerSummary($team, $deployer)['banner'])->toMatchArray([
            'body' => 'After Oct 20 this team becomes read-only. Ask an owner to choose a plan.',
            'action' => null,
        ]);
});

test('a lapsed team gets a destructive banner, and only owners get the action', function () {
    $team = Team::factory()->trialEnded(3)->owned()->create();
    $deployer = CloudBilling::member($team, TeamRole::Member);

    expect(billingBannerSummary($team)['banner'])->toBe([
        'tone' => 'destructive',
        'title' => "This team's trial has ended, so it is read-only.",
        'body' => 'Servers and apps keep running, and everything stays visible.',
        'action' => 'Choose a plan',
    ])->and(billingBannerSummary($team, $deployer)['banner'])->toMatchArray([
        'body' => 'Servers and apps keep running, and everything stays visible. Ask an owner to choose a plan.',
        'action' => null,
    ]);
});

test('a failed payment says when stripe tries again', function () {
    $team = Team::factory()->subscribed(Plan::Pro, 'past_due')->owned()->create();
    TeamInvoice::factory()->for($team)->failed(CarbonImmutable::parse('2026-10-21 09:00:00'))->create();

    expect(billingBannerSummary($team)['banner'])->toBe([
        'tone' => 'warning',
        'title' => 'The last payment failed.',
        'body' => 'Stripe tries again on Oct 21. Update the card so this team keeps working.',
        'action' => 'Update payment method',
    ]);
});

test('a failed payment without a retry date still asks for the card', function () {
    $team = Team::factory()->subscribed(Plan::Pro, 'past_due')->owned()->create();

    expect(billingBannerSummary($team)['banner']['body'])->toBe('Update the card so this team keeps working.');
});

test('the shared billing prop is cloud only', function () {
    $team = Team::factory()->onTrial()->owned()->create();
    $owner = User::query()->findOrFail($team->owner_id);

    $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn($page) => $page
        ->where('billing.state', 'trialing')
        ->where('billing.plan', 'trial')
        ->where('billing.can_manage', true)
        ->where('billing.href', route('settings.billing')));

    config(['coresmith.edition' => 'self-hosted']);

    $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn($page) => $page->where('billing', null));
});

test('a starter team learns what it is missing', function () {
    $team = Team::factory()->subscribed(Plan::Starter)->owned()->create();

    expect(billingBannerSummary($team)['notes'])->toBe([
        'members' => 'Starter is for one person.',
        'custom_domain' => 'Custom domains are part of Pro and Business.',
        'single_sign_on' => 'Single sign-on is part of Business.',
        'offsite_backups' => 'Database backups to S3 are part of Business.',
        'audit_log_export' => 'Audit log export is part of Business.',
    ]);
});

test('a business team is missing nothing', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    expect(array_filter(billingBannerSummary($team)['notes']))->toBe([]);
});

test('a read-only team\'s notes give the reason, not the plan', function (Closure $team, string $title) {
    $notes = billingBannerSummary($team())['notes'];

    expect($notes)->toBe([
        'members' => null,
        'custom_domain' => $title,
        'single_sign_on' => $title,
        'offsite_backups' => $title,
        'audit_log_export' => $title,
    ]);
})->with([
    'a trial that ended' => [fn() => Team::factory()->trialEnded()->owned()->create(), "This team's trial has ended, so it is read-only."],
    'a business plan that ended' => [function () {
        $team = Team::factory()->owned()->create();
        Subscription::factory()->plan(Plan::Business)->ended(now()->subDay())->for($team, 'owner')->create();

        return $team;
    }, "This team's plan has ended, so it is read-only."],
    'payments that failed' => [fn() => Team::factory()->subscribed(Plan::Business, 'unpaid')->owned()->create(), "This team's payments failed, so it is read-only."],
    'no plan at all' => [fn() => Team::factory()->owned()->create(), 'This team has no plan yet, so it is read-only.'],
]);
