<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Events\ServerProvisioned;
use App\Events\ServerProvisioningFailed;
use App\Jobs\CollectServerMetrics;
use App\Listeners\NotifyProvisioning;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApplicationOomKilledNotification;
use App\Notifications\DiskPressureNotification;
use App\Notifications\MemoryPressureNotification;
use App\Notifications\Preferences;
use App\Notifications\ProvisioningFailedNotification;
use App\Notifications\ServerProvisionedNotification;
use App\Notifications\TeamNotifier;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\FleetSnapshot;
use App\Services\Server\MetricsCollector;
use App\Services\Server\ServerReachability;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function alertsMember(Team $team, array $preferences = []): User
{
    $user = User::factory()->create(['notification_preferences' => $preferences === [] ? null : $preferences]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user;
}

function alertsProbe(int $memory = 40, int $disk = 40): string
{
    return "cpu=12.5\nmemory={$memory}\ndisk={$disk}\nload=0.42,0.51,0.60\nnet=1000,2000\nuptime=93630\n";
}

function alertsConnection(Server $server, string $probe): SSHConnection
{
    return (new SSHConnection($server, (new FakeSSHTransport)->respondTo('top -bn1', $probe)))->connect();
}

function alertsServer(): Server
{
    return Server::factory()->create(['name' => 'web-1', 'status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
}

function alertsCollect(Server $server, int $memory = 40, int $disk = 40): ServerMetric
{
    return (new MetricsCollector)->collect(alertsConnection($server, alertsProbe($memory, $disk)));
}

function alertsReading(Server $server, int|float $memory, int $minutesAgo): ServerMetric
{
    return ServerMetric::factory()->for($server)->create(['memory_percent' => $memory, 'disk_percent' => 40, 'recorded_at' => now()->subMinutes($minutesAgo)]);
}

function alertsRow(Model $subject, string $description, array $properties, CarbonInterface $at): void
{
    activity()->performedOn($subject)->withProperties($properties)->log($description);

    Activity::query()->latest('id')->firstOrFail()->forceFill(['created_at' => $at])->save();
}

function alertsKill(string $unit, int $secondsAgo): string
{
    return json_encode([
        'UNIT' => $unit . '.service',
        'MESSAGE' => $unit . '.service: A process of this unit has been killed by the OOM killer.',
        '__REALTIME_TIMESTAMP' => (string) (now()->subSeconds($secondsAgo)->getTimestamp() * 1000000),
    ], JSON_THROW_ON_ERROR);
}

function alertsRead(Server $server, string ...$lines): void
{
    $transport = (new FakeSSHTransport)->respondTo('journalctl --since', implode("\n", $lines));

    app(MetricsCollector::class)->recordOomKills((new SSHConnection($server, $transport))->connect());
}

function alertsApplication(Server $server, string $slug, array $attributes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create(array_merge(['name' => ucfirst($slug), 'slug' => $slug], $attributes));
}

function alertsRun(Server $server, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new CollectServerMetrics($server))->handle(app(SSHConnectionManager::class), app(MetricsCollector::class), app(ServerReachability::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function alertsSent(User $user, string $class): array
{
    return Notification::sent($user, $class)->values()->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 14:05:09'));
});

test('the provisioning listener hears both a finished and a stopped provision', function () {
    Event::fake();

    Event::assertListening(ServerProvisioned::class, NotifyProvisioning::class);
    Event::assertListening(ServerProvisioningFailed::class, NotifyProvisioning::class);
});

test('a finished provision emails the team', function () {
    Notification::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);
    $outsider = alertsMember(Team::factory()->create());

    (new NotifyProvisioning(app(TeamNotifier::class)))->handle(new ServerProvisioned($server, 372));

    Notification::assertSentTo($member, ServerProvisionedNotification::class, fn(ServerProvisionedNotification $notification): bool => $notification->server->is($server) && $notification->durationSeconds === 372);
    Notification::assertNotSentTo($outsider, ServerProvisionedNotification::class);

    $mail = alertsSent($member, ServerProvisionedNotification::class)[0]->toMail($member);

    expect($mail->subject)->toBe('web-1 is ready')
        ->and($mail->introLines)->toBe(['Provisioned and hardened in 6m 12s. All eight phases passed.', 'Add an app to deploy to it.'])
        ->and($mail->actionText)->toBe('Open web-1')
        ->and($mail->actionUrl)->toBe(route('servers.show', $server));
});

test('a provision that stopped emails the team', function () {
    Notification::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);

    ServerProvisioningFailed::dispatch($server, 2, 'Base system');

    Notification::assertSentTo($member, ProvisioningFailedNotification::class, fn(ProvisioningFailedNotification $notification): bool => $notification->server->is($server) && $notification->phase === 2 && $notification->phaseName === 'Base system');
});

test('people who turned provisioning off are not emailed', function () {
    Notification::fake();
    $server = alertsServer();
    $wanting = alertsMember($server->team);
    $others = alertsMember($server->team, ['server_alerts' => false, 'disk_pressure' => false]);
    $optedOut = alertsMember($server->team, ['provisioning' => false]);

    ServerProvisioned::dispatch($server, 372);
    ServerProvisioningFailed::dispatch($server, 2, 'Base system');

    Notification::assertSentTo([$wanting, $others], ServerProvisionedNotification::class);
    Notification::assertSentTo([$wanting, $others], ProvisioningFailedNotification::class);
    Notification::assertNotSentTo($optedOut, ServerProvisionedNotification::class);
    Notification::assertNotSentTo($optedOut, ProvisioningFailedNotification::class);
});

test('a provision on a server whose team is gone emails nobody', function () {
    Notification::fake();
    $server = alertsServer();
    alertsMember($server->team);
    $server->team->delete();

    ServerProvisioned::dispatch($server->fresh(), 372);
    ServerProvisioningFailed::dispatch($server->fresh(), 2, 'Base system');

    Notification::assertNothingSent();
});

test('a mail that cannot be queued is reported and never thrown at the provision', function (string $stage) {
    Exceptions::fake();
    $server = alertsServer();
    alertsMember($server->team);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));

    app(NotifyProvisioning::class)->handle($stage === 'finished' ? new ServerProvisioned($server, 372) : new ServerProvisioningFailed($server, 2, 'Base system'));

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Queue is down');
})->with([
    'a finished provision' => ['finished'],
    'a provision that stopped' => ['stopped'],
]);

