<?php

declare(strict_types=1);

use App\Enums\MonitorKind;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Monitor;
use App\Models\MonitorAlert;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\ServerMonitor;
use App\Models\User;
use App\Notifications\DiskPressureNotification;
use App\Notifications\MonitorAlertNotification;
use App\Notifications\MonitorRecoveredNotification;
use App\Services\Monitors\ReadingMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

function readingMonitorServer(array $meta = ['cpu_cores' => 4]): Server
{
    return Server::factory()->create(['name' => 'web-1', 'status' => ServerStatus::Active, 'meta' => $meta]);
}

function readingMonitorMember(Server $server, array $preferences = []): User
{
    $user = User::factory()->create(['notification_preferences' => $preferences === [] ? null : $preferences]);
    $server->team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user;
}

function readingMonitorMinutes(Server $server, int $minutes, array $values): void
{
    foreach (range(1, $minutes) as $minute) {
        $metric = ServerMetric::factory()->for($server)->create([...['cpu_percent' => 10, 'memory_percent' => 40, 'disk_percent' => 40, 'load_5m' => 0.4], ...$values, 'recorded_at' => now()]);

        app(ReadingMonitor::class)->evaluate($server, $metric);
        test()->travel(1)->minutes();
    }
}

function readingMonitorLimit(Server $server, MonitorKind $kind, float $threshold, int $duration, bool $enabled = true): Monitor
{
    return Monitor::query()->create(['team_id' => $server->team_id, 'kind' => $kind, 'threshold' => $threshold, 'duration' => $duration, 'enabled' => $enabled]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00'));
    Notification::fake();
});

test('a processor past its limit for ten minutes opens one alert and mails it once', function () {
    $server = readingMonitorServer();
    $member = readingMonitorMember($server);
    readingMonitorMinutes($server, 10, ['cpu_percent' => 20]);

    readingMonitorMinutes($server, 10, ['cpu_percent' => 95]);

    Notification::assertNothingSent();

    readingMonitorMinutes($server, 1, ['cpu_percent' => 96]);

    Notification::assertSentTo($member, MonitorAlertNotification::class, fn(MonitorAlertNotification $notification): bool => $notification->kind === MonitorKind::Cpu && $notification->value === 95.0 && $notification->limit->duration === 10);
    expect(MonitorAlert::query()->sole()->kind)->toBe(MonitorKind::Cpu)
        ->and(Activity::query()->where('description', 'cpu_pressure')->sole()->properties->toArray())->toBeInAnyKeyOrder(['percent' => 95, 'minutes' => 10]);

    readingMonitorMinutes($server, 30, ['cpu_percent' => 99]);

    Notification::assertSentTimes(MonitorAlertNotification::class, 1);
    expect(MonitorAlert::query()->sole()->peak)->toBe(99.0);
});

test('load is measured per core over fifteen minutes', function (float $load, bool $alerted) {
    $server = readingMonitorServer(['cpu_cores' => 4]);
    $member = readingMonitorMember($server);

    readingMonitorMinutes($server, 16, ['load_5m' => $load]);

    expect(MonitorAlert::query()->where('kind', MonitorKind::Load)->exists())->toBe($alerted);

    if ($alerted) {
        Notification::assertSentTo($member, MonitorAlertNotification::class, fn(MonitorAlertNotification $notification): bool => $notification->kind === MonitorKind::Load && $notification->value === $load / 4);
        expect(Activity::query()->where('description', 'load_pressure')->sole()->properties->toArray())->toBeInAnyKeyOrder(['load' => round($load / 4, 2), 'minutes' => 15]);
    }
})->with([
    'past 1.5 per core' => [6.4, true],
    'at the line' => [6.0, true],
    'under it' => [5.9, false],
]);

