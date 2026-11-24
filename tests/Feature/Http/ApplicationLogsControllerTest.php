<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Jobs\ReadAppLogs;
use App\Models\Application;
use App\Models\AppProcess;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Logs\AppLogs;
use App\Services\Logs\LogQuery;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function appLogsPageMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function appLogsPageApp(Team $team): Application
{
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'host_key_fingerprint' => null]);

    return Application::factory()->for($team)->for($server)->create([
        'name' => 'shop',
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'processes' => [['name' => 'worker', 'command' => 'php artisan queue:work']],
    ]);
}

function appLogsPageEntry(array $overrides = []): array
{
    $at = (float) now()->subMinute()->getTimestamp();

    return array_merge([
        'id' => 'e-' . fake()->unique()->numerify('####'),
        'at' => $at,
        'sort' => $at,
        'source' => 'requests',
        'process' => null,
        'level' => 'info',
        'text' => 'GET / 200 12ms',
        'more' => [],
        'request' => ['method' => 'GET', 'path' => '/', 'status' => 200, 'ms' => 12, 'bytes' => 512, 'ip' => '203.0.113.42', 'agent' => null, 'referer' => null, 'host' => 'shop.example.com', 'proto' => 'HTTP/2.0', 'at' => $at],
    ], $overrides);
}

function appLogsPageCache(Application $application, array $entries, LogQuery $query = new LogQuery, int $age = 0): void
{
    Cache::put(ReadAppLogs::cacheKey($application, $query), [
        'entries' => $entries,
        'stats' => ['requests' => 1, 'per_minute' => 0.1, 'server_errors' => 0, 'error_rate' => 0.0, 'p50' => 12, 'p95' => 12, 'slowest' => []],
        'capped' => false,
        'error' => null,
        'read_at' => now()->subSeconds($age)->timestamp,
    ], 120);
}

function appLogsPageLines(Application $application, array $query = []): string
{
    return route('servers.applications.logs.lines', [$application->server, $application, ...$query]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
});

test('the logs page opens with the app\'s sources, processes and the windows to pick from', function () {
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);
    AppProcess::query()->create(['application_id' => $application->id, 'unit' => 'coresmith-app-shop-worker', 'process' => 'worker', 'active_state' => 'active', 'sub_state' => 'running', 'memory_bytes' => 96 * 1048576, 'restarts' => 0, 'restart_times' => [], 'started_at' => now()->subHours(3), 'read_at' => now()]);

    $this->actingAs(appLogsPageMember($team))
        ->get(route('servers.applications.logs', [$application->server, $application]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('applications/Logs')
            ->where('application.name', 'shop')
            ->where('application.lines_href', appLogsPageLines($application))
            ->where('sources', [
                ['key' => 'requests', 'label' => 'Requests', 'count' => 0],
                ['key' => 'php', 'label' => 'PHP errors', 'count' => 0],
                ['key' => 'process:worker', 'label' => 'worker', 'count' => 0],
                ['key' => 'framework', 'label' => 'Laravel log', 'count' => 0],
                ['key' => 'scheduler', 'label' => 'Scheduler', 'count' => 0],
                ['key' => 'deploys', 'label' => 'Deploys', 'count' => 0],
            ])
            ->where('processes.0.process', 'worker')
            ->where('processes.0.state.label', 'Running')
            ->has('windows', 5)
            ->where('filters', ['window' => '1h', 'q' => '', 'sources' => [], 'level' => 'all', 'around' => null])
            ->where('crumbs', fn($crumbs): bool => collect($crumbs)->last()['label'] === 'Logs'));
});

test('the page keeps the filters in its address, so a link opens the same view', function () {
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);

    $this->actingAs(appLogsPageMember($team))
        ->get(route('servers.applications.logs', [$application->server, $application, 'window' => '24h', 'q' => 'checkout', 'level' => 'errors', 'sources' => ['requests']]))
        ->assertInertia(fn($page) => $page->where('filters', ['window' => '24h', 'q' => 'checkout', 'sources' => ['requests'], 'level' => 'errors', 'around' => null]));
});

test('another team\'s app logs are a not found, page and lines alike', function () {
    $application = appLogsPageApp(Team::factory()->create());
    $outsider = appLogsPageMember(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)->get(route('servers.applications.logs', [$application->server, $application]))->assertNotFound();
    $this->actingAs($outsider)->getJson(appLogsPageLines($application))->assertNotFound();
});

test('the first read is pending and starts a read on the server', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);

    $this->actingAs(appLogsPageMember($team))
        ->getJson(appLogsPageLines($application))
        ->assertStatus(202)
        ->assertJson(['pending' => true, 'entries' => []]);

    Queue::assertPushed(ReadAppLogs::class, 1);
});

