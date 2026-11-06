<?php

declare(strict_types=1);

use App\Broadcasting\TeamChannel;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Events\DeploymentFailed;
use App\Events\DeploymentSucceeded;
use App\Events\DeploymentUpdated;
use App\Events\FleetChanged;
use App\Events\ServerProvisioned;
use App\Events\ServerProvisioningFailed;
use App\Jobs\CollectServerMetrics;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\DestroyServerTask;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Panel\Realtime;
use App\Services\Runtime\AsdfManager;
use App\Services\Server\CertificateCheck;
use App\Services\Server\MetricsCollector;
use App\Services\Server\ServerReachability;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Exception\UnableToConnectException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeCloudApi;
use Tests\Support\FakeSSHTransport;

function fleetTeam(): array
{
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    Team::factory()->create()->members()->attach($outsider, ['role' => TeamRole::Owner->value]);

    return [$team, $member->fresh(), $outsider->fresh()];
}

function fleetPingServer(?Team $team = null, array $attributes = []): Server
{
    $factory = $team === null ? Server::factory() : Server::factory()->for($team);

    return $factory->create(array_merge(['name' => 'web-1', 'status' => ServerStatus::Active, 'host_key_fingerprint' => null], $attributes));
}

function fleetPingApplication(Server $server, array $attributes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create($attributes);
}

function fleetPings(): array
{
    return Event::dispatched(FleetChanged::class)
        ->map(fn(array $dispatch): array => [$dispatch[0]->teamId, $dispatch[0]->kind, $dispatch[0]->subjectId])
        ->values()
        ->all();
}

function fleetPingDownQueue(): object
{
    return new class
    {
        public function connection(): never
        {
            throw new RuntimeException('Queue is down');
        }
    };
}

function fleetConfigWith(array $environment): array
{
    $repository = Env::getRepository();

    foreach ($environment as $name => $value) {
        $repository->set($name, $value);
    }

    try {
        return require config_path('coresmith.php');
    } finally {
        foreach (array_keys($environment) as $name) {
            $repository->clear($name);
        }
    }
}