test('a server whose core count is not known yet raises no load alert', function () {
    $server = readingMonitorServer([]);
    readingMonitorMember($server);

    readingMonitorMinutes($server, 20, ['load_5m' => 40]);

    expect(MonitorAlert::query()->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('an alert closes once its readings have been under the line for its duration, and says how long it lasted', function () {
    $server = readingMonitorServer();
    $member = readingMonitorMember($server);
    readingMonitorMinutes($server, 10, ['cpu_percent' => 20]);
    readingMonitorMinutes($server, 11, ['cpu_percent' => 95]);

    readingMonitorMinutes($server, 10, ['cpu_percent' => 30]);

    Notification::assertNotSentTo($member, MonitorRecoveredNotification::class);

    readingMonitorMinutes($server, 1, ['cpu_percent' => 30]);

    $recovered = Notification::sent($member, MonitorRecoveredNotification::class)->sole();
    $alert = MonitorAlert::query()->sole();

    expect($recovered->kind)->toBe(MonitorKind::Cpu)
        ->and($recovered->minutes)->toBe(11)
        ->and($alert->closed_at)->not->toBeNull()
        ->and($alert->open_key)->toBeNull()
        ->and(Activity::query()->where('description', 'monitor_recovered')->sole()->properties->toArray())->toBeInAnyKeyOrder(['kind' => 'cpu', 'minutes' => 11]);
});

test('a short dip under the line does not close the alert', function () {
    $server = readingMonitorServer();
    $member = readingMonitorMember($server);
    readingMonitorMinutes($server, 5, ['memory_percent' => 97]);

    readingMonitorMinutes($server, 2, ['memory_percent' => 60]);
    readingMonitorMinutes($server, 5, ['memory_percent' => 97]);

    Notification::assertNotSentTo($member, MonitorRecoveredNotification::class);
    Notification::assertSentTimes(MonitorAlertNotification::class, 1);
    expect(MonitorAlert::query()->whereNotNull('open_key')->count())->toBe(1);
});

test('readings that stop never close an alert as recovered', function () {
    $server = readingMonitorServer();
    readingMonitorMember($server);
    readingMonitorMinutes($server, 5, ['memory_percent' => 97]);

    $this->travel(40)->minutes();
    readingMonitorMinutes($server, 2, ['memory_percent' => 30]);

    Notification::assertSentTimes(MonitorRecoveredNotification::class, 0);
    expect(MonitorAlert::query()->sole()->open_key)->not->toBeNull();
});

test('a team limit replaces the default line', function () {
    $server = readingMonitorServer();
    $member = readingMonitorMember($server);
    readingMonitorLimit($server, MonitorKind::Cpu, 70, 5);

    readingMonitorMinutes($server, 6, ['cpu_percent' => 75]);

    Notification::assertSentTo($member, MonitorAlertNotification::class, fn(MonitorAlertNotification $notification): bool => $notification->kind === MonitorKind::Cpu && $notification->limit->threshold === 70.0 && $notification->limit->source === 'team');
});

test('a server limit wins over the team limit for that server only', function () {
    $server = readingMonitorServer();
    $other = Server::factory()->for($server->team)->create(['status' => ServerStatus::Active]);
    readingMonitorMember($server);
    readingMonitorLimit($server, MonitorKind::Cpu, 70, 5);
    ServerMonitor::query()->create(['team_id' => $server->team_id, 'server_id' => $server->id, 'kind' => MonitorKind::Cpu, 'threshold' => 80, 'duration' => 5]);

    readingMonitorMinutes($server, 6, ['cpu_percent' => 75]);
    readingMonitorMinutes($other, 6, ['cpu_percent' => 75]);

    expect(MonitorAlert::query()->pluck('server_id')->all())->toBe([$other->id]);
});

test('a limit raised while an alert is open closes it by the new line, with no second alert', function () {
    $server = readingMonitorServer();
    readingMonitorMember($server);
    readingMonitorMinutes($server, 5, ['memory_percent' => 97]);

    readingMonitorLimit($server, MonitorKind::Memory, 99, 4);
    readingMonitorMinutes($server, 6, ['memory_percent' => 97]);

    Notification::assertSentTimes(MonitorAlertNotification::class, 1);
    Notification::assertSentTimes(MonitorRecoveredNotification::class, 1);
    expect(MonitorAlert::query()->sole()->open_key)->toBeNull();
});

test('a limit lowered while an alert is open keeps it open without a second alert', function () {
    $server = readingMonitorServer();
    readingMonitorMember($server);
    readingMonitorMinutes($server, 5, ['memory_percent' => 97]);

    readingMonitorLimit($server, MonitorKind::Memory, 90, 2);
    readingMonitorMinutes($server, 10, ['memory_percent' => 96]);

    Notification::assertSentTimes(MonitorAlertNotification::class, 1);
    Notification::assertSentTimes(MonitorRecoveredNotification::class, 0);
});

test('turning a monitor off closes its open alert without a mail or a feed row', function () {
    $server = readingMonitorServer();
    readingMonitorMember($server);
    readingMonitorMinutes($server, 11, ['cpu_percent' => 95]);

    readingMonitorLimit($server, MonitorKind::Cpu, 90, 10, enabled: false);
    readingMonitorMinutes($server, 3, ['cpu_percent' => 95]);

    Notification::assertSentTimes(MonitorAlertNotification::class, 1);
    Notification::assertSentTimes(MonitorRecoveredNotification::class, 0);
    expect(MonitorAlert::query()->sole()->open_key)->toBeNull()
        ->and(Activity::query()->where('description', 'monitor_recovered')->exists())->toBeFalse();
});

test('someone who turned server problems off gets no processor or recovery mail, and the alert still opens and closes', function () {
    $server = readingMonitorServer();
    $quiet = readingMonitorMember($server, ['server_alerts' => false]);

    readingMonitorMinutes($server, 11, ['cpu_percent' => 95]);
    readingMonitorMinutes($server, 11, ['cpu_percent' => 30]);

    Notification::assertNothingSentTo($quiet);
    expect(Activity::query()->where('description', 'cpu_pressure')->count())->toBe(1)
        ->and(Activity::query()->where('description', 'monitor_recovered')->count())->toBe(1);
});

test('an alert another worker opened first is left alone and mailed only by that worker', function () {
    $server = readingMonitorServer();
    readingMonitorMember($server);
    readingMonitorMinutes($server, 2, ['memory_percent' => 97]);
    $key = MonitorAlert::keyFor($server, MonitorKind::Memory);
    $raced = false;

    DB::listen(function (QueryExecuted $query) use ($server, $key, &$raced): void {
        if ($raced || !str_contains($query->sql, 'monitor_alerts') || !in_array($server->id, $query->bindings, true) || !str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }

        $raced = true;
        DB::table('monitor_alerts')->insert(['id' => (string) Str::ulid(), 'team_id' => $server->team_id, 'server_id' => $server->id, 'kind' => 'memory', 'opened_at' => now(), 'open_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
    });

    readingMonitorMinutes($server, 3, ['memory_percent' => 97]);

    expect($raced)->toBeTrue()
        ->and(MonitorAlert::query()->where('kind', MonitorKind::Memory)->count())->toBe(1);
    Notification::assertSentTimes(MonitorAlertNotification::class, 0);
});

test('a critical disk mail that cannot be sent is tried again the next minute', function () {
    Exceptions::fake();
    $server = readingMonitorServer();
    $member = readingMonitorMember($server);
    readingMonitorMinutes($server, 1, ['disk_percent' => 85]);

    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));
    readingMonitorMinutes($server, 1, ['disk_percent' => 96]);

    expect(MonitorAlert::query()->sole()->level)->toBe('warning');
    Exceptions::assertReportedCount(1);

    Notification::fake();
    readingMonitorMinutes($server, 1, ['disk_percent' => 96]);

    Notification::assertSentTo($member, DiskPressureNotification::class, fn(DiskPressureNotification $notification): bool => $notification->critical && $notification->percent === 96);
    expect(MonitorAlert::query()->sole()->level)->toBe('critical')
        ->and(Activity::query()->where('description', 'disk_pressure')->oldest('id')->get()->map(fn(Activity $row): string => $row->properties['level'])->all())->toBe(['warning', 'critical']);
});

test('a removed server drops its open alerts quietly', function () {
    $server = readingMonitorServer();
    readingMonitorMember($server);
    readingMonitorMinutes($server, 11, ['cpu_percent' => 95, 'memory_percent' => 97]);

    $server->delete();

    expect(MonitorAlert::query()->whereNotNull('open_key')->exists())->toBeFalse()
        ->and(MonitorAlert::query()->count())->toBe(2);
    Notification::assertSentTimes(MonitorRecoveredNotification::class, 0);
});

test('the evaluator watches disk, memory, processor and load, each with its own feed row', function () {
    expect(ReadingMonitor::KINDS)->toBe([MonitorKind::Disk, MonitorKind::Memory, MonitorKind::Cpu, MonitorKind::Load])
        ->and(ReadingMonitor::ACTIVITY)->toBe(['disk' => 'disk_pressure', 'memory' => 'memory_pressure', 'cpu' => 'cpu_pressure', 'load' => 'load_pressure'])
        ->and(ReadingMonitor::RECOVERY_MINUTES)->toBe(5);
});