test('disk at 95% sends the critical mail even after today\'s warning', function () {
    Notification::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);
    alertsRow($server, 'disk_pressure', ['percent' => 85, 'level' => 'warning'], now()->subHours(5));

    alertsCollect($server, disk: 96);

    Notification::assertSentTo($member, DiskPressureNotification::class, fn(DiskPressureNotification $notification): bool => $notification->critical && $notification->percent === 96 && $notification->server->is($server));

    alertsCollect($server, disk: 96);

    Notification::assertSentTimes(DiskPressureNotification::class, 1);

    $rows = Activity::query()->where('description', 'disk_pressure')->oldest('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->properties->toArray())->toBeInAnyKeyOrder(['percent' => 96, 'level' => 'critical']);
});

test('a warning row without a level still counts as today\'s warning', function () {
    Notification::fake();
    $server = alertsServer();
    alertsMember($server->team);
    alertsRow($server, 'disk_pressure', ['percent' => 82], now()->subHours(5));

    alertsCollect($server, disk: 85);

    Notification::assertNothingSent();
    expect(Activity::query()->where('description', 'disk_pressure')->count())->toBe(1);
});

test('the warning and the critical alert each go once a day', function () {
    Notification::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);

    alertsCollect($server, disk: 85);
    alertsCollect($server, disk: 96);
    alertsCollect($server, disk: 86);
    alertsCollect($server, disk: 97);

    expect(array_map(fn(DiskPressureNotification $notification): bool => $notification->critical, alertsSent($member, DiskPressureNotification::class)))->toBe([false, true]);

    $this->travel(25)->hours();
    alertsCollect($server, disk: 96);

    expect(array_map(fn(DiskPressureNotification $notification): bool => $notification->critical, alertsSent($member, DiskPressureNotification::class)))->toBe([false, true, true])
        ->and(Activity::query()->where('description', 'disk_pressure')->oldest('id')->get()->map(fn(Activity $row): string => $row->properties['level'])->all())->toBe(['warning', 'critical', 'critical']);
});

