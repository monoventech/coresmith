<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvoice;
use App\Models\User;
use App\Services\Panel\Formats;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Tests\Support\CloudBilling;

function billingPageOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function billingPagePlan(Team $team, ?User $viewer = null): array
{
    $page = null;

    test()->actingAs($viewer ?? billingPageOwner($team))
        ->get(route('settings.billing'))
        ->assertOk()
        ->assertInertia(function ($inertia) use (&$page) {
            $page = $inertia->toArray()['props'];
        });

    return $page;
}

function billingPageSubscribed(Plan $plan): SubscriptionFactory
{
    return Subscription::factory()->plan($plan);
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00'));
});

test('an active plan says when it renews', function () {
    $team = Team::factory()->owned()->create();
    billingPageSubscribed(Plan::Pro)->for($team, 'owner')->create(['renews_at' => CarbonImmutable::parse('2026-11-01')]);

    expect(billingPagePlan($team)['plan'])->toBe([
        'key' => 'pro',
        'label' => 'Pro',
        'badge' => ['label' => 'Active', 'tone' => 'accent'],
        'line' => '$19 per month · renews Nov 1',
        'amount' => '$19',
        'usage' => '0 servers',
        'note' => null,
        'primary' => 'change',
        'self_host' => true,
    ]);
});

test('a stripe trial says when the first charge comes', function () {
    $team = Team::factory()->owned()->create();
    billingPageSubscribed(Plan::Pro)->trialing(CarbonImmutable::parse('2026-11-01'))->for($team, 'owner')->create();

    expect(billingPagePlan($team)['plan'])->toMatchArray([
        'label' => 'Pro',
        'badge' => ['label' => 'Trial', 'tone' => 'accent'],
        'line' => '$19 per month · first charge Nov 1',
        'amount' => '$19',
        'primary' => 'change',
    ]);
});

test('the free trial says what it includes and when it ends', function () {
    $team = Team::factory()->owned()->create(['trial_ends_at' => CarbonImmutable::parse('2026-11-01')]);

    expect(billingPagePlan($team)['plan'])->toMatchArray([
        'key' => 'trial',
        'label' => 'Trial',
        'badge' => ['label' => 'Trial', 'tone' => 'accent'],
        'line' => 'Pro features on 1 server · trial ends Nov 1',
        'amount' => '$0',
        'usage' => '0 of 1 server',
        'primary' => 'choose',
    ]);
});

test('a failed payment shows on the plan', function () {
    $team = Team::factory()->owned()->create();
    billingPageSubscribed(Plan::Pro)->status('past_due')->for($team, 'owner')->create();

    expect(billingPagePlan($team)['plan'])->toMatchArray([
        'badge' => ['label' => 'Payment failed', 'tone' => 'destructive'],
        'line' => '$19 per month · payment failed',
        'primary' => 'change',
    ]);
});

test('a cancelled plan says when it ends and can be kept', function () {
    $team = Team::factory()->owned()->create();
    billingPageSubscribed(Plan::Business)->ending(CarbonImmutable::parse('2026-11-01'))->for($team, 'owner')->create();

    expect(billingPagePlan($team)['plan'])->toMatchArray([
        'label' => 'Business',
        'badge' => ['label' => 'Ending', 'tone' => 'warning'],
        'line' => '$39 per month · ends Nov 1',
        'amount' => '$39',
        'note' => 'The plan stays active until Nov 1. After that this team is read-only, and its servers and apps keep running.',
        'primary' => 'keep',
        'self_host' => false,
    ]);
});

test('a read-only team is told why and offered a plan', function (Closure $build, string $label, string $line) {
    $team = Team::factory()->owned()->create();
    $build($team);

    expect(billingPagePlan($team->fresh())['plan'])->toMatchArray([
        'key' => null,
        'label' => $label,
        'badge' => ['label' => 'Read-only', 'tone' => 'destructive'],
        'line' => $line,
        'amount' => '$0',
        'usage' => '0 servers',
        'primary' => 'choose',
    ]);
})->with([
    'no plan' => [fn(Team $team) => null, 'No plan', 'No plan yet · this team is read-only'],
    'trial ended' => [fn(Team $team) => $team->forceFill(['trial_ends_at' => CarbonImmutable::parse('2026-10-01')])->save(), 'Trial', 'Trial ended Oct 1 · this team is read-only'],
    'plan ended' => [fn(Team $team) => billingPageSubscribed(Plan::Pro)->ended(CarbonImmutable::parse('2026-10-01'))->for($team, 'owner')->create(), 'No plan', 'Ended Oct 1 · this team is read-only'],
    'payment failed' => [fn(Team $team) => billingPageSubscribed(Plan::Pro)->status('unpaid')->for($team, 'owner')->create(), 'No plan', 'Payments failed · this team is read-only'],
]);

