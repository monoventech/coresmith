<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Panel\FleetSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function fleetServer(Team $team, array $attributes = []): Server
{
    return Server::factory()->for($team)->create(array_merge(['status' => ServerStatus::Active], $attributes));
}

function fleetMetric(Server $server, float $disk, int $minutesAgo = 1, array $attributes = []): ServerMetric
{
    return ServerMetric::factory()->for($server)->create(array_merge([
        'cpu_percent' => 20.4,
        'memory_percent' => 55.5,
        'disk_percent' => $disk,
        'recorded_at' => now()->subMinutes($minutesAgo),
    ], $attributes));
}

function fleetRunning(Server $server): Deployment
{
    $application = Application::factory()->for($server->team)->for($server)->create();

    return Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running, 'started_at' => now()]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('a server state follows a fixed precedence from broken to healthy', function (array $attributes, ?float $disk, bool $deploying, string $key, string $label, string $tone) {
    $team = Team::factory()->create();
    $server = fleetServer($team, $attributes);

    if ($disk !== null) {
        fleetMetric($server, $disk);
    }

    if ($deploying) {
        fleetRunning($server);
    }

    expect((new FleetSnapshot($team))->state($server->fresh()))->toBe(['key' => $key, 'label' => $label, 'tone' => $tone]);
})->with([
    'host key beats everything' => [['status' => ServerStatus::Failed, 'pending_host_key_fingerprint' => 'SHA256:new'], 95.0, true, 'host_key', 'Host key changed', 'destructive'],
    'failed provisioning' => [['status' => ServerStatus::Failed], null, false, 'failed', 'Provisioning failed', 'destructive'],
    'unreachable' => [['status' => ServerStatus::Disconnected], 95.0, true, 'disconnected', 'Unreachable', 'destructive'],
    'provisioning' => [['status' => ServerStatus::Provisioning], null, false, 'provisioning', 'Provisioning', 'accent'],
    'not connected' => [['status' => ServerStatus::Pending], null, false, 'pending', 'Not connected', 'default'],
    'connected, waiting to provision' => [['status' => ServerStatus::Pending, 'last_connected_at' => '2026-09-13 14:00:00'], null, false, 'pending', 'Not provisioned', 'default'],
    'deploying beats a full disk' => [[], 95.0, true, 'deploying', 'Deploying', 'accent'],
    'disk at the warning line' => [[], 80.0, false, 'warning', 'Disk 80%', 'warning'],
    'disk rounded in the label' => [[], 91.6, false, 'warning', 'Disk 92%', 'warning'],
    'disk just under the line' => [[], 79.9, false, 'healthy', 'Healthy', 'success'],
    'no readings' => [[], null, false, 'healthy', 'Healthy', 'success'],
]);

test('a server between installations reads as moving out, moving here or a failed move', function (bool $teamMoving, ?array $takeover, string $key, string $label, string $tone) {
    $team = Team::factory()->create(['moving_at' => $teamMoving ? now() : null]);
    $server = fleetServer($team, ['status' => ServerStatus::Moving, 'takeover' => $takeover]);

    expect((new FleetSnapshot($team))->state($server->fresh()))->toBe(['key' => $key, 'label' => $label, 'tone' => $tone]);
})->with([
    'leaving coresmith cloud' => [true, null, 'moving_away', 'Moving out', 'default'],
    'arriving here' => [false, ['state' => 'running'], 'moving', 'Moving here', 'accent'],
    'a takeover that stopped' => [false, ['state' => 'failed', 'reason' => 'Coresmith Cloud\'s key no longer opens web-01.'], 'move_failed', 'Move failed', 'destructive'],
]);

test('a stale disk reading does not raise a warning', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);
    fleetMetric($server, 97.0, minutesAgo: FleetSnapshot::FRESH_METRIC_MINUTES + 1);

    expect((new FleetSnapshot($team))->state($server)['key'])->toBe('healthy');
});

test('the latest reading is the most recent one, not the first recorded', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);
    fleetMetric($server, 40.0, minutesAgo: 1);
    fleetMetric($server, 90.0, minutesAgo: 5);

    expect((float) (new FleetSnapshot($team))->latestMetric($server)->disk_percent)->toBe(40.0);
});