test('both disk mails follow Disk above 80%, not Server problems', function () {
    Notification::fake();
    $server = alertsServer();
    $wanting = alertsMember($server->team);
    $noServerAlerts = alertsMember($server->team, ['server_alerts' => false]);
    $noDisk = alertsMember($server->team, ['disk_pressure' => false]);

    alertsCollect($server, disk: 85);
    alertsCollect($server, disk: 96);

    Notification::assertSentToTimes($wanting, DiskPressureNotification::class, 2);
    Notification::assertSentToTimes($noServerAlerts, DiskPressureNotification::class, 2);
    Notification::assertNotSentTo($noDisk, DiskPressureNotification::class);
});

test('a disk alert that cannot be sent is reported, leaves no row and is retried the next minute', function () {
    Exceptions::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));

    $metric = alertsCollect($server, disk: 96);

    expect($metric->exists)->toBeTrue()
        ->and(Activity::query()->where('description', 'disk_pressure')->exists())->toBeFalse();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Queue is down');

    Notification::fake();
    $this->travel(1)->minutes();
    alertsCollect($server, disk: 96);

    Notification::assertSentTo($member, DiskPressureNotification::class, fn(DiskPressureNotification $notification): bool => $notification->critical && $notification->percent === 96);
    expect(Activity::query()->where('description', 'disk_pressure')->count())->toBe(1);
});

test('memory at 95% for three readings emails once a day', function () {
    Notification::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);
    alertsReading($server, 95, minutesAgo: 2);
    alertsReading($server, 96, minutesAgo: 1);

    alertsCollect($server, memory: 98);

    Notification::assertSentTo($member, MemoryPressureNotification::class, fn(MemoryPressureNotification $notification): bool => $notification->server->is($server) && $notification->percent === 95);
    expect(Activity::query()->where('description', 'memory_pressure')->sole()->properties->toArray())->toBe(['percent' => 95]);

    $this->travel(1)->minutes();
    alertsCollect($server, memory: 99);

    Notification::assertSentTimes(MemoryPressureNotification::class, 1);
    expect(Activity::query()->where('description', 'memory_pressure')->count())->toBe(1);

    $this->travel(25)->hours();

    foreach (range(1, 3) as $reading) {
        alertsCollect($server, memory: 99);
        $this->travel(1)->minutes();
    }

    Notification::assertSentTimes(MemoryPressureNotification::class, 2);
    expect(Activity::query()->where('description', 'memory_pressure')->count())->toBe(2);
});

test('the memory mail names the lowest of the three readings, rounded down', function (array $earlier, int $latest, int $named) {
    Notification::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);

    foreach ($earlier as [$memory, $minutesAgo]) {
        alertsReading($server, $memory, $minutesAgo);
    }

    alertsCollect($server, memory: $latest);

    $notification = alertsSent($member, MemoryPressureNotification::class)[0];
    $mail = $notification->toMail($member);

    expect($notification->percent)->toBe($named)
        ->and($mail->subject)->toBe("web-1 memory is at {$named}%")
        ->and($mail->introLines[0])->toBe("Memory has stayed at {$named}% or more for three minutes. An app that asks for more is killed and started again.")
        ->and(Activity::query()->where('description', 'memory_pressure')->sole()->properties->toArray())->toBe(['percent' => $named]);
})->with([
    'the first is the lowest' => [[[95, 2], [96, 1]], 98, 95],
    'the middle is the lowest' => [[[98, 2], [96, 1]], 97, 96],
    'the latest is the lowest' => [[[98, 2], [97, 1]], 96, 96],
    'a fraction is rounded down, never up' => [[[95.6, 2], [97, 1]], 98, 95],
]);

