<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\ReadAccessLog;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Server\AccessLogReader;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeSSHTransport;

function logRouteMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function logRouteApp(Team $team): Application
{
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'host_key_fingerprint' => null]);

    return Application::factory()->for($team)->for($server)->create(['domain' => 'shop.example.com']);
}

function logRouteUrl(Application $application): string
{
    return route('servers.applications.logs', [$application->server_id, $application]);
}

function logRouteRead(Application $application, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new ReadAccessLog($application))->handle(app(SSHConnectionManager::class), new AccessLogReader);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('with nothing read yet the endpoint answers pending and queues a read', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = logRouteApp($team);

    $this->actingAs(logRouteMember($team, TeamRole::Member))
        ->get(logRouteUrl($application))
        ->assertStatus(202)
        ->assertExactJson(['lines' => [], 'pending' => true]);

    Queue::assertPushed(ReadAccessLog::class, 1);
});

test('fresh cached lines are returned without queueing another read', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = logRouteApp($team);
    $lines = [['time' => '14:05:01', 'text' => 'GET / 200 12ms']];

    Cache::put(ReadAccessLog::cacheKey($application), ['lines' => $lines, 'error' => null, 'read_at' => now()->subSeconds(3)->timestamp], 120);

    $this->actingAs(logRouteMember($team, TeamRole::Member))
        ->get(logRouteUrl($application))
        ->assertOk()
        ->assertExactJson(['lines' => $lines, 'error' => null, 'pending' => false]);

    Queue::assertNotPushed(ReadAccessLog::class);
});

test('a read that could not reach the server passes its error through', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = logRouteApp($team);

    Cache::put(ReadAccessLog::cacheKey($application), ['lines' => [], 'error' => "Couldn't reach web-1 to read the access log.", 'read_at' => now()->timestamp], 120);

    $this->actingAs(logRouteMember($team, TeamRole::Member))
        ->get(logRouteUrl($application))
        ->assertOk()
        ->assertJson(['lines' => [], 'error' => "Couldn't reach web-1 to read the access log.", 'pending' => false]);
});

test('lines older than ten seconds are still shown while a fresh read is queued', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = logRouteApp($team);
    $lines = [['time' => '14:04:50', 'text' => 'GET /old 200 9ms']];

    Cache::put(ReadAccessLog::cacheKey($application), ['lines' => $lines, 'error' => null, 'read_at' => now()->subSeconds(11)->timestamp], 120);

    $this->actingAs(logRouteMember($team, TeamRole::Member))
        ->get(logRouteUrl($application))
        ->assertOk()
        ->assertJson(['lines' => $lines, 'pending' => false]);

    Queue::assertPushed(ReadAccessLog::class, 1);
});

test('lines exactly ten seconds old still count as fresh', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = logRouteApp($team);

    Cache::put(ReadAccessLog::cacheKey($application), ['lines' => [], 'error' => null, 'read_at' => now()->subSeconds(10)->timestamp], 120);

    $this->actingAs(logRouteMember($team, TeamRole::Member))->get(logRouteUrl($application))->assertOk();

    Queue::assertNotPushed(ReadAccessLog::class);
});

test('another team cannot read the logs and nothing is queued', function () {
    Queue::fake();
    $application = logRouteApp(Team::factory()->create());

    $this->actingAs(logRouteMember(Team::factory()->create(), TeamRole::Owner))
        ->get(logRouteUrl($application))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('logs addressed through another server are a not found', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = logRouteApp($team);
    $other = Server::factory()->for($team)->create();

    $this->actingAs(logRouteMember($team, TeamRole::Owner))
        ->get(route('servers.applications.logs', [$other, $application]))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('the logs endpoint requires authentication and is rate limited', function () {
    $application = logRouteApp(Team::factory()->create());

    $this->get(logRouteUrl($application))->assertRedirect(route('login'));

    expect(Route::getRoutes()->getByName('servers.applications.logs')->gatherMiddleware())->toContain('throttle:30,1');
});

test('a read caches the application requests with the moment they were read', function () {
    $application = logRouteApp(Team::factory()->create());
    $transport = (new FakeSSHTransport)->respondTo('access.log', json_encode([
        'ts' => 1789308300,
        'request' => ['host' => 'shop.example.com', 'method' => 'GET', 'uri' => '/cart'],
        'status' => 200,
        'duration' => 0.02,
    ], JSON_THROW_ON_ERROR));

    logRouteRead($application, $transport);

    expect(Cache::get(ReadAccessLog::cacheKey($application)))->toBe([
        'lines' => [['time' => '14:05:00', 'text' => 'GET /cart 200 20ms']],
        'error' => null,
        'read_at' => now()->timestamp,
    ])->and($transport->connected())->toBeFalse();
});

test('a read that cannot reach the server caches an error instead of failing', function () {
    $application = logRouteApp(Team::factory()->create());

    logRouteRead($application, (new FakeSSHTransport)->failLogin());

    expect(Cache::get(ReadAccessLog::cacheKey($application)))->toBe([
        'lines' => [],
        'error' => "Couldn't reach web-1 to read the access log.",
        'read_at' => now()->timestamp,
    ]);
});

test('a cached read expires after two minutes', function () {
    $application = logRouteApp(Team::factory()->create());

    logRouteRead($application, new FakeSSHTransport);

    $this->travel(ReadAccessLog::TTL_SECONDS + 1)->seconds();

    expect(Cache::get(ReadAccessLog::cacheKey($application)))->toBeNull();
});

test('reads are unique per application so polling cannot pile them up', function () {
    $application = logRouteApp(Team::factory()->create());
    $job = new ReadAccessLog($application);

    expect($job->uniqueId())->toBe((string) $application->id)
        ->and($job->uniqueFor)->toBe(15)
        ->and($job->queue)->toBe('default')
        ->and(ReadAccessLog::cacheKey($application))->toBe('access-log:' . $application->id);
});
