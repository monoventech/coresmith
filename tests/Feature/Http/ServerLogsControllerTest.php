<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\ReadServerLogs;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Logs\LogQuery;
use App\Services\Logs\ServerLogs;
use App\Services\Panel\FleetSnapshot;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function serverLogsMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function serverLogsServer(Team $team): Server
{
    return Server::factory()->for($team)->create(['name' => 'web-1', 'host_key_fingerprint' => null]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
});

test('a server\'s logs page opens for someone who can change the server', function () {
    $team = Team::factory()->create();
    $server = serverLogsServer($team);

    $this->actingAs(serverLogsMember($team, TeamRole::Owner))
        ->get(route('servers.logs', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('servers/Logs')
            ->where('server.lines_href', route('servers.logs.lines', $server))
            ->where('sources.0', ['key' => 'requests', 'label' => 'Requests', 'count' => 0])
            ->where('sources.3', ['key' => 'signins', 'label' => 'Sign-ins', 'count' => 0])
            ->where('crumbs', fn($crumbs): bool => collect($crumbs)->last()['label'] === 'Logs'));
});

test('a member who cannot change the server cannot read its logs, and another team gets a not found', function () {
    $team = Team::factory()->create();
    $server = serverLogsServer($team);

    $this->actingAs(serverLogsMember($team, TeamRole::Member))->get(route('servers.logs', $server))->assertForbidden();
    $this->actingAs(serverLogsMember($team, TeamRole::Member))->getJson(route('servers.logs.lines', $server))->assertForbidden();
    $this->actingAs(serverLogsMember(Team::factory()->create(), TeamRole::Owner))->get(route('servers.logs', $server))->assertNotFound();
});

test('the first read is pending and starts a read, and cached lines come back filtered', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = serverLogsServer($team);
    $owner = serverLogsMember($team, TeamRole::Owner);

    $this->actingAs($owner)->getJson(route('servers.logs.lines', $server))->assertStatus(202)->assertJson(['pending' => true]);
    Queue::assertPushed(ReadServerLogs::class, 1);

    Cache::put(ReadServerLogs::cacheKey($server, new LogQuery), [
        'entries' => [
            ['id' => 'a', 'at' => 1.0, 'sort' => 1.0, 'source' => 'signins', 'process' => null, 'level' => 'info', 'text' => 'Accepted publickey for deploy', 'more' => [], 'request' => null],
            ['id' => 'b', 'at' => 2.0, 'sort' => 2.0, 'source' => 'firewall', 'process' => null, 'level' => 'warning', 'text' => 'Ban 203.0.113.9', 'more' => [], 'request' => null],
        ],
        'stats' => null,
        'capped' => false,
        'error' => null,
        'read_at' => now()->timestamp,
    ], 120);

    $this->actingAs($owner)
        ->getJson(route('servers.logs.lines', [$server, 'sources' => ['firewall']]))
        ->assertOk()
        ->assertJsonCount(1, 'entries')
        ->assertJsonPath('entries.0.text', 'Ban 203.0.113.9')
        ->assertJsonPath('sources.4', ['key' => 'firewall', 'label' => 'Blocked addresses', 'count' => 1]);
});

test('a quiet source shows its lines, and the note about older lines shows only when lines the filter wants were left out', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = serverLogsServer($team);
    $owner = serverLogsMember($team, TeamRole::Owner);

    Cache::put(ReadServerLogs::cacheKey($server, new LogQuery), [
        'entries' => [
            ['id' => 'a', 'at' => 1.0, 'sort' => 1.0, 'source' => 'system', 'process' => null, 'level' => 'warning', 'text' => 'tailscaled.service: Got notification message', 'more' => [], 'request' => null],
            ['id' => 'b', 'at' => 2.0, 'sort' => 2.0, 'source' => 'web', 'process' => null, 'level' => 'info', 'text' => 'Caddy reloaded', 'more' => [], 'request' => null],
        ],
        'stats' => null,
        'capped' => true,
        'cut' => [['source' => 'web', 'level' => 'info']],
        'error' => null,
        'read_at' => now()->timestamp,
    ], 120);

    $this->actingAs($owner)
        ->getJson(route('servers.logs.lines', [$server, 'sources' => ['system']]))
        ->assertOk()
        ->assertJsonPath('entries.0.text', 'tailscaled.service: Got notification message')
        ->assertJsonPath('capped', false);
    $this->actingAs($owner)->getJson(route('servers.logs.lines', $server))->assertJsonPath('capped', true);
    $this->actingAs($owner)->getJson(route('servers.logs.lines', [$server, 'level' => 'warnings']))->assertJsonPath('capped', false);
});

test('a read caches what it found, and keeps it with an error when the server cannot be reached', function () {
    $team = Team::factory()->create();
    $server = serverLogsServer($team);
    $transport = new FakeSSHTransport;
    $transport->onCommand('date +%z', function () use ($transport): void {
        preg_match('/==([0-9a-f]{16})==/', (string) last($transport->commands()), $found);
        $transport->respondTo('date +%z', "=={$found[1]}== meta\n" . now()->getTimestamp() . "\n+0000\n=={$found[1]}== signins\n" . json_encode(['__REALTIME_TIMESTAMP' => (string) (now()->subMinute()->getTimestamp() * 1000000), 'MESSAGE' => 'Accepted publickey for deploy']) . "\n");
    });
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new ReadServerLogs($server, new LogQuery))->handle(app(SSHConnectionManager::class), app(ServerLogs::class));
        SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->refuseConnections());
        $this->travel(5)->seconds();
        (new ReadServerLogs($server, new LogQuery))->handle(app(SSHConnectionManager::class), app(ServerLogs::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    $cached = Cache::get(ReadServerLogs::cacheKey($server, new LogQuery));

    expect(array_column($cached['entries'], 'text'))->toBe(['Accepted publickey for deploy'])
        ->and($cached['error'])->toBe("Couldn't reach web-1 to read the logs. Coresmith tries again in a moment.");
});

test('the fleet card opens server logs for someone who can change the server, and Activity for everyone else', function () {
    $team = Team::factory()->create();
    $server = serverLogsServer($team);

    expect((new FleetSnapshot($team, serverLogsMember($team, TeamRole::Owner)))->card($server->fresh())['logs_href'])->toBe(route('servers.logs', $server))
        ->and((new FleetSnapshot($team, serverLogsMember($team, TeamRole::Member)))->card($server->fresh())['logs_href'])->toBe(route('servers.activity', $server))
        ->and((new FleetSnapshot($team))->card($server->fresh())['logs_href'])->toBe(route('servers.activity', $server));
});