test('a memory alert that cannot be sent is reported, leaves no row and is retried the next minute', function () {
    Exceptions::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);
    alertsReading($server, 96, minutesAgo: 2);
    alertsReading($server, 97, minutesAgo: 1);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));

    $metric = alertsCollect($server, memory: 98);

    expect($metric->exists)->toBeTrue()
        ->and(Activity::query()->where('description', 'memory_pressure')->exists())->toBeFalse();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Queue is down');

    Notification::fake();
    $this->travel(1)->minutes();
    alertsCollect($server, memory: 99);

    Notification::assertSentTo($member, MemoryPressureNotification::class, fn(MemoryPressureNotification $notification): bool => $notification->percent === 97);
    expect(Activity::query()->where('description', 'memory_pressure')->count())->toBe(1);
});

test('a memory spike does not email', function (array $readings) {
    Notification::fake();
    $server = alertsServer();
    alertsMember($server->team);

    foreach ($readings as [$memory, $minutesAgo]) {
        alertsReading($server, $memory, $minutesAgo);
    }

    alertsCollect($server, memory: 98);

    Notification::assertNothingSent();
    expect(Activity::query()->where('description', 'memory_pressure')->exists())->toBeFalse();
})->with([
    'a low reading in between' => [[[40, 2], [96, 1]]],
    'high readings too long ago' => [[[96, 7], [97, 6]]],
    'only one earlier reading' => [[[97, 1]]],
    'no earlier readings' => [[]],
]);

test('the memory line is 95%: at it counts, under it does not', function (int $first, int $second, int $latest, bool $emailed) {
    Notification::fake();
    $server = alertsServer();
    alertsMember($server->team);
    alertsReading($server, $first, minutesAgo: 2);
    alertsReading($server, $second, minutesAgo: 1);

    alertsCollect($server, memory: $latest);

    expect(Activity::query()->where('description', 'memory_pressure')->exists())->toBe($emailed);

    if ($emailed) {
        Notification::assertSentTimes(MemoryPressureNotification::class, 1);
    } else {
        Notification::assertNothingSent();
    }
})->with([
    'all three at the line' => [95, 95, 95, true],
    'the first under it' => [94, 96, 96, false],
    'the second under it' => [96, 94, 96, false],
    'the latest under it' => [97, 97, 94, false],
]);

test('another server\'s readings do not count towards this one', function () {
    Notification::fake();
    $server = alertsServer();
    $other = alertsServer();
    alertsMember($server->team);
    alertsMember($other->team);
    alertsReading($other, 97, minutesAgo: 2);
    alertsReading($other, 97, minutesAgo: 1);
    alertsReading($server, 97, minutesAgo: 1);

    alertsCollect($server, memory: 98);

    Notification::assertNothingSent();
});

test('memory and memory kill mails follow Server problems, not the disk or provisioning rows', function () {
    Notification::fake();
    $server = alertsServer();
    alertsApplication($server, 'shop');
    $wanting = alertsMember($server->team);
    $quiet = alertsMember($server->team, ['server_alerts' => false]);
    $noDisk = alertsMember($server->team, ['disk_pressure' => false, 'provisioning' => false]);
    alertsReading($server, 96, minutesAgo: 2);
    alertsReading($server, 97, minutesAgo: 1);

    alertsCollect($server, memory: 98);
    alertsRead($server, alertsKill('coresmith-app-shop-web', 20));

    Notification::assertSentTo([$wanting, $noDisk], MemoryPressureNotification::class);
    Notification::assertSentTo([$wanting, $noDisk], ApplicationOomKilledNotification::class);
    Notification::assertNotSentTo($quiet, MemoryPressureNotification::class);
    Notification::assertNotSentTo($quiet, ApplicationOomKilledNotification::class);
});

