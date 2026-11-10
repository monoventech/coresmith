<?php

declare(strict_types=1);

use App\Console\Commands\PruneMetricsCommand;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\FleetSnapshot;
use App\Services\Server\MetricsCollector;
use Illuminate\Console\Scheduling\Schedule;

function metricsUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('the probe reads network counters from the physical interfaces', function () {
    $probe = (new MetricsCollector)->probe();

    expect($probe)->toContain('/proc/net/dev')
        ->and($probe)->toContain('eth')
        ->and($probe)->toContain('enp');
});

test('network counters are parsed when the interface reports them', function () {
    $parsed = (new MetricsCollector)->parse('cpu=12.5 memory=44.0 disk=61 load=0.42,0.31,0.25 net=1048576,2097152');

    expect($parsed['rx'])->toBe(1048576)
        ->and($parsed['tx'])->toBe(2097152)
        ->and($parsed['cpu'])->toBe(12.5)
        ->and($parsed['load1'])->toBe(0.42);
});

test('a server with no matching interface records null rather than zero traffic', function () {
    $parsed = (new MetricsCollector)->parse('cpu=1 memory=2 disk=3 load=0.1,0.1,0.1');

    expect($parsed['rx'])->toBeNull()
        ->and($parsed['tx'])->toBeNull();
});

test('pruning deletes readings past the window and keeps the rest', function () {
    $server = Server::factory()->create();

    ServerMetric::factory()->for($server)->count(3)->create(['recorded_at' => now()->subDays(45)]);
    ServerMetric::factory()->for($server)->count(2)->create(['recorded_at' => now()->subDays(10)]);

    $this->artisan('coresmith:prune-metrics')->assertSuccessful();

    expect(ServerMetric::count())->toBe(2);
});

test('the window can be narrowed', function () {
    $server = Server::factory()->create();

    ServerMetric::factory()->for($server)->count(4)->create(['recorded_at' => now()->subDays(10)]);
    ServerMetric::factory()->for($server)->count(1)->create(['recorded_at' => now()->subHours(2)]);

    $this->artisan('coresmith:prune-metrics', ['--days' => 7])->assertSuccessful();

    expect(ServerMetric::count())->toBe(1);
});

test('a window of less than a day is refused rather than wiping the table', function (string $days) {
    $server = Server::factory()->create();
    ServerMetric::factory()->for($server)->count(3)->create(['recorded_at' => now()->subDays(90)]);

    $this->artisan('coresmith:prune-metrics', ['--days' => $days])->assertFailed();

    expect(ServerMetric::count())->toBe(3);
})->with(['0', '-1', 'nonsense']);

test('the default window is thirty days', function () {
    expect(PruneMetricsCommand::DEFAULT_DAYS)->toBe(30);
});

test('pruning works past a single batch', function () {
    $server = Server::factory()->create();

    ServerMetric::factory()->for($server)->count(20)->create(['recorded_at' => now()->subDays(60)]);

    $this->artisan('coresmith:prune-metrics')->assertSuccessful();

    expect(ServerMetric::count())->toBe(0);
});

test('pruning is scheduled so the table cannot grow without bound', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn($event): bool => str_contains((string) $event->command, 'coresmith:prune-metrics'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('15 3 * * 0');
});

test('the server page carries the latest reading and a day of hourly averages', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    ServerMetric::factory()->for($server)->create([
        'cpu_percent' => 10.2,
        'memory_percent' => 40.2,
        'disk_percent' => 61.1,
        'recorded_at' => now()->subMinutes(100),
    ]);
    ServerMetric::factory()->for($server)->create([
        'cpu_percent' => 14.4,
        'memory_percent' => 48.4,
        'disk_percent' => 61.3,
        'recorded_at' => now()->subMinutes(70),
    ]);
    ServerMetric::factory()->for($server)->create([
        'cpu_percent' => 30.46,
        'memory_percent' => 50.54,
        'disk_percent' => 62.16,
        'recorded_at' => now()->subMinutes(5),
    ]);
    ServerMetric::factory()->for($server)->create(['recorded_at' => now()->subDays(2)]);

    $this->actingAs(metricsUser($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('server.metrics.cpu.now', 30)
            ->where('server.metrics.cpu.series', [12.3, 30.5])
            ->where('server.metrics.memory.now', 51)
            ->where('server.metrics.memory.series', [44.3, 50.5])
            ->where('server.metrics.disk.now', 62)
            ->where('server.metrics.disk.series', [61.2, 62.2]));
});