test('servers are listed oldest first with their applications in name order', function () {
    $team = Team::factory()->create();
    fleetServer($team, ['name' => 'a-newer', 'created_at' => now()->subDay()]);
    $older = fleetServer($team, ['name' => 'z-older', 'created_at' => now()->subWeek()]);
    Application::factory()->for($team)->for($older)->create(['name' => 'Zed']);
    Application::factory()->for($team)->for($older)->create(['name' => 'Alpha']);
    fleetServer(Team::factory()->create(), ['name' => 'not-ours']);

    $servers = (new FleetSnapshot($team))->servers();

    expect($servers->pluck('name')->all())->toBe(['z-older', 'a-newer'])
        ->and($servers->first()->applications->pluck('name')->all())->toBe(['Alpha', 'Zed']);
});

test('the meta line summarises where a server runs and how long it has been up', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team, [
        'environment' => 'Production',
        'region' => 'fra1',
        'meta' => ['memory_mb' => 8192],
        'os_name' => 'ubuntu',
        'os_version' => '24.04',
        'ip_address' => '203.0.113.10',
        'booted_at' => now()->subDays(3),
    ]);
    Application::factory()->for($team)->for($server)->count(2)->create();

    $fleet = new FleetSnapshot($team);
    $loaded = $fleet->servers()->first();

    expect($fleet->meta($loaded))->toBe('Production · fra1 · 8 GB · 2 apps · up 3 days')
        ->and($fleet->meta($loaded, long: true))->toBe('Production · fra1 · 8 GB · Ubuntu 24.04 · 203.0.113.10 · up 3 days')
        ->and($fleet->compactMeta($loaded))->toBe('Production · fra1 · 2 apps');
});

test('the meta line drops what it does not know and counts a single app', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team, ['environment' => null, 'region' => null, 'meta' => null, 'booted_at' => null]);
    Application::factory()->for($team)->for($server)->create();

    $fleet = new FleetSnapshot($team);

    expect($fleet->meta($fleet->servers()->first()))->toBe('1 app')
        ->and($fleet->compactMeta($fleet->servers()->first()))->toBe('1 app');
});

test('a server card carries its state, rounded readings, links and applications', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team, ['name' => 'web-1', 'environment' => 'Staging']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Shop', 'framework' => 'laravel']);
    fleetMetric($server, 61.5, attributes: ['cpu_percent' => 20.4, 'memory_percent' => 55.5]);

    $fleet = new FleetSnapshot($team);

    expect($fleet->card($fleet->servers()->first()))->toBe([
        'id' => $server->id,
        'name' => 'web-1',
        'state' => ['key' => 'healthy', 'label' => 'Healthy', 'tone' => 'success'],
        'meta' => 'Staging · 1 app',
        'compact_meta' => 'Staging · 1 app',
        'cpu' => 20,
        'memory' => 56,
        'disk' => 62,
        'href' => route('servers.show', $server),
        'logs_href' => route('servers.activity', $server),
        'watch_href' => null,
        'applications' => [[
            'id' => $application->id,
            'name' => 'Shop',
            'kind' => 'Laravel',
            'deploy_href' => route('servers.applications.deploy', [$server, $application]),
        ]],
        'cost' => null,
        'provider' => null,
    ]);
});

test('a server card without readings has no numbers and links to a running deploy', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);
    $running = fleetRunning($server);

    $fleet = new FleetSnapshot($team);
    $card = $fleet->card($fleet->servers()->first());

    expect($card['cpu'])->toBeNull()
        ->and($card['memory'])->toBeNull()
        ->and($card['disk'])->toBeNull()
        ->and($card['watch_href'])->toBe(route('servers.applications.deployments.show', [$server, $running->application_id, $running]));
});

test('only the teams own running deployments are running, newest first', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);
    $older = fleetRunning($server);
    $older->update(['started_at' => now()->subMinutes(5)]);
    $newer = fleetRunning($server);
    fleetRunning(fleetServer(Team::factory()->create()));

    $fleet = new FleetSnapshot($team);

    expect($fleet->running()->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($fleet->runningOn($server)->id)->toBe($newer->id)
        ->and($fleet->runningOn(fleetServer($team)))->toBeNull();
});