test('the first memory kill of the day emails, the rest are only logged', function () {
    Notification::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);
    $shop = alertsApplication($server, 'shop', ['app_size' => 'medium']);
    $blog = alertsApplication($server, 'blog');

    alertsRead(
        $server,
        alertsKill('coresmith-app-shop-web', 50),
        alertsKill('coresmith-app-shop-queue-worker', 30),
        alertsKill('coresmith-app-blog-web', 20),
    );

    $kills = Activity::query()->where('description', 'app_oom_killed')->get();

    expect($kills->where('subject_id', $shop->id))->toHaveCount(2)
        ->and($kills->where('subject_id', $blog->id))->toHaveCount(1);

    Notification::assertSentTimes(ApplicationOomKilledNotification::class, 2);
    Notification::assertSentTo($member, ApplicationOomKilledNotification::class, fn(ApplicationOomKilledNotification $notification): bool => $notification->application->is($shop) && $notification->process === 'web' && $notification->limitMb === 512 && $notification->at->toIso8601String() === '2026-09-14T14:04:19+00:00');
    Notification::assertSentTo($member, ApplicationOomKilledNotification::class, fn(ApplicationOomKilledNotification $notification): bool => $notification->application->is($blog) && $notification->process === 'web' && $notification->limitMb === 256 && $notification->at->toIso8601String() === '2026-09-14T14:04:49+00:00');

    $this->travel(2)->minutes();
    alertsRead($server, alertsKill('coresmith-app-shop-web', 5));

    Notification::assertSentTimes(ApplicationOomKilledNotification::class, 2);
    expect(Activity::query()->where('description', 'app_oom_killed')->where('subject_id', $shop->id)->count())->toBe(3);

    $this->travel(10)->hours();
    alertsRead($server, alertsKill('coresmith-app-shop-web', 5));

    Notification::assertSentTimes(ApplicationOomKilledNotification::class, 2);

    $this->travel(25)->hours();
    alertsRead($server, alertsKill('coresmith-app-shop-web', 5));

    Notification::assertSentTimes(ApplicationOomKilledNotification::class, 3);
});

test('a kill that was already logged sends nothing again', function () {
    Notification::fake();
    $server = alertsServer();
    alertsMember($server->team);
    alertsApplication($server, 'shop');
    $line = alertsKill('coresmith-app-shop-web', 50);

    alertsRead($server, $line);
    alertsRead($server, $line);

    Notification::assertSentTimes(ApplicationOomKilledNotification::class, 1);
    expect(Activity::query()->where('description', 'app_oom_killed')->count())->toBe(1);
});

test('a memory kill alert that cannot be sent is reported, leaves no row and is retried the next minute', function () {
    Exceptions::fake();
    $server = alertsServer();
    $member = alertsMember($server->team);
    $shop = alertsApplication($server, 'shop');
    $line = alertsKill('coresmith-app-shop-web', 20);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));

    alertsRead($server, $line);

    expect(Activity::query()->where('description', 'app_oom_killed')->exists())->toBeFalse();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Queue is down');

    Notification::fake();
    $this->travel(1)->minutes();
    alertsRead($server, $line);

    Notification::assertSentTo($member, ApplicationOomKilledNotification::class, fn(ApplicationOomKilledNotification $notification): bool => $notification->application->is($shop));
    expect(Activity::query()->where('description', 'app_oom_killed')->count())->toBe(1);
});