test('lines come back filtered by source and level, with the app\'s deploys linked and a count for each source', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);
    $deployment = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'release' => '20261124-115900', 'created_at' => now()->subMinutes(2), 'finished_at' => now()->subMinutes(2)]);
    appLogsPageCache($application, [
        appLogsPageEntry(['text' => 'GET / 200 12ms']),
        appLogsPageEntry(['text' => 'GET /pay 500 40ms', 'level' => 'error']),
        appLogsPageEntry(['source' => 'process:worker', 'process' => 'worker', 'text' => 'Processing SendReceipt', 'request' => null]),
    ]);

    $this->actingAs(appLogsPageMember($team))
        ->getJson(appLogsPageLines($application, ['level' => 'errors']))
        ->assertOk()
        ->assertJsonPath('entries.0.source', 'deploys')
        ->assertJsonPath('entries.0.href', route('servers.applications.deployments.show', [$application->server, $application, $deployment]))
        ->assertJsonPath('entries.1.text', 'GET /pay 500 40ms')
        ->assertJsonCount(2, 'entries')
        ->assertJsonPath('sources.0', ['key' => 'requests', 'label' => 'Requests', 'count' => 2])
        ->assertJsonPath('sources.2', ['key' => 'process:worker', 'label' => 'worker', 'count' => 1]);

    $this->actingAs(appLogsPageMember($team))
        ->getJson(appLogsPageLines($application, ['sources' => ['process:worker']]))
        ->assertJsonCount(1, 'entries')
        ->assertJsonPath('entries.0.text', 'Processing SendReceipt')
        ->assertJsonPath('entries.0.at', (int) (now()->subMinute()->getTimestamp() * 1000));

    Queue::assertNothingPushed();
});

test('lines older than two seconds are shown while a fresh read is queued', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);
    appLogsPageCache($application, [appLogsPageEntry()], age: 3);

    $this->actingAs(appLogsPageMember($team))->getJson(appLogsPageLines($application))->assertOk()->assertJsonCount(1, 'entries');

    Queue::assertPushed(ReadAppLogs::class, 1);
});

test('only the newest 300 lines are sent, and the page is told there were more', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);
    appLogsPageCache($application, array_map(fn(int $index): array => appLogsPageEntry(['id' => "e{$index}", 'sort' => (float) $index, 'text' => "line {$index}"]), range(1, 320)));

    $this->actingAs(appLogsPageMember($team))
        ->getJson(appLogsPageLines($application))
        ->assertJsonCount(300, 'entries')
        ->assertJsonPath('entries.299.text', 'line 320')
        ->assertJsonPath('capped', true);
});

test('reading the lines is limited to 90 a minute', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);
    $user = appLogsPageMember($team);
    appLogsPageCache($application, []);

    foreach (range(1, 90) as $read) {
        $this->actingAs($user)->getJson(appLogsPageLines($application))->assertOk();
    }

    $this->actingAs($user)->getJson(appLogsPageLines($application))->assertTooManyRequests();
});

test('a read keeps the lines it had when the server cannot be reached, and says so', function () {
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);
    appLogsPageCache($application, [appLogsPageEntry(['text' => 'still here'])], age: 10);
    $transport = (new FakeSSHTransport)->refuseConnections();
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new ReadAppLogs($application, new LogQuery))->handle(app(SSHConnectionManager::class), app(AppLogs::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    $cached = Cache::get(ReadAppLogs::cacheKey($application, new LogQuery));

    expect($cached['entries'][0]['text'])->toBe('still here')
        ->and($cached['error'])->toBe("Couldn't reach web-1 to read the logs. Coresmith tries again in a moment.")
        ->and($cached['read_at'])->toBe(now()->timestamp);
});

test('a read caches what the reader found on the server', function () {
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);
    $transport = new FakeSSHTransport;
    $transport->onCommand('date +%z', function () use ($transport): void {
        preg_match('/==([0-9a-f]{16})==/', (string) last($transport->commands()), $found);
        $transport->respondTo('date +%z', "=={$found[1]}== meta\n" . now()->getTimestamp() . "\n+0000\n=={$found[1]}== process worker\n40 " . now()->getTimestamp() . "\n" . now()->subSeconds(30)->format('Y-m-d H:i:s') . " Processing SendReceipt\n");
    });
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new ReadAppLogs($application, new LogQuery))->handle(app(SSHConnectionManager::class), app(AppLogs::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    $cached = Cache::get(ReadAppLogs::cacheKey($application, new LogQuery));

    expect($cached['error'])->toBeNull()
        ->and(array_column($cached['entries'], 'text'))->toBe(['Processing SendReceipt']);
});

test('a search leaves out the deploys that do not match it, like every other source', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = appLogsPageApp($team);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20261124-115800', 'commit_hash' => str_repeat('a1b2c3d', 5) . 'aaaaa', 'commit_message' => 'Fix tax on gift cards', 'created_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(3)]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'release' => '20261124-115900', 'created_at' => now()->subMinutes(2), 'finished_at' => now()->subMinutes(2)]);
    appLogsPageCache($application, [], new LogQuery('1h', 'GIFT'));

    $this->actingAs(appLogsPageMember($team))
        ->getJson(appLogsPageLines($application, ['q' => 'GIFT']))
        ->assertOk()
        ->assertJsonCount(1, 'entries')
        ->assertJsonPath('entries.0.source', 'deploys')
        ->assertJsonPath('entries.0.text', fn(string $text): bool => str_contains($text, 'Fix tax on gift cards'));
});
