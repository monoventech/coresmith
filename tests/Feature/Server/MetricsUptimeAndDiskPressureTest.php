<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\DiskPressureNotification;
use App\Services\Server\MetricsCollector;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function pressureProbe(float|int $disk, ?int $uptime = 93630): string
{
    return "cpu=12.5\nmemory=48.2\ndisk={$disk}\nload=0.42,0.51,0.60\nnet=1000,2000\n" . ($uptime === null ? '' : "uptime={$uptime}\n");
}

function pressureConnection(string $probe, ?Server $server = null): SSHConnection
{
    $server ??= Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);

    return (new SSHConnection($server, (new FakeSSHTransport)->respondTo('top -bn1', $probe)))->connect();
}

function pressureMember(Team $team, array $preferences = []): User
{
    $user = User::factory()->create(['notification_preferences' => $preferences === [] ? null : $preferences]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('the probe asks for uptime and the parser reads it in whole seconds', function () {
    $collector = new MetricsCollector;

    expect($collector->probe())->toContain('/proc/uptime')
        ->and($collector->parse(pressureProbe(40, 93630))['uptime'])->toBe(93630)
        ->and($collector->parse(pressureProbe(40, null))['uptime'])->toBeNull()
        ->and($collector->parse('uptime=abc')['uptime'])->toBeNull();
});

test('the rest of the probe is still parsed alongside uptime', function () {
    expect((new MetricsCollector)->parse(pressureProbe(61)))->toBe([
        'cpu' => 12.5,
        'memory' => 48.2,
        'disk' => 61.0,
        'load1' => 0.42,
        'load5' => 0.51,
        'load15' => 0.6,
        'rx' => 1000,
        'tx' => 2000,
        'uptime' => 93630,
        'memory_used_mb' => null,
        'memory_total_mb' => null,
        'disk_used_mb' => null,
        'disk_free_mb' => null,
        'disk_total_mb' => null,
    ]);
});

test('collecting records when the server booted, to the minute', function () {
    $connection = pressureConnection(pressureProbe(40, 93630));

    (new MetricsCollector)->collect($connection);

    expect($connection->server()->fresh()->booted_at?->toDateTimeString())->toBe('2026-09-12 12:04:00');
});

test('a probe without uptime leaves the recorded boot time alone', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null, 'booted_at' => '2026-09-01 08:00:00']);

    (new MetricsCollector)->collect(pressureConnection(pressureProbe(40, null), $server));

    expect($server->fresh()->booted_at?->toDateTimeString())->toBe('2026-09-01 08:00:00');
});

test('a disk at or above eighty percent is logged as disk pressure with a whole percent and its level', function (float|int $disk, int $logged, string $level) {
    $connection = pressureConnection(pressureProbe($disk));

    (new MetricsCollector)->collect($connection);

    $activity = Activity::query()->where('description', 'disk_pressure')->sole();

    expect($activity->subject_id)->toBe($connection->server()->id)
        ->and($activity->properties->toArray())->toBeInAnyKeyOrder(['percent' => $logged, 'level' => $level]);
})->with([
    'exactly eighty' => [80, 80, 'warning'],
    'rounded up' => [87.6, 88, 'warning'],
    'just under the abort line' => [94, 94, 'warning'],
    'exactly the abort line' => [95, 95, 'critical'],
    'full' => [100, 100, 'critical'],
]);

test('a disk below eighty percent raises nothing', function () {
    Notification::fake();

    (new MetricsCollector)->collect(pressureConnection(pressureProbe(79.9)));

    expect(Activity::query()->where('description', 'disk_pressure')->exists())->toBeFalse();

    Notification::assertNothingSent();
});

test('disk pressure is raised at most once a day per server', function () {
    Notification::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    pressureMember($server->team);

    (new MetricsCollector)->collect(pressureConnection(pressureProbe(90), $server));

    $this->travel(23)->hours();
    (new MetricsCollector)->collect(pressureConnection(pressureProbe(91), $server));

    expect(Activity::query()->where('description', 'disk_pressure')->count())->toBe(1);

    $this->travel(61)->minutes();
    (new MetricsCollector)->collect(pressureConnection(pressureProbe(92), $server));

    expect(Activity::query()->where('description', 'disk_pressure')->count())->toBe(2);

    Notification::assertSentTimes(DiskPressureNotification::class, 2);
});

test('one server under pressure does not silence another', function () {
    (new MetricsCollector)->collect(pressureConnection(pressureProbe(90)));
    (new MetricsCollector)->collect(pressureConnection(pressureProbe(90)));

    expect(Activity::query()->where('description', 'disk_pressure')->count())->toBe(2);
});

test('team members who want disk warnings are emailed about the server', function () {
    Notification::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $wanting = pressureMember($server->team);
    $optedOut = pressureMember($server->team, ['disk_pressure' => false]);
    $outsider = User::factory()->create();

    (new MetricsCollector)->collect(pressureConnection(pressureProbe(86.4), $server));

    Notification::assertSentTo($wanting, DiskPressureNotification::class, fn(DiskPressureNotification $notification): bool => $notification->server->is($server) && $notification->percent === 86);
    Notification::assertNotSentTo($optedOut, DiskPressureNotification::class);
    Notification::assertNotSentTo($outsider, DiskPressureNotification::class);
});

test('a server whose team is gone still logs its disk pressure without emailing anyone', function () {
    Notification::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    pressureMember($server->team);
    $server->team->delete();

    (new MetricsCollector)->collect(pressureConnection(pressureProbe(95), $server->fresh()));

    expect(Activity::query()->where('description', 'disk_pressure')->count())->toBe(1);

    Notification::assertNothingSent();
});