function fleetPingCollect(Server $server, int $memory = 40, int $disk = 40): void
{
    $transport = (new FakeSSHTransport)->respondTo('top -bn1', "cpu=12.5\nmemory={$memory}\ndisk={$disk}\nload=0.42,0.51,0.60\nnet=1000,2000\nuptime=93630\n");

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new CollectServerMetrics($server))->handle(app(SSHConnectionManager::class), app(MetricsCollector::class), app(ServerReachability::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function fleetPingReading(Server $server, float $memory, int $minutesAgo): ServerMetric
{
    return ServerMetric::factory()->for($server)->create(['memory_percent' => $memory, 'disk_percent' => 40, 'recorded_at' => now()->subMinutes($minutesAgo)]);
}

function fleetPingKill(Server $server, string $unit, int $secondsAgo): void
{
    $line = json_encode([
        'UNIT' => $unit . '.service',
        'MESSAGE' => $unit . '.service: A process of this unit has been killed by the OOM killer.',
        '__REALTIME_TIMESTAMP' => (string) (now()->subSeconds($secondsAgo)->getTimestamp() * 1000000),
    ], JSON_THROW_ON_ERROR);

    app(MetricsCollector::class)->recordOomKills((new SSHConnection($server, (new FakeSSHTransport)->respondTo('journalctl --since', $line)))->connect());
}

function fleetPingDeployment(): Deployment
{
    $server = fleetPingServer();
    $application = Application::factory()->for($server->team)->for($server)->create([
        'slug' => 'my-app',
        'domain' => 'app.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'https://github.com/monoven/example.git',
        'repository_branch' => 'main',
        'run_migrations' => true,
        'base_port' => 8000,
    ]);

    return Deployment::factory()->for($application)->create();
}

function fleetPingDeploy(Deployment $deployment): void
{
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n", 0);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261031140000'))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 14:00:00'));
});

test("a team's members may follow its channel and nobody else may", function () {
    [$team, $member, $outsider] = fleetTeam();
    $channel = new TeamChannel;

    expect($channel->join($member, $team->id))->toBeTrue()
        ->and($channel->join($outsider, $team->id))->toBeFalse()
        ->and($channel->join(User::factory()->create(), $team->id))->toBeFalse()
        ->and($channel->join($member, '01JQQQQQQQQQQQQQQQQQQQQQQQ'))->toBeFalse();
});

test('every role in the team may follow its channel', function (TeamRole $role) {
    [$team] = fleetTeam();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    expect((new TeamChannel)->join($user->fresh(), $team->id))->toBeTrue();
})->with(TeamRole::cases());

test('a channel naming no team is refused', function (string $name) {
    [, $member] = fleetTeam();

    expect((new TeamChannel)->join($member, $name))->toBeFalse();
})->with(['', 'not-an-id', '1 OR 1=1']);

test('a team that was deleted can no longer be followed', function () {
    [$team, $member] = fleetTeam();
    $team->delete();

    expect((new TeamChannel)->join($member, $team->id))->toBeFalse();
});

test('the team channel is registered under its name', function () {
    expect(Broadcast::getChannels()->get('teams.{team}'))->toBe(TeamChannel::class);
});

test('only the deploy and team channels can be joined', function () {
    expect(Broadcast::getChannels()->keys()->sort()->values()->all())->toBe(['deployments.{deployment}', 'teams.{team}']);
});

test('the ping carries only a kind and an id', function () {
    Event::fake([FleetChanged::class]);
    [$team] = fleetTeam();
    $server = fleetPingServer($team);
    $application = fleetPingApplication($server);
    $deployment = Deployment::factory()->for($application)->create();

    FleetChanged::forServer($server);

    $ping = Event::dispatched(FleetChanged::class)->sole()[0];

    expect($ping->broadcastOn())->toHaveCount(1)
        ->and($ping->broadcastOn()[0]->name)->toBe('private-teams.' . $team->id)
        ->and($ping->broadcastAs())->toBe('fleet.changed')
        ->and($ping->broadcastWith())->toBe(['kind' => 'server', 'id' => $server->id]);

    FleetChanged::forApplication($application);
    FleetChanged::forDeployment($deployment);

    expect(fleetPings())->toBe([
        [$team->id, 'server', $server->id],
        [$team->id, 'application', $application->id],
        [$team->id, 'deployment', $deployment->id],
    ]);
});

test('a ping for a deployment whose app is gone is dropped', function () {
    Event::fake([FleetChanged::class]);
    $deployment = Deployment::factory()->for(fleetPingApplication(fleetPingServer()))->create();
    $deployment->application->delete();

    FleetChanged::forDeployment($deployment->fresh());

    Event::assertNotDispatched(FleetChanged::class);
});

test('nothing is queued without a broadcaster', function (string $driver) {
    Queue::fake();
    config(['broadcasting.default' => $driver]);
    $server = fleetPingServer();

    expect((new FleetChanged($server->team_id, 'server', $server->id))->broadcastWhen())->toBeFalse();

    FleetChanged::forServer($server);

    Queue::assertNothingPushed();
})->with(['null', 'log', '']);

test('a ping is queued once a broadcaster is configured', function () {
    Queue::fake();
    config(['broadcasting.default' => 'reverb']);
    $server = fleetPingServer();

    expect((new FleetChanged($server->team_id, 'server', $server->id))->broadcastWhen())->toBeTrue();

    FleetChanged::forServer($server);

    Queue::assertPushed(BroadcastEvent::class, fn(BroadcastEvent $job): bool => $job->event instanceof FleetChanged && $job->event->subjectId === $server->id);
    Queue::assertPushed(BroadcastEvent::class, 1);
});

test('streaming is on for every broadcaster but the ones that go nowhere', function (string $driver, bool $streaming) {
    config(['broadcasting.default' => $driver]);

    expect(Realtime::available())->toBe($streaming);
})->with([
    ['null', false],
    ['log', false],
    ['', false],
    ['reverb', true],
    ['pusher', true],
    ['redis', true],
    ['ably', true],
]);

test('pages are told whether to stream and how often to refresh', function () {
    [, $member] = fleetTeam();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->where('panel.live.streaming', false)
            ->where('panel.live.refresh_seconds', 60));

    config(['broadcasting.default' => 'reverb']);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->where('panel.live.streaming', true)
            ->where('panel.live.refresh_seconds', 60));
});

test('the refresh interval the pages are told is the configured one', function () {
    [, $member] = fleetTeam();
    config(['coresmith.live.refresh_seconds' => 45]);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('panel.live.refresh_seconds', 45));
});

test('the refresh interval comes from the environment and never drops below fifteen seconds', function (?string $value, int $seconds) {
    $config = fleetConfigWith($value === null ? [] : ['CORESMITH_LIVE_REFRESH_SECONDS' => $value]);

    expect($config['live']['refresh_seconds'])->toBe($seconds);
})->with([
    'unset' => [null, 60],
    'five' => ['5', 15],
    'fifteen' => ['15', 15],
    'a minute and a half' => ['90', 90],
    'nonsense' => ['soon', 15],
]);