test('one app\'s failed kill alert does not drop another app\'s kill', function () {
    Exceptions::fake();
    $server = alertsServer();
    alertsMember($server->team);
    alertsApplication($server, 'shop');
    $blog = alertsApplication($server, 'blog');
    $sends = 0;
    Notification::shouldReceive('send')->twice()->andReturnUsing(function () use (&$sends): void {
        if (++$sends === 1) {
            throw new RuntimeException('Queue is down');
        }
    });

    alertsRead($server, alertsKill('coresmith-app-shop-web', 30), alertsKill('coresmith-app-blog-web', 20));

    expect(Activity::query()->where('description', 'app_oom_killed')->pluck('subject_id')->all())->toBe([$blog->id]);
    Exceptions::assertReportedCount(1);
});

test('a failed alert never stops the rest of the collection', function () {
    Exceptions::fake();
    $server = alertsServer();
    alertsMember($server->team);
    $shop = alertsApplication($server, 'shop');
    alertsRow($shop, 'app_oom_killed', ['process' => 'web', 'at' => '2026-09-14T11:59:00+00:00', 'limit_mb' => 256], now()->subHours(2));
    $server->forceFill(['connection_failures' => 2, 'last_connection_error' => 'The server took the connection but stopped answering. It may be overloaded or rebooting.'])->save();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));

    alertsRun($server, (new FakeSSHTransport)
        ->respondTo('top -bn1', alertsProbe(disk: 96))
        ->respondTo('journalctl --since', alertsKill('coresmith-app-shop-web', 20)));

    expect(ServerMetric::query()->where('server_id', $server->id)->count())->toBe(1)
        ->and($server->fresh()->connection_failures)->toBe(0)
        ->and(Activity::query()->where('description', 'disk_pressure')->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'app_oom_killed')->count())->toBe(2);
    Exceptions::assertReportedCount(1);
});

test('a server whose team is gone still logs its memory alerts without emailing anyone', function () {
    Notification::fake();
    $server = alertsServer();
    alertsMember($server->team);
    alertsApplication($server, 'shop');
    alertsReading($server, 96, minutesAgo: 2);
    alertsReading($server, 97, minutesAgo: 1);
    $server->team->delete();
    $server = $server->fresh();

    alertsCollect($server, memory: 98);
    alertsRead($server, alertsKill('coresmith-app-shop-web', 20));

    Notification::assertNothingSent();
    expect(Activity::query()->where('description', 'memory_pressure')->count())->toBe(1)
        ->and(Activity::query()->where('description', 'app_oom_killed')->count())->toBe(1);
});

test('the memory lines of the fleet and of the email are the same 95%', function () {
    expect(MetricsCollector::MEMORY_PRESSURE_PERCENT)->toBe(95)
        ->and(MetricsCollector::MEMORY_PRESSURE_READINGS)->toBe(3)
        ->and(FleetSnapshot::MEMORY_WARNING)->toBe(MetricsCollector::MEMORY_PRESSURE_PERCENT);
});

test('each alert says what happened and what to do', function (string $alert, string $subject, array $lines, string $action) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Shop', 'app_size' => 'medium']);

    $notification = match ($alert) {
        'provisioning stopped' => new ProvisioningFailedNotification($server, 2, 'Base system'),
        'killed for memory' => new ApplicationOomKilledNotification($application, 'web', 512, CarbonImmutable::parse('2026-09-14 09:59:10')),
        'memory' => new MemoryPressureNotification($server, 98),
        'disk' => new DiskPressureNotification($server, 96, critical: true),
    };

    $mail = $notification->toMail(new User);

    expect($mail->subject)->toBe($subject)
        ->and($mail->introLines)->toBe($lines)
        ->and($mail->actionText)->toBe($action)
        ->and($mail->actionUrl)->toBe($alert === 'killed for memory' ? route('servers.applications.show', [$server, $application]) : route('servers.show', $server));
})->with([
    'provisioning stopped' => [
        'provisioning stopped',
        'Provisioning stopped on web-1',
        ['Phase 2 of 8, Base system, failed, so web-1 is not ready yet.', 'Fix what the log shows, then retry from that phase on the server page.'],
        'Open web-1',
    ],
    'killed for memory' => [
        'killed for memory',
        'Shop was killed for memory',
        ['The web process on web-1 went over its 512 MB memory limit at Sep 14, 2026 at 09:59 UTC, and it was started again.', 'If it keeps happening, the app needs more memory than the Medium size gives it.'],
        'Open Shop',
    ],
    'memory' => [
        'memory',
        'web-1 memory is at 98%',
        ['Memory has stayed at 98% or more for three minutes. An app that asks for more is killed and started again.', 'Check which process is using it on the server.'],
        'Open web-1',
    ],
    'disk' => [
        'disk',
        'web-1 disk is at 96%, deploys are paused',
        ['Coresmith stops deploying to a server whose disk is 95% full, so deploys there fail until space is freed.', 'Pruning keeps the last 5 releases of each app, plus the live one.'],
        'Open web-1',
    ],
]);