test('each reading card says, in plain amounts, what its percentage is of', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['meta' => [
        'cpu_cores' => 16,
        'cpu_physical_cores' => 8,
        'cpu_model' => 'AMD Ryzen 7 6800H with Radeon Graphics',
        'memory_mb' => 27833,
        'memory_installed_mb' => 32768,
        'disk_gb' => 913,
    ]]);

    ServerMetric::factory()->for($server)->create([
        'load_1m' => 0.1,
        'memory_used_mb' => 1637,
        'memory_total_mb' => 27833,
        'disk_used_mb' => 16384,
        'disk_free_mb' => 872448,
        'disk_total_mb' => 934912,
        'recorded_at' => now()->subMinute(),
    ]);

    $this->actingAs(metricsUser($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertInertia(fn($page) => $page
            ->where('server.metrics.cpu.detail', 'AMD Ryzen 7 6800H with Radeon Graphics · 8 cores, 16 threads · load 0.10')
            ->where('server.metrics.memory.detail', '1.6 GB of 27 GB used · 32 GB installed')
            ->where('server.metrics.disk.detail', '16 GB of 913 GB used · 852 GB free'));
});

test('without a current reading, the cards still say what the server has', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'memory_installed_mb' => 4096, 'disk_gb' => 80]]);

    $this->actingAs(metricsUser($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertInertia(fn($page) => $page
            ->where('server.metrics.cpu.detail', '2 cores')
            ->where('server.metrics.memory.detail', '4 GB total')
            ->where('server.metrics.disk.detail', '80 GB total'));
});

test('a server that has never been read reports no metrics rather than failing', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(metricsUser($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('server.metrics', [
            'cpu' => ['now' => null, 'series' => [], 'detail' => null],
            'memory' => ['now' => null, 'series' => [], 'detail' => null],
            'disk' => ['now' => null, 'series' => [], 'detail' => null],
        ]));
});

test('the latest reading is the most recent one, not the first inserted', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    ServerMetric::factory()->for($server)->create(['cpu_percent' => 10.0, 'recorded_at' => now()->subMinutes(3)]);
    ServerMetric::factory()->for($server)->create(['cpu_percent' => 90.0, 'recorded_at' => now()->subMinutes(1)]);
    ServerMetric::factory()->for($server)->create(['cpu_percent' => 20.0, 'recorded_at' => now()->subMinutes(2)]);

    $this->actingAs(metricsUser($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertInertia(fn($page) => $page->where('server.metrics.cpu.now', 90));
});

test('a reading older than the freshness window stays in the history but is not shown as current', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    ServerMetric::factory()->for($server)->create([
        'cpu_percent' => 42.4,
        'recorded_at' => now()->subMinutes(FleetSnapshot::FRESH_METRIC_MINUTES + 1),
    ]);

    $this->actingAs(metricsUser($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertInertia(fn($page) => $page
            ->where('server.metrics.cpu.now', null)
            ->where('server.metrics.cpu.series', [42.4]));
});

test('another teams metrics are not reachable', function () {
    $server = Server::factory()->for(Team::factory()->create())->create();
    ServerMetric::factory()->for($server)->create();

    $this->actingAs(metricsUser(Team::factory()->create(), TeamRole::Owner))
        ->get(route('servers.show', $server))
        ->assertForbidden();
});