test('starting a deploy pings the team', function () {
    Event::fake([FleetChanged::class, DeploymentSucceeded::class]);
    $deployment = fleetPingDeployment();

    fleetPingDeploy($deployment);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(fleetPings())->toBe([[$deployment->application->team_id, 'deployment', $deployment->id]]);
});

test('the start ping comes after the deploy is marked running', function () {
    Event::fake([DeploymentSucceeded::class]);
    $deployment = fleetPingDeployment();
    $seen = [];

    Event::listen(FleetChanged::class, function (FleetChanged $ping) use (&$seen): void {
        $current = Deployment::query()->findOrFail($ping->subjectId);

        $seen[] = [$current->status, $current->started_at !== null];
    });

    fleetPingDeploy($deployment);

    expect($seen)->toBe([[DeploymentStatus::Running, true]]);
});

test('a finished or failed deploy pings the team', function () {
    Event::fake([FleetChanged::class]);
    $deployment = Deployment::factory()->for(fleetPingApplication(fleetPingServer()))->create();
    $ping = [$deployment->application->team_id, 'deployment', $deployment->id];

    DeploymentSucceeded::dispatch($deployment);

    expect(fleetPings())->toBe([$ping]);

    DeploymentFailed::dispatch($deployment);

    expect(fleetPings())->toBe([$ping, $ping]);
});

test('an outage and a recovery ping the team', function () {
    Event::fake([FleetChanged::class]);
    Notification::fake();
    $server = fleetPingServer();
    $reachability = app(ServerReachability::class);
    $refused = new UnableToConnectException('Cannot connect to 192.0.2.10:22. Error 111. Connection refused');
    $ping = [$server->team_id, 'server', $server->id];

    $reachability->failed($server, $refused);
    $reachability->failed($server, $refused);

    expect(fleetPings())->toBe([]);

    $reachability->failed($server, $refused);
    $reachability->failed($server, $refused);

    expect(fleetPings())->toBe([$ping]);

    $reachability->answered($server);
    $reachability->answered($server);

    expect(fleetPings())->toBe([$ping]);

    $reachability->answered($server);
    $reachability->answered($server);

    expect(fleetPings())->toBe([$ping, $ping]);
});

test('a stale view of an outage cannot ping its recovery twice', function () {
    Event::fake([FleetChanged::class]);
    Notification::fake();
    $server = fleetPingServer(attributes: ['unreachable_since' => now()->subMinutes(5), 'connection_failures' => 5, 'connection_answers' => 2]);
    $stale = $server->fresh();

    app(ServerReachability::class)->answered($server);
    app(ServerReachability::class)->answered($stale);

    expect(fleetPings())->toBe([[$server->team_id, 'server', $server->id]]);
});

test('a provisioning outcome pings the team', function () {
    Event::fake([FleetChanged::class]);
    Notification::fake();
    $server = fleetPingServer();
    $ping = [$server->team_id, 'server', $server->id];

    ServerProvisioned::dispatch($server, 372);

    expect(fleetPings())->toBe([$ping]);

    ServerProvisioningFailed::dispatch($server, 2, 'Base system');

    expect(fleetPings())->toBe([$ping, $ping]);
});

test('a provisioning ping does not depend on its mail', function () {
    Event::fake([FleetChanged::class]);
    Exceptions::fake();
    $server = fleetPingServer();
    Notification::shouldReceive('send')->twice()->andThrow(new RuntimeException('Queue is down'));
    $server->team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);

    ServerProvisioned::dispatch($server, 372);
    ServerProvisioningFailed::dispatch($server, 2, 'Base system');

    expect(fleetPings())->toHaveCount(2);
});

test('a memory kill and a new alert ping the team', function () {
    Event::fake([FleetChanged::class]);
    Notification::fake();
    $server = fleetPingServer();
    $shop = fleetPingApplication($server, ['slug' => 'shop']);

    fleetPingCollect($server, disk: 90);

    expect(fleetPings())->toBe([[$server->team_id, 'server', $server->id]]);

    $this->travel(10)->minutes();
    fleetPingReading($server, 97, 2);
    fleetPingReading($server, 97, 1);
    fleetPingCollect($server, memory: 96);

    expect(fleetPings())->toHaveCount(2)
        ->and(Activity::query()->where('description', 'memory_pressure')->count())->toBe(1);

    fleetPingKill($server, 'coresmith-app-shop-web', 30);

    expect(fleetPings())->toBe([
        [$server->team_id, 'server', $server->id],
        [$server->team_id, 'server', $server->id],
        [$server->team_id, 'application', $shop->id],
    ]);
});

