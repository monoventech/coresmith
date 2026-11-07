<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PlanEndedNotification;
use App\Notifications\PlanStartedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\Support\CloudBilling;

function billingMailTeam(): Team
{
    return Team::factory()->owned()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
}

function billingMailOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

beforeEach(function () {
    CloudBilling::enable();
});

test('a started plan thanks the owners and points at billing', function () {
    $team = billingMailTeam();
    $mail = (new PlanStartedNotification($team, Plan::Pro))->toMail(billingMailOwner($team));

    expect($mail->subject)->toBe('Acme Labs is on Pro')
        ->and($mail->introLines)->toBe(['Thanks for choosing Coresmith Cloud.', 'Receipts come from Stripe and are listed on the Billing tab.'])
        ->and($mail->actionText)->toBe('Open billing')
        ->and($mail->actionUrl)->toBe('http://localhost/acme/settings/billing');
});

test('an ended plan says the team is read-only and how to come back', function () {
    $team = billingMailTeam();
    $mail = (new PlanEndedNotification($team))->toMail(billingMailOwner($team));

    expect($mail->subject)->toBe("Acme Labs's Coresmith plan has ended")
        ->and($mail->introLines)->toBe(['The team is read-only now. Servers and apps keep running and you can still see everything.', 'Choose a plan to make changes again.'])
        ->and($mail->actionText)->toBe('Choose a plan')
        ->and($mail->actionUrl)->toBe('http://localhost/acme/settings/billing');
});

test('a failed payment says when stripe tries again', function () {
    $team = billingMailTeam();
    $mail = (new PaymentFailedNotification($team, Plan::Business, CarbonImmutable::parse('2026-10-21 09:00:00')))->toMail(billingMailOwner($team));

    expect($mail->subject)->toBe('Payment failed for Acme Labs')
        ->and($mail->introLines)->toBe([
            "Stripe could not charge the card for Acme Labs's Business plan.",
            'It tries again on Oct 21.',
            'Update the card to keep the team working.',
        ])
        ->and($mail->actionText)->toBe('Update payment method')
        ->and($mail->actionUrl)->toBe('http://localhost/acme/settings/billing');
});

test('a failed payment with no retry says stripe has stopped', function () {
    $team = billingMailTeam();
    $mail = (new PaymentFailedNotification($team, null, null))->toMail(billingMailOwner($team));

    expect($mail->introLines)->toBe([
        "Stripe could not charge the card for Acme Labs's Coresmith Cloud plan.",
        'It will not try again on its own.',
        'Update the card to keep the team working.',
    ]);
});

test('billing mail is queued and sent by email only', function (object $notification) {
    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via(new User))->toBe(['mail']);
})->with([
    'started' => fn() => new PlanStartedNotification(billingMailTeam(), Plan::Pro),
    'ended' => fn() => new PlanEndedNotification(billingMailTeam()),
    'failed' => fn() => new PaymentFailedNotification(billingMailTeam(), Plan::Pro, null),
]);

test('only owners are a team\'s owners', function () {
    $team = Team::factory()->owned()->create();
    $second = CloudBilling::member($team, TeamRole::Owner);
    CloudBilling::member($team, TeamRole::Admin);
    CloudBilling::member($team, TeamRole::Member);

    expect($team->owners()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$team->owner_id, $second->getKey()])->sort()->values()->all());
});