test('a healthy fleet needs no attention', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);
    fleetMetric($server, 40.0);
    Deployment::factory()->for(Application::factory()->for($team)->for($server))->create(['status' => DeploymentStatus::Succeeded]);

    expect((new FleetSnapshot($team))->attention())->toBe([]);
});

test('broken servers are critical and listed before every warning', function () {
    $team = Team::factory()->create();
    $full = fleetServer($team, ['name' => 'full-disk', 'created_at' => now()->subWeek()]);
    fleetMetric($full, 91.4);
    $stopped = Application::factory()->for($team)->for($full)->create(['name' => 'Paused', 'power_state' => PowerState::Stopped]);
    $moved = fleetServer($team, ['name' => 'moved', 'pending_host_key_fingerprint' => 'SHA256:new', 'created_at' => now()->subDay()]);
    $gone = fleetServer($team, ['name' => 'gone', 'status' => ServerStatus::Disconnected, 'created_at' => now()]);

    expect((new FleetSnapshot($team))->attention())->toBe([
        ['severity' => 'critical', 'subject' => 'moved', 'summary' => 'host key changed', 'href' => route('servers.show', $moved)],
        ['severity' => 'critical', 'subject' => 'gone', 'summary' => 'unreachable', 'href' => route('servers.show', $gone)],
        ['severity' => 'warning', 'subject' => 'full-disk', 'summary' => 'disk 91%', 'href' => route('servers.show', $full)],
        ['severity' => 'warning', 'subject' => 'Paused', 'summary' => 'stopped', 'href' => route('servers.applications.show', [$full, $stopped])],
    ]);
});

test('a server that is deploying onto a full disk is still a disk warning', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team, ['name' => 'web-1']);
    fleetMetric($server, 88.0);
    fleetRunning($server);

    expect((new FleetSnapshot($team))->attention())->toBe([
        ['severity' => 'warning', 'subject' => 'web-1', 'summary' => 'disk 88%', 'href' => route('servers.show', $server)],
    ]);
});

test('a server that is deploying with memory at the line is still a memory warning', function (float $memory, float $disk, array $listed) {
    $team = Team::factory()->create();
    $server = fleetServer($team, ['name' => 'web-1']);
    fleetMetric($server, $disk, attributes: ['memory_percent' => $memory]);
    fleetRunning($server);

    expect((new FleetSnapshot($team))->attention())->toBe(array_map(
        fn(string $summary): array => ['severity' => 'warning', 'subject' => 'web-1', 'summary' => $summary, 'href' => route('servers.show', $server)],
        $listed,
    ));
})->with([
    'at the line' => [95.0, 40.0, ['memory 95%']],
    'rounded in the summary' => [96.6, 40.0, ['memory 97%']],
    'over a full disk' => [97.0, 88.0, ['memory 97%']],
    'just under the line' => [94.9, 40.0, []],
    'a full disk alone' => [60.0, 88.0, ['disk 88%']],
]);

test('an application whose last finished deploy failed needs attention until one succeeds', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Shop']);
    $failed = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'created_at' => now()->subMinutes(10)]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running, 'created_at' => now()]);

    expect((new FleetSnapshot($team))->attention())->toBe([
        ['severity' => 'warning', 'subject' => 'Shop', 'summary' => 'last deploy failed', 'href' => route('servers.applications.deployments.show', [$server, $application, $failed])],
    ]);

    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'created_at' => now()->subMinute()]);

    expect((new FleetSnapshot($team))->attention())->toBe([]);
});

test('another teams problems never need this teams attention', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $server = fleetServer($other, ['status' => ServerStatus::Failed]);
    $application = Application::factory()->for($other)->for($server)->create(['power_state' => PowerState::Stopped]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed]);

    expect((new FleetSnapshot($team))->attention())->toBe([]);
});

test('deploys this week count every attempt from the last seven days except cancelled ones', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(fleetServer($team))->create();

    foreach ([DeploymentStatus::Succeeded, DeploymentStatus::Failed, DeploymentStatus::Running, DeploymentStatus::Pending] as $status) {
        Deployment::factory()->for($application)->create(['status' => $status, 'created_at' => now()->subDays(2)]);
    }

    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Cancelled, 'created_at' => now()->subDay()]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'created_at' => now()->subDays(8)]);
    Deployment::factory()->for(Application::factory()->for(Team::factory()))->create(['status' => DeploymentStatus::Succeeded]);

    expect((new FleetSnapshot($team))->deploysThisWeek())->toBe(4);
});

