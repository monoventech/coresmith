<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\MonitorKind;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Monitor;
use App\Models\MonitorAlert;
use App\Models\Server;
use App\Models\User;
use App\Notifications\CronJobNotification;
use App\Notifications\CronJobRecoveredNotification;
use App\Services\Cron\CronMonitor;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\CronJobs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

function cronMonitorServer(): Server
{
    return Server::factory()->create(['name' => 'web-1', 'status' => ServerStatus::Active, 'booted_at' => now()->subDays(30)]);
}

function cronMonitorApp(Server $server, array $attributes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Php,
        'cron_jobs' => [['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']],
        'current_release' => '20261019-120000',
        'power_state' => PowerState::Active,
        'cron_written_at' => now()->subDays(10),
        ...$attributes,
    ]);
}

function cronMonitorMember(Server $server): User
{
    $user = User::factory()->create();
    $server->team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user;
}

function cronMonitorId(): string
{
    return CronJobs::id(['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']);
}

function cronMonitorState(Application $application, ?array $last, ?int $running = null): Application
{
    $application->forceFill(['cron_runs' => [cronMonitorId() => ['seen' => now()->subDays(10)->getTimestamp(), 'last' => $last, 'running' => $running]]])->save();

    return $application->fresh();
}

function cronMonitorRun(Server $server, Application $application): void
{
    app(CronMonitor::class)->evaluate($server->fresh(), $application->fresh(), now()->getTimestamp(), 'UTC');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00'));
    Notification::fake();
});

test('a job whose latest run failed opens one alert and mails its exit code once', function () {
    $server = cronMonitorServer();
    $member = cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server), ['started' => now()->setTime(3, 0)->getTimestamp(), 'seconds' => 12, 'exit' => 3]);

    cronMonitorRun($server, $application);
    cronMonitorRun($server, $application);

    Notification::assertSentToTimes($member, CronJobNotification::class, 1);
    Notification::assertSentTo($member, CronJobNotification::class, fn(CronJobNotification $notification): bool => $notification->reason === 'failed' && $notification->exit === 3 && $notification->job === 'php artisan backup:run');
    expect(MonitorAlert::query()->sole()->only(['kind', 'job', 'level']))->toBe(['kind' => MonitorKind::Cron, 'job' => cronMonitorId(), 'level' => 'failed'])
        ->and(Activity::query()->where('description', 'cron_failed')->sole()->properties->toArray())->toBeInAnyKeyOrder(['job' => 'php artisan backup:run', 'exit' => 3]);
});

test('a job that did not run when due opens a missed alert naming the due time', function () {
    $server = cronMonitorServer();
    $member = cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server), ['started' => now()->subDay()->setTime(3, 0, 1)->getTimestamp(), 'seconds' => 12, 'exit' => 0]);

    cronMonitorRun($server, $application);

    $due = now()->setTime(3, 0)->getTimestamp();

    Notification::assertSentTo($member, CronJobNotification::class, fn(CronJobNotification $notification): bool => $notification->reason === 'missed' && $notification->moment === $due);
    expect(MonitorAlert::query()->sole()->level)->toBe('missed')
        ->and(Activity::query()->where('description', 'cron_missed')->sole()->properties->toArray())->toBeInAnyKeyOrder(['job' => 'php artisan backup:run', 'due' => $due]);
});

test('a job that is running from its due time is not missed', function () {
    $server = cronMonitorServer();
    cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server), ['started' => now()->subDay()->setTime(3, 0, 1)->getTimestamp(), 'seconds' => 12, 'exit' => 0], now()->setTime(3, 0, 1)->getTimestamp());

    cronMonitorRun($server, $application);

    Notification::assertNothingSent();
});