test('the card and invoices are shown to owners', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $team->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242', 'pm_expires' => '08/28'])->save();
    TeamInvoice::factory()->for($team)->create(['period_start' => CarbonImmutable::parse('2026-09-01'), 'number' => 'MNV-0002']);
    TeamInvoice::factory()->for($team)->create(['period_start' => CarbonImmutable::parse('2026-10-01'), 'number' => 'MNV-0003']);
    TeamInvoice::factory()->for($team)->draft()->create(['period_start' => CarbonImmutable::parse('2026-11-01')]);
    TeamInvoice::factory()->for($team)->failed(CarbonImmutable::parse('2026-10-21'))->create(['period_start' => CarbonImmutable::parse('2026-08-01'), 'number' => 'MNV-0001']);

    $page = billingPagePlan($team);

    expect($page['payment'])->toBe(['label' => 'Visa •••• 4242', 'expires' => '08/28'])
        ->and($page['invoice_count'])->toBe(3)
        ->and(array_column($page['invoices'], 'number'))->toBe(['MNV-0003', 'MNV-0002', 'MNV-0001'])
        ->and(array_column($page['invoices'], 'period'))->toBe(['Oct 2026', 'Sep 2026', 'Aug 2026'])
        ->and(array_column(array_column($page['invoices'], 'status'), 'label'))->toBe(['Paid', 'Paid', 'Failed'])
        ->and(array_unique(array_column($page['invoices'], 'amount')))->toBe(['$19'])
        ->and($page['invoices_note'])->toBeNull()
        ->and($page['receipts_to'])->toBe(billingPageOwner($team)->email);
});

test('a deployer sees the plan and its limits but not the card or the invoices', function () {
    $team = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    $team->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242', 'pm_expires' => '08/28'])->save();
    TeamInvoice::factory()->for($team)->create();
    $deployer = CloudBilling::member($team, TeamRole::Member);
    $deployer->forceFill(['current_team_id' => $team->id])->save();

    $page = billingPagePlan($team, $deployer->fresh());

    expect($page['can_manage'])->toBeFalse()
        ->and($page['plan']['usage'])->toBe('0 of 3 servers')
        ->and($page['plan']['primary'])->toBeNull()
        ->and($page['plan']['self_host'])->toBeFalse()
        ->and($page['payment'])->toBeNull()
        ->and($page['payment_note'])->toBe('Only an owner can see and change the payment method.')
        ->and($page['invoices'])->toBe([])
        ->and($page['invoices_note'])->toBe('Only an owner can see invoices.')
        ->and($page['receipts_to'])->toBeNull();
});

test('the invoice list stops at 24', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    foreach (range(1, 30) as $month) {
        TeamInvoice::factory()->for($team)->create(['period_start' => CarbonImmutable::parse('2024-01-01')->addMonths($month)]);
    }

    $page = billingPagePlan($team);

    expect($page['invoices'])->toHaveCount(24)
        ->and($page['invoice_count'])->toBe(30);
});

test('without invoices the page says when the first arrives', function () {
    $trial = Team::factory()->onTrial()->owned()->create();
    $none = Team::factory()->owned()->create();

    expect(billingPagePlan($trial)['invoices_note'])->toBe('No invoices yet. The first one arrives when the trial ends and a plan starts.')
        ->and(billingPagePlan($trial)['payment_note'])->toBe('No card yet. You add one when you choose a plan.')
        ->and(billingPagePlan($none)['invoices_note'])->toBe('No invoices yet. The first one arrives when you choose a plan.');
});

test('a user without a team has no billing page', function () {
    $this->actingAs(User::factory()->create())->get(route('settings.billing'))->assertNotFound();
});

test('the plan dialog lists each plan with what blocks it', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    Server::factory()->for($team)->count(4)->create(['status' => ServerStatus::Active]);

    $plans = billingPagePlan($team)['plans'];

    expect(array_column($plans, 'key'))->toBe(['starter', 'pro', 'business'])
        ->and(array_column($plans, 'label'))->toBe(['Starter', 'Pro', 'Business'])
        ->and(array_column($plans, 'current'))->toBe([false, true, false])
        ->and(array_column($plans, 'available'))->toBe([true, true, true])
        ->and($plans[0]['summary'])->toBe('$9 per month · 3 servers · one person')
        ->and(array_column($plans[0]['blockers'], 'message'))->toBe(['Starter includes 3 servers and this team has 4. Detach 1 first.'])
        ->and($plans[2]['blockers'])->toBe([])
        ->and(array_column($plans, 'notes'))->toBe([[], [], []]);
});

test('leaving business is noted on the cheaper plans', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    expect(array_column(billingPagePlan($team)['plans'], 'notes'))->toBe([
        ['Database backups to S3 stop.', 'Audit log export goes away.'],
        ['Database backups to S3 stop.', 'Audit log export goes away.'],
        [],
    ]);
});

test('a plan without a price is shown as not available', function () {
    config(['coresmith.billing.plans.business.prices' => '']);
    $team = Team::factory()->onTrial()->owned()->create();

    expect(array_column(billingPagePlan($team)['plans'], 'available'))->toBe([true, true, false])
        ->and(array_column(billingPagePlan($team)['plans'], 'current'))->toBe([false, false, false]);
});