test('uptime is unknown until a server has been read', function () {
    $team = Team::factory()->create();
    fleetServer($team);

    expect((new FleetSnapshot($team))->uptime())->toBeNull();
});

test('uptime is full when a reading arrived every minute since the first one', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);

    foreach (range(0, 10) as $minutesAgo) {
        fleetMetric($server, 40.0, minutesAgo: $minutesAgo);
    }

    expect((new FleetSnapshot($team))->uptime())->toBe(100.0);
});

test('uptime is the share of minutes that produced a reading across the fleet', function () {
    $team = Team::factory()->create();
    $steady = fleetServer($team);
    $patchy = fleetServer($team);

    foreach (range(0, 10) as $minutesAgo) {
        fleetMetric($steady, 40.0, minutesAgo: $minutesAgo);
    }

    fleetMetric($patchy, 40.0, minutesAgo: 4);
    fleetMetric($patchy, 40.0, minutesAgo: 0);

    expect((new FleetSnapshot($team))->uptime())->toBe(81.25);
});

test('uptime never exceeds full coverage and ignores readings older than thirty days', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);
    fleetMetric($server, 40.0, minutesAgo: 0);
    fleetMetric($server, 41.0, minutesAgo: 0);
    fleetMetric($server, 42.0, minutesAgo: 60 * 24 * (FleetSnapshot::UPTIME_DAYS + 1));

    expect((new FleetSnapshot($team))->uptime())->toBe(100.0);
});

test('a partial record of readings lowers uptime', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);

    foreach ([10, 5, 0] as $minutesAgo) {
        fleetMetric($server, 40.0, minutesAgo: $minutesAgo);
    }

    expect((new FleetSnapshot($team))->uptime())->toBe(27.27);
});

test('an application killed for memory in the last day needs attention', function () {
    $team = Team::factory()->create();
    $server = fleetServer($team);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Hungry']);

    activity()->performedOn($application)->withProperties(['process' => 'web', 'at' => now()->subHours(2)->toIso8601String(), 'limit_mb' => 256])->log('app_oom_killed');

    expect((new FleetSnapshot($team))->attention())->toContain(
        ['severity' => 'warning', 'subject' => 'Hungry', 'summary' => 'killed for memory', 'href' => route('servers.applications.show', [$server, $application])],
    );

    $this->travel(25)->hours();

    expect((new FleetSnapshot($team))->attention())->toBe([]);
});

function cloudFleetCreating(Team $team, bool $failed, array $attributes = []): Server
{
    $server = Server::factory()->fromProvider(CloudProvider::factory()->for($team)->create())->creating()->create(['name' => 'outpost-02', ...$attributes]);
    CloudTask::start($server, CloudTask::CREATE, 'create');

    if ($failed) {
        CloudTask::fail($server, 'Hetzner Cloud refused the size.');
    }

    return $server->fresh();
}

test('a server at its provider reads as being created, or as failed once its creation fails', function (bool $failed, array $attributes, string $key, string $label, string $tone) {
    $team = Team::factory()->create();
    $server = cloudFleetCreating($team, $failed, $attributes);

    expect((new FleetSnapshot($team))->state($server))->toBe(['key' => $key, 'label' => $label, 'tone' => $tone]);
})->with([
    'being created' => [false, [], 'creating', 'Creating', 'accent'],
    'creation failed' => [true, [], 'creation_failed', 'Creation failed', 'destructive'],
    'a changed host key still comes first' => [true, ['pending_host_key_fingerprint' => 'SHA256:new'], 'host_key', 'Host key changed', 'destructive'],
]);

test('a provider server card carries its monthly cost and provider', function () {
    $team = Team::factory()->create();
    Server::factory()->fromProvider(CloudProvider::factory()->for($team)->create())->create(['status' => ServerStatus::Active, 'provider_monthly_cost' => '4.35', 'provider_currency' => 'EUR']);

    $fleet = new FleetSnapshot($team);
    $card = $fleet->card($fleet->servers()->first());

    expect($card['cost'])->toBe('€4.35/mo')
        ->and($card['provider'])->toBe('Hetzner Cloud');
});