test('the kill mail names the size the app page names', function (string $size, string $named) {
    $application = Application::factory()->create(['name' => 'Shop', 'app_size' => $size]);

    $mail = (new ApplicationOomKilledNotification($application, 'queue-worker', 256, CarbonImmutable::parse('2026-09-14 09:59:10')))->toMail(new User);

    expect($mail->introLines[1])->toBe("If it keeps happening, the app needs more memory than the {$named} size gives it.");
})->with([
    'small' => ['small', 'Small'],
    'medium' => ['medium', 'Medium'],
    'large' => ['large', 'Large'],
    'custom' => ['custom', 'Custom'],
    'a size that is not known' => ['legacy', 'Small'],
]);

test('the warning disk mail is the one it always was', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    $mail = (new DiskPressureNotification($server, 86))->toMail(new User);

    expect($mail->subject)->toBe('web-1 disk is at 86%')
        ->and($mail->introLines)->toBe([
            'Deploys stop at 95%, so there is still room, but not much.',
            'Pruning keeps the last 5 releases of each app, plus the live one.',
        ]);
});

test('every new alert is a queued email', function (object $notification) {
    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via(new User))->toBe(['mail']);
})->with([
    'provisioned' => fn() => new ServerProvisionedNotification(new Server, 372),
    'provisioning stopped' => fn() => new ProvisioningFailedNotification(new Server, 2, 'Base system'),
    'memory' => fn() => new MemoryPressureNotification(new Server, 98),
    'killed for memory' => fn() => new ApplicationOomKilledNotification(new Application, 'web', 512, CarbonImmutable::now()),
]);

test('provisioning is a preference of its own, on by default', function () {
    expect(Preferences::PROVISIONING)->toBe('provisioning')
        ->and(Preferences::DEFAULTS[Preferences::PROVISIONING])->toBeTrue()
        ->and(Preferences::LABELS[Preferences::PROVISIONING])->toBe('Provisioning')
        ->and(Preferences::DESCRIPTIONS[Preferences::PROVISIONING])->toBe("When a server is ready, provisioning stops partway, or its provider can't create, resize or destroy it")
        ->and(array_keys(Preferences::DEFAULTS))->toBe(['deploy_failed', 'disk_pressure', 'server_alerts', 'provisioning', 'toggle_changes', 'weekly_digest']);
});

test('the fleet shows sustained memory as a warning', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    ServerMetric::factory()->for($server)->create(['memory_percent' => 97, 'disk_percent' => 40, 'recorded_at' => now()->subMinute()]);

    $fleet = new FleetSnapshot($team);

    expect($fleet->state($server))->toBe(['key' => 'memory', 'label' => 'Memory 97%', 'tone' => 'warning'])
        ->and($fleet->card($server)['state'])->toBe(['key' => 'memory', 'label' => 'Memory 97%', 'tone' => 'warning'])
        ->and($fleet->attention())->toBe([
            ['severity' => 'warning', 'subject' => 'web-1', 'summary' => 'memory 97%', 'href' => route('servers.show', $server)],
        ]);
});