test('a deployer is not shown what blocks a plan change', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    Server::factory()->for($team)->count(4)->create(['status' => ServerStatus::Active]);
    $deployer = CloudBilling::member($team, TeamRole::Member);
    $deployer->forceFill(['current_team_id' => $team->id])->save();

    expect(array_column(billingPagePlan($team, $deployer->fresh())['plans'], 'blockers'))->toBe([[], [], []]);
});

test('moving to self-host knows when cloud stops', function () {
    $paying = Team::factory()->owned()->create(['stripe_id' => 'cus_paying']);
    billingPageSubscribed(Plan::Pro)->for($paying, 'owner')->create(['renews_at' => CarbonImmutable::parse('2026-11-01')]);
    $trying = Team::factory()->owned()->create(['trial_ends_at' => CarbonImmutable::parse('2026-10-25')]);
    $lapsed = Team::factory()->trialEnded()->owned()->create();
    $guide = 'https://github.com/monoven/coresmith#self-hosting';

    expect(billingPagePlan($paying)['self_hosting'])->toBe(['has_subscription' => true, 'ends_on' => 'Nov 1', 'trial_ends_on' => null, 'guide' => $guide])
        ->and(billingPagePlan($trying)['self_hosting'])->toBe(['has_subscription' => false, 'ends_on' => null, 'trial_ends_on' => 'Oct 25', 'guide' => $guide])
        ->and(billingPagePlan($lapsed)['self_hosting'])->toBe(['has_subscription' => false, 'ends_on' => null, 'trial_ends_on' => null, 'guide' => $guide]);
});

test('the plan card and the dialog show what the team pays, not the configured price', function () {
    $team = Team::factory()->owned()->create();
    billingPageSubscribed(Plan::Pro)->for($team, 'owner')->create(['renews_at' => CarbonImmutable::parse('2026-11-01'), 'unit_amount' => 1500, 'currency' => 'usd']);

    $page = billingPagePlan($team);

    expect($page['plan']['line'])->toBe('$15 per month · renews Nov 1')
        ->and($page['plan']['amount'])->toBe('$15')
        ->and($page['plans'][0]['summary'])->toBe('$9 per month · 3 servers · one person')
        ->and($page['plans'][1]['summary'])->toBe('$15 per month · unlimited servers · teammates and roles · custom domain')
        ->and($page['plans'][2]['summary'])->toStartWith('$39 per month');
});

test('a mirrored price keeps its cents and its currency', function () {
    $team = Team::factory()->owned()->create();
    billingPageSubscribed(Plan::Pro)->for($team, 'owner')->create(['renews_at' => CarbonImmutable::parse('2026-11-01'), 'unit_amount' => 1550, 'currency' => 'gbp']);

    $page = billingPagePlan($team);

    expect($page['plan']['amount'])->toBe(Formats::cents(1550, 'gbp'))
        ->and($page['plan']['amount'])->not->toBe(Formats::cents(1550, 'usd'))
        ->and($page['plan']['line'])->toStartWith(Formats::cents(1550, 'gbp') . ' per month');
});

test('the configured price stands in only when nothing is mirrored', function () {
    $team = Team::factory()->owned()->create();
    billingPageSubscribed(Plan::Pro)->for($team, 'owner')->create(['unit_amount' => null, 'currency' => null]);

    $page = billingPagePlan($team);

    expect($page['plan']['amount'])->toBe('$19')
        ->and($page['plans'][1]['summary'])->toStartWith('$19 per month');
});

test('an ended plan\'s price is not shown as the price of the next one', function () {
    $team = Team::factory()->onTrial(9)->owned()->create();
    billingPageSubscribed(Plan::Pro)->ended(now()->subMonth())->for($team, 'owner')->create(['unit_amount' => 1500, 'currency' => 'usd']);

    $page = billingPagePlan($team);

    expect($page['plan']['amount'])->toBe('$0')
        ->and($page['plans'][1]['summary'])->toStartWith('$19 per month');
});

test('the card can be changed at stripe whenever the team has a customer', function () {
    $team = Team::factory()->subscribed(Plan::Pro, 'past_due')->owned()->create();

    $page = billingPagePlan($team);

    expect($page['portal'])->toBeTrue()
        ->and($page['payment'])->toBeNull()
        ->and($page['payment_note'])->toBe('No card shown here yet. Add or update it at Stripe.');
});

test('a team with a card can change it at stripe', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $team->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242', 'pm_expires' => '08/28'])->save();

    $page = billingPagePlan($team->fresh());

    expect($page['portal'])->toBeTrue()
        ->and($page['payment']['label'])->toBe('Visa •••• 4242')
        ->and($page['payment_note'])->toBe('');
});

test('a team that has never been to stripe has nowhere to change a card', function () {
    $team = Team::factory()->onTrial()->owned()->create();

    $page = billingPagePlan($team);

    expect($page['portal'])->toBeFalse()
        ->and($page['payment_note'])->toBe('No card yet. You add one when you choose a plan.');
});

test('only an owner can go to stripe', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    $page = billingPagePlan($team, CloudBilling::member($team, TeamRole::Admin));

    expect($page['portal'])->toBeFalse()
        ->and($page['payment_note'])->toBe('Only an owner can see and change the payment method.');
});