test('the next run without an error closes the alert and says when it ran', function () {
    $server = cronMonitorServer();
    $member = cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server), ['started' => now()->setTime(3, 0)->getTimestamp(), 'seconds' => 12, 'exit' => 3]);
    cronMonitorRun($server, $application);

    $this->travel(1)->day();
    $application = cronMonitorState($application, ['started' => now()->setTime(3, 0, 1)->getTimestamp(), 'seconds' => 9, 'exit' => 0]);
    cronMonitorRun($server, $application);

    Notification::assertSentTo($member, CronJobRecoveredNotification::class, fn(CronJobRecoveredNotification $notification): bool => $notification->seconds === 9 && $notification->started === now()->setTime(3, 0, 1)->getTimestamp());
    expect(MonitorAlert::query()->sole()->open_key)->toBeNull()
        ->and(Activity::query()->where('description', 'cron_recovered')->sole()->properties->toArray())->toBe(['job' => 'php artisan backup:run']);
});

test('another failure while the alert is open sends nothing more', function () {
    $server = cronMonitorServer();
    cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server), ['started' => now()->setTime(3, 0)->getTimestamp(), 'seconds' => 12, 'exit' => 3]);
    cronMonitorRun($server, $application);

    $this->travel(1)->day();
    cronMonitorRun($server, cronMonitorState($application, ['started' => now()->setTime(3, 0, 1)->getTimestamp(), 'seconds' => 12, 'exit' => 1]));

    Notification::assertSentTimes(CronJobNotification::class, 1);
    Notification::assertSentTimes(CronJobRecoveredNotification::class, 0);
});

test('a removed job, a switched off monitor or a stopped app closes the alert without a mail', function (Closure $change) {
    $server = cronMonitorServer();
    cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server), ['started' => now()->setTime(3, 0)->getTimestamp(), 'seconds' => 12, 'exit' => 3]);
    cronMonitorRun($server, $application);

    $change($application->fresh());
    cronMonitorRun($server, $application);

    expect(MonitorAlert::query()->sole()->open_key)->toBeNull();
    Notification::assertSentTimes(CronJobRecoveredNotification::class, 0);
})->with([
    'the job is removed' => [fn(Application $application) => $application->forceFill(['cron_jobs' => [['schedule' => '0 4 * * *', 'command' => 'php artisan other:run']]])->save()],
    'the monitor is switched off' => [fn(Application $application) => Monitor::query()->create(['team_id' => $application->team_id, 'kind' => MonitorKind::Cron, 'threshold' => 0, 'duration' => 0, 'enabled' => false])],
    'the app is stopped' => [fn(Application $application) => $application->forceFill(['power_state' => PowerState::Stopped])->save()],
]);

test('a job due before the crontab was last written, or before the server booted, is not missed', function (string $column) {
    $server = cronMonitorServer();
    cronMonitorMember($server);
    $application = cronMonitorApp($server);

    if ($column === 'booted_at') {
        $server->forceFill(['booted_at' => now()->setTime(4, 0)])->save();
    } else {
        $application->forceFill(['cron_written_at' => now()->setTime(4, 0)])->save();
    }

    cronMonitorRun($server, cronMonitorState($application, ['started' => now()->subDay()->setTime(3, 0, 1)->getTimestamp(), 'seconds' => 12, 'exit' => 0]));

    Notification::assertNothingSent();
})->with(['cron_written_at', 'booted_at']);

test('the mails say what failed or was missed, and when it ran again', function (Closure $make, string $subject, array $lines) {
    $mail = $make()->toMail(new User);

    expect($mail->subject)->toBe($subject)
        ->and($mail->introLines)->toBe($lines)
        ->and($mail->actionText)->toBe('Open Shop');
})->with([
    'failed' => [fn() => new CronJobNotification(cronMonitorApp(cronMonitorServer()), 'php artisan backup:run', 'failed', CarbonImmutable::parse('2026-11-15 03:00:00')->getTimestamp(), 3, 'Europe/London'), 'Shop: php artisan backup:run failed', ["It exited with code 3 at Nov 15 at 03:00 GMT. What it printed is in the scheduler log on the app's page.", "You'll get one more email when it runs without an error."]],
    'missed' => [fn() => new CronJobNotification(cronMonitorApp(cronMonitorServer()), 'Laravel scheduler', 'missed', CarbonImmutable::parse('2026-07-15 02:00:00')->getTimestamp(), null, 'Europe/London'), "Shop: Laravel scheduler didn't run", ["It was due at Jul 15 at 03:00 BST and didn't start. Check that cron is running on web-1.", "You'll get one more email when it runs again."]],
    'recovered' => [fn() => new CronJobRecoveredNotification(cronMonitorApp(cronMonitorServer()), 'php artisan backup:run', CarbonImmutable::parse('2026-11-16 03:00:01')->getTimestamp(), 9, 'UTC'), 'Shop: php artisan backup:run ran without an error', ['It ran at Nov 16 at 03:00 UTC and took 9 seconds.']],
]);