test('memory wins over the disk warning on the same server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    ServerMetric::factory()->for($server)->create(['memory_percent' => 97, 'disk_percent' => 85, 'recorded_at' => now()->subMinute()]);

    $fleet = new FleetSnapshot($team);

    expect($fleet->state($server)['key'])->toBe('memory')
        ->and($fleet->attention())->toBe([
            ['severity' => 'warning', 'subject' => 'web-1', 'summary' => 'memory 97%', 'href' => route('servers.show', $server)],
        ]);
});

test('memory takes its place in the fleet precedence', function (array $attributes, float $memory, bool $deploying, int $minutesAgo, string $key, string $label) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(array_merge(['status' => ServerStatus::Active], $attributes));
    ServerMetric::factory()->for($server)->create(['memory_percent' => $memory, 'disk_percent' => 40, 'recorded_at' => now()->subMinutes($minutesAgo)]);

    if ($deploying) {
        Deployment::factory()->for(Application::factory()->for($team)->for($server))->create(['status' => DeploymentStatus::Running, 'started_at' => now()]);
    }

    expect((new FleetSnapshot($team))->state($server->fresh()))->toMatchArray(['key' => $key, 'label' => $label]);
})->with([
    'at the line' => [[], 95.0, false, 1, 'memory', 'Memory 95%'],
    'rounded in the label' => [[], 96.6, false, 1, 'memory', 'Memory 97%'],
    'just under the line' => [[], 94.9, false, 1, 'healthy', 'Healthy'],
    'a stale reading' => [[], 98.0, false, FleetSnapshot::FRESH_METRIC_MINUTES + 1, 'healthy', 'Healthy'],
    'deploying comes first' => [[], 98.0, true, 1, 'deploying', 'Deploying'],
    'unreachable comes first' => [['unreachable_since' => '2026-09-14 13:50:00'], 98.0, false, 1, 'unreachable', 'Unreachable'],
    'a changed host key comes first' => [['pending_host_key_fingerprint' => 'SHA256:new'], 98.0, false, 1, 'host_key', 'Host key changed'],
]);

test('the feed describes memory, critical disk and a stopped provision', function (string $description, array $properties, string $sentence) {
    $server = alertsServer();

    activity()->performedOn($server)->withProperties($properties)->log($description);

    $row = (new ActivityFeed)->forServer($server)[0];

    expect(implode('', array_column($row['parts'], 'text')))->toBe($sentence)
        ->and($row['parts'][1])->toBe(['text' => 'web-1', 'mono' => true])
        ->and($row['href'])->toBe(route('servers.show', $server));
})->with([
    'sustained memory' => ['memory_pressure', ['percent' => 98], 'Memory warning, web-1 at 98% for three minutes'],
    'critical disk' => ['disk_pressure', ['percent' => 96, 'level' => 'critical'], 'Disk at 96% on web-1, deploys are paused until space is freed'],
    'a disk warning with its level' => ['disk_pressure', ['percent' => 86, 'level' => 'warning'], 'Disk warning, web-1 at 86%, consider pruning old releases'],
    'a disk warning from before levels' => ['disk_pressure', ['percent' => 86], 'Disk warning, web-1 at 86%, consider pruning old releases'],
    'a stopped provision' => ['provisioning_failed', ['phase' => 2, 'name' => 'Base system'], 'Provisioning stopped on web-1 at phase 2, Base system'],
]);

test('the team feed carries the new rows too', function () {
    $server = alertsServer();

    activity()->performedOn($server)->withProperties(['percent' => 98])->log('memory_pressure');

    $rows = (new ActivityFeed)->forTeam($server->team);

    expect($rows)->toHaveCount(1)
        ->and(implode('', array_column($rows[0]['parts'], 'text')))->toBe('Memory warning, web-1 at 98% for three minutes');
});