test('a disk alert pings once a day, and again when it turns critical', function () {
    Event::fake([FleetChanged::class]);
    Notification::fake();
    $server = fleetPingServer();

    fleetPingCollect($server, disk: 90);
    fleetPingCollect($server, disk: 91);

    expect(fleetPings())->toHaveCount(1);

    fleetPingCollect($server, disk: 96);

    expect(fleetPings())->toHaveCount(2)
        ->and(Activity::query()->where('description', 'disk_pressure')->count())->toBe(2);
});

test('every kill that is newly logged pings and one that is only read again does not', function () {
    Event::fake([FleetChanged::class]);
    Notification::fake();
    $server = fleetPingServer();
    $shop = fleetPingApplication($server, ['slug' => 'shop']);

    fleetPingKill($server, 'coresmith-app-shop-web', 30);
    fleetPingKill($server, 'coresmith-app-shop-web', 30);

    expect(fleetPings())->toBe([[$server->team_id, 'application', $shop->id]]);

    fleetPingKill($server, 'coresmith-app-shop-web', 10);

    expect(fleetPings())->toHaveCount(2);
});

test('a kill that belongs to no app pings nothing', function () {
    Event::fake([FleetChanged::class]);
    $server = fleetPingServer();
    fleetPingApplication($server, ['slug' => 'shop']);

    fleetPingKill($server, 'nginx', 30);

    Event::assertNotDispatched(FleetChanged::class);
});

test('an alert that could not be mailed pings nothing until its row is written', function () {
    Event::fake([FleetChanged::class]);
    Exceptions::fake();
    $server = fleetPingServer();
    $server->team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));

    fleetPingCollect($server, disk: 90);

    expect(Activity::query()->where('description', 'disk_pressure')->count())->toBe(0);
    Exceptions::assertReportedCount(1);
    Event::assertNotDispatched(FleetChanged::class);
});

test('an ordinary metric does not ping', function () {
    Event::fake([FleetChanged::class]);
    Notification::fake();
    $server = fleetPingServer();
    fleetPingApplication($server, ['slug' => 'shop']);

    foreach ([[40, 40], [94, 79], [96, 40], [96, 40]] as [$memory, $disk]) {
        fleetPingCollect($server, memory: $memory, disk: $disk);
        $this->travel(1)->minutes();
    }

    expect(ServerMetric::query()->where('server_id', $server->id)->count())->toBe(4)
        ->and(Activity::query()->whereIn('description', ['disk_pressure', 'memory_pressure', 'app_oom_killed'])->count())->toBe(0);
    Event::assertNotDispatched(FleetChanged::class);
});

test('an answer from a server that never went quiet pings nothing', function () {
    Event::fake([FleetChanged::class]);
    $server = fleetPingServer();

    app(ServerReachability::class)->answered($server);
    app(ServerReachability::class)->failed($server, new UnableToConnectException('Cannot connect to 192.0.2.10:22. Error 111. Connection refused'));

    Event::assertNotDispatched(FleetChanged::class);
});

test('the deploy page still streams when a broadcaster is configured', function (string $driver, bool $streaming) {
    config(['broadcasting.default' => $driver]);
    [$team, $member] = fleetTeam();
    $server = fleetPingServer($team);
    $application = fleetPingApplication($server);
    $deployment = Deployment::factory()->for($application)->create();

    $this->actingAs($member)
        ->get(route('servers.applications.deployments.show', [$server, $application, $deployment]))
        ->assertInertia(fn($page) => $page->where('streaming', Realtime::available())->where('streaming', $streaming));
})->with([
    ['null', false],
    ['log', false],
    ['reverb', true],
]);

test('a ping that cannot be queued is reported and never thrown at the caller', function (string $subject) {
    Exceptions::fake();
    $server = fleetPingServer();
    $application = fleetPingApplication($server);
    $deployment = Deployment::factory()->for($application)->create();
    config(['broadcasting.default' => 'reverb']);
    app()->instance('queue', fleetPingDownQueue());

    match ($subject) {
        'server' => FleetChanged::forServer($server),
        'application' => FleetChanged::forApplication($application),
        'deployment' => FleetChanged::forDeployment($deployment),
    };

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Queue is down');
})->with(['server', 'application', 'deployment']);

