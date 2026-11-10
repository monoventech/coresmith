<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Events\DeploymentFailed;
use App\Jobs\CollectServerMetrics;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\WakeApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\Server\MetricsCollector;
use App\Services\Server\ServerReachability;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

const PROBE_OUTPUT = "cpu=12.5\nmemory=48.2\ndisk=61\nload=0.42,0.51,0.60\n";

function metricsConnection(FakeSSHTransport $transport, array $serverAttributes = []): SSHConnection
{
    $server = Server::factory()->create(array_merge([
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'status' => ServerStatus::Active,
    ], $serverAttributes));

    return (new SSHConnection($server, $transport))->connect();
}

test('a probe is parsed into a metric row', function () {
    $connection = metricsConnection((new FakeSSHTransport)->respondTo('top -bn1', PROBE_OUTPUT, 0));

    $metric = (new MetricsCollector)->collect($connection);

    expect($metric->cpu_percent)->toBe(12.5)
        ->and($metric->memory_percent)->toBe(48.2)
        ->and($metric->disk_percent)->toBe(61.0)
        ->and($metric->load_1m)->toBe(0.42)
        ->and($metric->load_15m)->toBe(0.6)
        ->and(ServerMetric::count())->toBe(1);
});

test('a reading keeps the amounts behind its percentages: memory and disk used, free and in all', function () {
    $connection = metricsConnection((new FakeSSHTransport)->respondTo('top -bn1', PROBE_OUTPUT . "memory_mb=1637,27833\ndisk_kb=957349888,16777216,893386752\n", 0));

    $metric = (new MetricsCollector)->collect($connection);

    expect($metric->fresh()->only(['memory_used_mb', 'memory_total_mb', 'disk_used_mb', 'disk_free_mb', 'disk_total_mb']))->toBe([
        'memory_used_mb' => 1637,
        'memory_total_mb' => 27833,
        'disk_used_mb' => 16384,
        'disk_free_mb' => 872448,
        'disk_total_mb' => 934912,
    ]);
});

test('the processor, the system name and the memory installed are read when a server has none, and not again within the day', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('top -bn1', PROBE_OUTPUT, 0)
        ->respondTo('lscpu', "cpu_model=AMD Ryzen 7 6800H with Radeon Graphics\ncpu_cores=8\nos=Ubuntu 24.04.5 LTS\ninstalled_mb=32768\n", 0);
    $connection = metricsConnection($transport, ['meta' => ['cpu_cores' => 16, 'memory_mb' => 27833]]);

    (new MetricsCollector)->collect($connection);
    (new MetricsCollector)->collect($connection);

    expect($connection->server()->fresh()->meta)->toMatchArray([
        'cpu_cores' => 16,
        'memory_mb' => 27833,
        'cpu_model' => 'AMD Ryzen 7 6800H with Radeon Graphics',
        'cpu_physical_cores' => 8,
        'os_pretty' => 'Ubuntu 24.04.5 LTS',
        'memory_installed_mb' => 32768,
    ])
        ->and(collect($transport->commands())->filter(fn(string $command): bool => str_contains($command, 'lscpu'))->count())->toBe(1);
});

test('a probe that returns nothing still records zeroes rather than failing', function () {
    $connection = metricsConnection((new FakeSSHTransport)->respondTo('top -bn1', '', 0));

    expect((new MetricsCollector)->collect($connection)->cpu_percent)->toBe(0.0);
});

test('traffic to a sleeping app queues a wake', function () {
    Queue::fake();
    $transport = (new FakeSSHTransport)
        ->respondTo('top -bn1', PROBE_OUTPUT, 0)
        ->respondTo('access.log', "app.example.com\nother.example.com\n", 0);
    $connection = metricsConnection($transport);

    Application::factory()->for($connection->server())->for($connection->server()->team)->create([
        'domain' => 'app.example.com',
        'power_state' => PowerState::Sleeping,
    ]);

    (new MetricsCollector)->recordTraffic($connection);

    Queue::assertPushed(WakeApplication::class);
});

test('no traffic leaves a sleeping app asleep', function () {
    Queue::fake();
    $transport = (new FakeSSHTransport)->respondTo('access.log', "someone-else.example.com\n", 0);
    $connection = metricsConnection($transport);

    Application::factory()->for($connection->server())->for($connection->server()->team)->create([
        'domain' => 'app.example.com',
        'power_state' => PowerState::Sleeping,
    ]);

    (new MetricsCollector)->recordTraffic($connection);

    Queue::assertNotPushed(WakeApplication::class);
});

test('an active app is never woken', function () {
    Queue::fake();
    $transport = (new FakeSSHTransport)->respondTo('access.log', "app.example.com\n", 0);
    $connection = metricsConnection($transport);

    Application::factory()->for($connection->server())->for($connection->server()->team)->create([
        'domain' => 'app.example.com',
        'power_state' => PowerState::Active,
    ]);

    (new MetricsCollector)->recordTraffic($connection);

    Queue::assertNotPushed(WakeApplication::class);
});

test('an additional domain also wakes the app', function () {
    Queue::fake();
    $transport = (new FakeSSHTransport)->respondTo('access.log', "www.example.com\n", 0);
    $connection = metricsConnection($transport);

    Application::factory()->for($connection->server())->for($connection->server()->team)->create([
        'domain' => 'app.example.com',
        'additional_domains' => ['www.example.com'],
        'power_state' => PowerState::Sleeping,
    ]);

    (new MetricsCollector)->recordTraffic($connection);

    Queue::assertPushed(WakeApplication::class);
});

test('metrics are not collected from a server that is not active', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new CollectServerMetrics($server))->handle(app(SSHConnectionManager::class), new MetricsCollector, app(ServerReachability::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($transport->commands())->toBe([])->and(ServerMetric::count())->toBe(0);
});

test('a deployment stuck running is failed and its lock released', function () {
    Event::fake([DeploymentFailed::class]);
    $application = Application::factory()->create();
    $deployment = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(30),
    ]);

    Cache::lock('deploy:' . $application->getKey(), 600)->get();

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->log)->toContain('No progress was reported')
        ->and(Cache::lock('deploy:' . $application->getKey(), 600)->get())->toBeTrue();

    Event::assertDispatched(DeploymentFailed::class);
});

test('a deployment still inside the window is left alone', function () {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(2),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running);
});

test('the schedule registers metric collection, sleeping and stale detection', function (string $name) {
    $names = collect(app(Schedule::class)->events())
        ->map(fn($event) => $event->description)
        ->all();

    expect($names)->toContain($name);
})->with(['coresmith:collect-metrics', 'coresmith:sleep-idle-apps', 'coresmith:detect-stale-deployments']);