test('the feed says which cron job failed, was missed or ran again', function (string $description, array $properties, string $sentence) {
    $application = cronMonitorApp(cronMonitorServer());

    activity()->performedOn($application)->withProperties($properties)->log($description);

    expect(implode('', array_column((new ActivityFeed)->forTeam($application->team)[0]['parts'], 'text')))->toBe($sentence);
})->with([
    'failed' => ['cron_failed', ['job' => 'php artisan backup:run', 'exit' => 3], 'Shop cron job php artisan backup:run failed with exit code 3'],
    'missed' => ['cron_missed', ['job' => 'Laravel scheduler', 'due' => 1794700000], "Shop cron job Laravel scheduler didn't run when it was due"],
    'recovered' => ['cron_recovered', ['job' => 'php artisan backup:run'], 'Shop cron job php artisan backup:run ran without an error again'],
]);

test('a run without an error inside five minutes of the alert leaves it open, so an every-minute job does not flap', function () {
    $server = cronMonitorServer();
    cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server), ['started' => now()->subMinute()->getTimestamp(), 'seconds' => 1, 'exit' => 1]);
    cronMonitorRun($server, $application);

    $this->travel(2)->minutes();
    cronMonitorRun($server, cronMonitorState($application, ['started' => now()->subMinute()->getTimestamp(), 'seconds' => 1, 'exit' => 0]));

    expect(MonitorAlert::query()->sole()->open_key)->not->toBeNull();

    $this->travel(5)->minutes();
    cronMonitorRun($server, cronMonitorState($application, ['started' => now()->subMinute()->getTimestamp(), 'seconds' => 1, 'exit' => 0]));

    expect(MonitorAlert::query()->sole()->open_key)->toBeNull();
    Notification::assertSentTimes(CronJobRecoveredNotification::class, 1);
});

test('a job that fails again within an hour of recovering does not alert again', function () {
    $server = cronMonitorServer();
    cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server), ['started' => now()->subMinute()->getTimestamp(), 'seconds' => 1, 'exit' => 1]);
    cronMonitorRun($server, $application);

    $this->travel(10)->minutes();
    cronMonitorRun($server, cronMonitorState($application, ['started' => now()->subMinute()->getTimestamp(), 'seconds' => 1, 'exit' => 0]));
    $this->travel(10)->minutes();
    cronMonitorRun($server, cronMonitorState($application, ['started' => now()->subMinute()->getTimestamp(), 'seconds' => 1, 'exit' => 2]));

    Notification::assertSentTimes(CronJobNotification::class, 1);

    $this->travel(2)->hours();
    cronMonitorRun($server, cronMonitorState($application, ['started' => now()->subMinute()->getTimestamp(), 'seconds' => 1, 'exit' => 2]));

    Notification::assertSentTimes(CronJobNotification::class, 2);
});

test('an app whose jobs Coresmith has not written since this release is not judged, so an upgrade cannot read them as failed or missed', function () {
    $server = cronMonitorServer();
    cronMonitorMember($server);
    $application = cronMonitorState(cronMonitorApp($server, ['cron_written_at' => null]), ['started' => now()->subDays(2)->setTime(3, 0, 1)->getTimestamp(), 'seconds' => 1, 'exit' => 1]);

    cronMonitorRun($server, $application);

    Notification::assertNothingSent();
    expect(MonitorAlert::query()->exists())->toBeFalse();
});