test('the fleet loads providers once, however many servers it shows', function () {
    $team = Team::factory()->create();
    $account = CloudProvider::factory()->for($team)->create();
    Server::factory()->fromProvider($account)->count(3)->create(['status' => ServerStatus::Active]);
    $fleet = new FleetSnapshot($team);
    $servers = $fleet->servers();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $servers->each(fn(Server $server) => $fleet->card($server));

    expect(collect(DB::getQueryLog())->filter(fn(array $query): bool => str_contains($query['query'], 'cloud_providers'))->count())->toBe(0);
});

test('a failed creation needs attention first', function () {
    $team = Team::factory()->create();
    $full = fleetServer($team, ['name' => 'full-disk', 'created_at' => now()->subWeek()]);
    fleetMetric($full, 91.4);
    $failed = cloudFleetCreating($team, true);
    cloudFleetCreating($team, false, ['name' => 'outpost-03']);

    expect((new FleetSnapshot($team))->attention())->toBe([
        ['severity' => 'critical', 'subject' => 'outpost-02', 'summary' => 'creation failed', 'href' => route('servers.show', $failed)],
        ['severity' => 'warning', 'subject' => 'full-disk', 'summary' => 'disk 91%', 'href' => route('servers.show', $full)],
    ]);
});

function cloudFleetDestroying(Team $team, bool $failed, array $attributes = []): Server
{
    $server = Server::factory()->fromProvider(CloudProvider::factory()->for($team)->create())->create(['name' => 'outpost-04', 'status' => ServerStatus::Active, ...$attributes]);
    CloudTask::start($server, CloudTask::DESTROY, 'delete');

    if ($failed) {
        CloudTask::fail($server, 'Hetzner Cloud refused the request.');
    }

    return $server->fresh();
}

test('a server being destroyed reads so, and a failed destroy needs attention', function () {
    $team = Team::factory()->create();
    $running = cloudFleetDestroying($team, false);
    $failed = cloudFleetDestroying($team, true, ['name' => 'outpost-05']);
    $fleet = new FleetSnapshot($team);

    expect($fleet->state($running))->toBe(['key' => 'destroying', 'label' => 'Destroying', 'tone' => 'default'])
        ->and($fleet->state($failed))->toBe(['key' => 'destroy_failed', 'label' => 'Destroy failed', 'tone' => 'destructive'])
        ->and($fleet->attention())->toContain(['severity' => 'critical', 'subject' => 'outpost-05', 'summary' => 'destroy failed', 'href' => route('servers.show', $failed)]);
});

function cloudFleetResizing(Team $team, bool $failed, array $attributes = []): Server
{
    $server = Server::factory()->fromProvider(CloudProvider::factory()->for($team)->create())->create(['name' => 'outpost-06', 'status' => ServerStatus::Active, ...$attributes]);
    CloudTask::start($server, CloudTask::RESIZE, 'change', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);

    if ($failed) {
        CloudTask::fail($server, 'Hetzner Cloud refused the request. outpost-07 was started again on cx22.');
    }

    return $server->fresh();
}

test('a server being resized reads so, and a failed resize is a warning', function () {
    $team = Team::factory()->create();
    $running = cloudFleetResizing($team, false);
    $failed = cloudFleetResizing($team, true, ['name' => 'outpost-07']);
    fleetRunning($running);
    $fleet = new FleetSnapshot($team);

    expect($fleet->state($running))->toBe(['key' => 'resizing', 'label' => 'Resizing', 'tone' => 'accent'])
        ->and($fleet->state($failed))->toBe(['key' => 'resize_failed', 'label' => 'Resize failed', 'tone' => 'warning'])
        ->and($fleet->attention())->toContain(['severity' => 'warning', 'subject' => 'outpost-07', 'summary' => 'resize failed', 'href' => route('servers.show', $failed)])
        ->and(collect($fleet->attention())->pluck('subject')->all())->not->toContain('outpost-06');
});
