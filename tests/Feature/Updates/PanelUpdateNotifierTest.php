<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\InstanceSetting;
use App\Models\Team;
use App\Models\User;
use App\Notifications\PanelUpdateFailedNotification;
use App\Services\Updates\PanelUpdateNotifier;
use App\Services\Updates\PanelUpdater;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdateState;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->paths = updatesPanel();
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    Team::factory()->create()->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
});

afterEach(function () {
    updatesCleanUp();
});

function updatesFinished(string $state, string $message, ?string $step = null, string $trigger = 'panel'): UpdateStatus
{
    $status = UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse('0.1.1'), $trigger, null);

    return ($step === null ? $status : $status->withStep($step))->finished($state, $message, $step);
}

test('a failed update reaches every instance owner and the alert email once', function () {
    Notification::fake();
    $second = User::factory()->create();
    $admin = User::factory()->create();
    Team::factory()->create()->members()->attach([$second->id => ['role' => TeamRole::Owner->value], $admin->id => ['role' => TeamRole::Admin->value]]);
    InstanceSetting::put('notification_email', 'ops@example.com');

    expect(app(PanelUpdateNotifier::class)->failed(updatesFinished(UpdateStatus::ROLLED_BACK, 'Running migrations failed: x. Coresmith rolled back to v0.1.0 and is running as before.', 'migrate')))->toBeTrue();

    Notification::assertSentTo([$this->owner, $second], PanelUpdateFailedNotification::class);
    Notification::assertNotSentTo($admin, PanelUpdateFailedNotification::class);
    Notification::assertSentOnDemand(PanelUpdateFailedNotification::class, fn($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ops@example.com');
});

test('an alert email that belongs to an owner is not emailed twice', function () {
    Notification::fake();
    InstanceSetting::put('notification_email', 'OWNER@example.com');

    app(PanelUpdateNotifier::class)->failed(updatesFinished(UpdateStatus::FAILED, 'Running migrations failed.', 'migrate'));

    Notification::assertSentToTimes($this->owner, PanelUpdateFailedNotification::class, 1);
    Notification::assertSentOnDemandTimes(PanelUpdateFailedNotification::class, 0);
});

test('a mail failure never stops the rollback', function () {
    Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('smtp down'));

    expect(app(PanelUpdateNotifier::class)->failed(updatesFinished(UpdateStatus::FAILED, 'x', 'migrate')))->toBeFalse();
});

test('nothing is emailed while mail goes nowhere', function () {
    Notification::fake();
    config(['mail.default' => 'log']);

    expect(app(PanelUpdateNotifier::class)->failed(updatesFinished(UpdateStatus::FAILED, 'x', 'migrate')))->toBeFalse()
        ->and(app(PanelUpdateNotifier::class)->mailable())->toBeFalse();

    Notification::assertNothingSent();
});

test('a rolled back update is emailed in plain words', function () {
    $status = updatesFinished(UpdateStatus::ROLLED_BACK, 'Running migrations failed: x. Coresmith rolled back to v0.1.0 and is running as before.', 'migrate');
    $mail = (new PanelUpdateFailedNotification($status->toArray()))->toMail($this->owner);

    expect($mail->subject)->toBe("Coresmith couldn't update to v0.1.1")
        ->and($mail->introLines)->toBe(['The update failed while running migrations, so Coresmith rolled back to v0.1.0 and is running as before.', $status->message])
        ->and($mail->actionText)->toBe('Read the log')
        ->and($mail->actionUrl)->toBe(route('settings.general'))
        ->and((new PanelUpdateFailedNotification($status->toArray()))->via($this->owner))->toBe(['mail']);
});

test('a failed rollback is emailed with what went wrong and how to finish it', function () {
    $message = 'Running migrations failed: x. The rollback did not finish: the backup is missing. Coresmith is in maintenance mode. Fix the problem above, then run cd /opt/coresmith/app && sudo -u coresmith php artisan coresmith:update --recover to finish the rollback.';
    $mail = (new PanelUpdateFailedNotification(updatesFinished(UpdateStatus::FAILED, $message, 'migrate')->toArray()))->toMail($this->owner);

    expect($mail->subject)->toBe('Coresmith needs attention after a failed update')
        ->and($mail->introLines)->toBe(["The update to v0.1.1 failed and the rollback didn't finish.", $message]);
});

test('an interrupted automatic update that recovery stops before anything changed is emailed', function () {
    Notification::fake();
    $status = UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse('0.1.1'), 'auto', null)->withStep('dependencies')->withMarker('built', '0.1.1-a1b2c3d');
    app(UpdateState::class)->save($status);

    $recovered = app(PanelUpdater::class)->recover();

    expect($recovered->state)->toBe(UpdateStatus::ABORTED);
    Notification::assertSentTo($this->owner, PanelUpdateFailedNotification::class);
});

test('an automatic update that did not run is emailed with why', function () {
    $mail = (new PanelUpdateFailedNotification(updatesFinished(UpdateStatus::ABORTED, 'A deploy of atlas is running. Wait for it to finish, then update.', 'preflight', 'auto')->toArray()))->toMail($this->owner);

    expect($mail->subject)->toBe("Tonight's update to v0.1.1 didn't run")
        ->and($mail->introLines)->toBe(['A deploy of atlas is running. Wait for it to finish, then update.']);
});

test('the updater emails owners when an update is rolled back, and not when it succeeds', function () {
    Notification::fake();
    updatesMachine(['artisan:migrate' => [1, "Migration failed\n"]]);

    app(PanelUpdater::class)->run(Version::parse('0.1.1'), 'panel', $this->owner->id);

    Notification::assertSentTo($this->owner, PanelUpdateFailedNotification::class);
});

test('a successful update emails nobody', function () {
    Notification::fake();

    app(PanelUpdater::class)->run(Version::parse('0.1.1'));

    Notification::assertNothingSent();
});

test('an automatic update that stopped before changing anything is emailed, a panel one is not', function (string $trigger, bool $sent) {
    Notification::fake();
    updatesMachine(['composer' => [1, "boom\n"]]);

    app(PanelUpdater::class)->run(Version::parse('0.1.1'), $trigger);

    $sent ? Notification::assertSentTo($this->owner, PanelUpdateFailedNotification::class) : Notification::assertNothingSent();
})->with([
    'auto' => ['auto', true],
    'panel' => ['panel', false],
]);

test('an automatic update refused at pre-flight is emailed', function () {
    Notification::fake();
    config(['coresmith.updates.min_free_bytes' => 10 ** 15]);

    app(PanelUpdater::class)->run(Version::parse('0.1.1'), 'auto');

    Notification::assertSentTo($this->owner, PanelUpdateFailedNotification::class, fn(PanelUpdateFailedNotification $notification): bool => $notification->status['failed_step'] === 'preflight');
});

test('the run log says when the owners could not be emailed', function () {
    config(['mail.default' => 'log']);
    updatesMachine(['artisan:migrate' => [1, "Migration failed\n"]]);

    $status = app(PanelUpdater::class)->run(Version::parse('0.1.1'));

    expect(app(UpdateState::class)->log($status->id))->toContain("Warning: Coresmith couldn't email the owners about this. No mail server is set up; add MAIL_MAILER and its settings to {$this->paths['root']}/shared/.env.");
});