test('a deploy runs to the end and frees its lock when its ping cannot be queued', function () {
    Event::fake([DeploymentUpdated::class, DeploymentSucceeded::class]);
    Exceptions::fake();
    $deployment = fleetPingDeployment();
    config(['broadcasting.default' => 'reverb']);
    app()->instance('queue', fleetPingDownQueue());

    fleetPingDeploy($deployment);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 600)->get())->toBeTrue();
    Exceptions::assertReportedCount(2);
    Exceptions::assertNotReported(fn(Throwable $e): bool => $e->getMessage() !== 'Queue is down');
});

test('a collection finishes and keeps its alert when its ping cannot be queued', function () {
    Exceptions::fake();
    Notification::fake();
    $server = fleetPingServer();
    config(['broadcasting.default' => 'reverb']);
    app()->instance('queue', fleetPingDownQueue());

    fleetPingCollect($server, disk: 90);

    expect(Activity::query()->where('description', 'disk_pressure')->count())->toBe(1)
        ->and(ServerMetric::query()->where('server_id', $server->id)->count())->toBe(1)
        ->and($server->fresh()->last_connected_at)->not->toBeNull();
    Exceptions::assertReportedCount(1);
});

test('a provision is not failed by a ping that cannot be queued', function (string $stage) {
    Exceptions::fake();
    Notification::fake();
    $server = fleetPingServer();
    config(['broadcasting.default' => 'reverb']);
    app()->instance('queue', fleetPingDownQueue());

    match ($stage) {
        'finished' => ServerProvisioned::dispatch($server, 372),
        'stopped' => ServerProvisioningFailed::dispatch($server, 2, 'Base system'),
    };

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Queue is down');
})->with(['finished', 'stopped']);

test('an expiring certificate pings the team once its row is written', function () {
    Notification::fake();
    $server = fleetPingServer();
    $application = fleetPingApplication($server, ['domain' => 'shop.example.com']);
    $transport = (new FakeSSHTransport)->respondTo('@@cert', '@@cert shop.example.com notAfter=' . now()->addDays(3)->utc()->format('M j H:i:s Y') . " GMT\n");
    $seen = [];

    Event::listen(FleetChanged::class, function (FleetChanged $ping) use ($application, &$seen): void {
        $seen[] = [$ping->teamId, $ping->kind, $ping->subjectId, Activity::query()->where('subject_id', $application->id)->where('description', 'certificate_expiring')->exists()];
    });

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app(CertificateCheck::class)->run(app(SSHConnectionManager::class)->for($server));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($seen)->toBe([[$application->team_id, 'application', $application->id, true]]);
});

test('a provider task tells the fleet when it starts, moves on, ends, is tried again or is dismissed, and not on a heartbeat', function () {
    Event::fake([FleetChanged::class]);
    $server = Server::factory()->fromProvider(CloudProvider::factory()->create())->create();
    $pings = fn(): int => Event::dispatched(FleetChanged::class, fn(FleetChanged $event): bool => $event->kind === 'server' && $event->subjectId === $server->getKey())->count();

    CloudTask::start($server, CloudTask::RESIZE, 'power_off');
    $started = $pings();
    CloudTask::touch($server, ['polls' => 1]);
    $touched = $pings();
    CloudTask::advance($server, 'wait_off');
    $advanced = $pings();
    CloudTask::fail($server, 'Hetzner Cloud refused the request.');
    $failed = $pings();
    CloudTask::resume($server);
    $resumed = $pings();
    CloudTask::finish($server);
    $finished = $pings();
    CloudTask::clear($server);

    expect([$started, $touched, $advanced, $failed, $resumed, $finished, $pings()])->toBe([1, 1, 2, 3, 4, 5, 6]);
});

test('a destroyed server tells the fleet once it is gone', function () {
    Event::fake([FleetChanged::class]);
    $server = Server::factory()->fromProvider(CloudProvider::factory()->create())->create();
    CloudTask::start($server, CloudTask::DESTROY, 'delete');
    $before = Event::dispatched(FleetChanged::class)->count();

    app(DestroyServerTask::class)->finished($server, CloudTask::of($server), new FakeCloudApi);

    expect(Server::query()->find($server->getKey()))->toBeNull()
        ->and(Event::dispatched(FleetChanged::class, fn(FleetChanged $event): bool => $event->subjectId === $server->getKey())->count() - $before)->toBe(1);
});
