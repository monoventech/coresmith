<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TrialEndingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CloudBilling;

function trialReminderOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00:00'));
    Notification::fake();
});

test('seven days out the owners are reminded once', function () {
    $team = Team::factory()->onTrial(7)->owned()->create();
    $second = CloudBilling::member($team, TeamRole::Owner);
    $admin = CloudBilling::member($team, TeamRole::Admin);

    $this->artisan('coresmith:trial-reminders')->assertSuccessful();
    $this->artisan('coresmith:trial-reminders')->assertSuccessful();

    Notification::assertSentToTimes(trialReminderOwner($team), TrialEndingNotification::class, 1);
    Notification::assertSentToTimes($second, TrialEndingNotification::class, 1);
    Notification::assertNotSentTo($admin, TrialEndingNotification::class);

    expect($team->fresh()->trial_reminded_at?->toDateTimeString())->toBe('2026-10-18 09:00:00');
});

test('a trial ending sooner is reminded too', function () {
    $team = Team::factory()->owned()->create(['trial_ends_at' => CarbonImmutable::parse('2026-10-19 18:00:00')]);

    $this->artisan('coresmith:trial-reminders')->assertSuccessful();

    Notification::assertSentTo(trialReminderOwner($team), TrialEndingNotification::class, fn(TrialEndingNotification $notification): bool => $notification->days === 1);
});

test('eight days out is too early', function () {
    Team::factory()->onTrial(8)->owned()->create();

    $this->artisan('coresmith:trial-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

test('ended trials and paying teams are not reminded', function () {
    $ended = Team::factory()->trialEnded()->owned()->create();
    $paying = Team::factory()->subscribed(Plan::Pro)->owned()->create(['trial_ends_at' => now()->addDays(3)]);
    $reminded = Team::factory()->onTrial(2)->owned()->create(['trial_reminded_at' => now()->subDays(5)]);
    $free = Team::factory()->onTrial(3)->free(Plan::Business)->owned()->create();
    $checkedOut = Team::factory()->onTrial(4)->owned()->create(['stripe_id' => 'cus_checked_out']);
    Subscription::factory()->for($checkedOut, 'owner')->plan(Plan::Pro)->trialing(now()->addDays(4))->create();

    $this->artisan('coresmith:trial-reminders')->assertSuccessful();

    Notification::assertNothingSent();

    expect($ended->fresh()->trial_reminded_at)->toBeNull()
        ->and($paying->fresh()->trial_reminded_at)->toBeNull()
        ->and($checkedOut->fresh()->trial_reminded_at)->toBeNull()
        ->and($free->fresh()->trial_reminded_at)->toBeNull()
        ->and($reminded->fresh()->trial_reminded_at?->toDateString())->toBe('2026-10-13');
});

test('self-hosted sends nothing and says why', function () {
    config(['coresmith.edition' => 'self-hosted']);
    Team::factory()->onTrial(7)->owned()->create();

    $this->artisan('coresmith:trial-reminders')
        ->expectsOutput('Trial reminders are part of Coresmith Cloud.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

test('the reminder says when and what happens', function (int $days, string $subject) {
    $team = Team::factory()->owned()->create(['name' => 'Acme Labs', 'slug' => 'acme', 'trial_ends_at' => now()->addDays($days)]);
    $mail = (new TrialEndingNotification($team, $days, CarbonImmutable::instance($team->trial_ends_at)))->toMail(trialReminderOwner($team));

    expect($mail->subject)->toBe($subject)
        ->and($mail->introLines)->toBe([
            'Acme Labs is on a 14-day trial of Pro. It ends on ' . now()->addDays($days)->format('M j') . '.',
            'After that the team becomes read-only: servers and apps keep running, but nothing can change until you choose a plan.',
        ])
        ->and($mail->actionText)->toBe('Choose a plan')
        ->and($mail->actionUrl)->toBe('http://localhost/acme/settings/billing');
})->with([
    'a week' => [7, 'Your Coresmith trial ends in 7 days'],
    'a day' => [1, 'Your Coresmith trial ends in 1 day'],
    'today' => [0, 'Your Coresmith trial ends today'],
]);

test('the reminder is scheduled every morning', function () {
    $event = collect(app(Schedule::class)->events())->first(fn(Event $event): bool => $event->description === 'coresmith:trial-reminders');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
