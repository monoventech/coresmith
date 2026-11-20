<?php

declare(strict_types=1);

use App\Jobs\SyncServerFrontDoor;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Services\Address\CloudflareConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

const FRONT_DOOR_SYNC_API = 'https://api.cloudflare.test/client/v4';

beforeEach(function () {
    config(['coresmith.address.cloudflare_api' => FRONT_DOOR_SYNC_API, 'app.url' => 'https://beelink.coresmith.io']);
    Sleep::fake();
});

function frontDoorSyncServers(): array
{
    $home = Server::factory()->create([
        'name' => 'beelink',
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'runs_coresmith' => true,
        'public_addresses' => ['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], 'checked_at' => now()->toIso8601String()],
    ]);
    $door = Server::factory()->create([
        'team_id' => $home->team_id,
        'name' => 'web-1',
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'public_addresses' => ['ipv4' => '203.0.113.9', 'ipv4_open' => true, 'ipv6' => [], 'checked_at' => now()->toIso8601String()],
    ]);
    Application::factory()->for($home->team)->for($home)->create(['domain' => 'pencil.example']);

    return [$home, $door];
}

function frontDoorSyncRow(Server $home, Server $door, array $attributes = []): ServerFrontDoor
{
    return ServerFrontDoor::query()->create([
        'server_id' => $home->id,
        'door_server_id' => $door->id,
        'state' => ServerFrontDoor::OPENING,
        'subnet' => '10.205.0.0/24',
        'door_address' => '10.205.0.1',
        'home_address' => '10.205.0.2',
        ...$attributes,
    ]);
}

function frontDoorSyncTransports(Server $home, Server $door, ?string $handshake = 'now', bool $doorReachable = true): array
{
    $at = $handshake === null ? '' : "HOMEKEY=\t" . (now()->getTimestamp() - 5) . "\n";
    $homeTransport = (new FakeSSHTransport)->respondTo('command -v wg', "/usr/bin/wg\n")->respondTo('wg pubkey', "HOMEKEY=\n");
    $doorTransport = (new FakeSSHTransport)->respondTo('command -v wg', "/usr/bin/wg\n")->respondTo('wg pubkey', "DOORKEY=\n")->respondTo('latest-handshakes', $at);

    if (!$doorReachable) {
        $doorTransport->refuseConnections();
    }

    SSHConnectionManager::createTransportUsing(fn(Server $server): FakeSSHTransport => $server->is($door) ? $doorTransport : $homeTransport);

    return [$homeTransport, $doorTransport];
}

function frontDoorSyncWeb(array $pointedAt = ['beelink.coresmith.io' => '203.0.113.9', 'pencil.example' => '203.0.113.9'], array $statuses = [], array $records = []): void
{
    Http::fake(function (Request $request) use ($pointedAt, $statuses, $records) {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $answer = fn(mixed $result) => Http::response(['success' => true, 'errors' => [], 'result' => $result]);

        return match (true) {
            str_starts_with($url, 'https://cloudflare-dns.com/') => Http::response(['Status' => 0, 'Answer' => ($query['type'] ?? '') === 'A' && isset($pointedAt[$query['name'] ?? '']) ? [['name' => $query['name'], 'type' => 1, 'data' => $pointedAt[$query['name']]]] : []]),
            str_starts_with($url, FRONT_DOOR_SYNC_API . '/zones?name=coresmith.io') => $answer([['id' => 'z1', 'name' => 'coresmith.io', 'account' => ['id' => 'acc1']]]),
            str_starts_with($url, FRONT_DOOR_SYNC_API . '/zones?name=pencil.example') => $answer([['id' => 'z2', 'name' => 'pencil.example', 'account' => ['id' => 'acc1']]]),
            str_starts_with($url, FRONT_DOOR_SYNC_API . '/zones?name=') => $answer([]),
            str_contains($url, '/dns_records?') => $answer($records[$query['name'] ?? ''] ?? [['id' => 'r6', 'type' => 'AAAA', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']]),
            str_starts_with($url, FRONT_DOOR_SYNC_API) => $answer(['id' => 'new']),
            default => Http::response('ok', $statuses[(string) parse_url($url, PHP_URL_HOST)] ?? 200),
        };
    });
}

test('turning on links both servers, routes the domains through the door and points them in Cloudflare', function () {
    CloudflareConnection::connect('cf-token');
    [$home, $door] = frontDoorSyncServers();
    $frontDoor = frontDoorSyncRow($home, $door);
    [$homeTransport, $doorTransport] = frontDoorSyncTransports($home, $door);
    frontDoorSyncWeb();

    app()->call([new SyncServerFrontDoor($home), 'handle']);

    $frontDoor->refresh();
    $doorCommands = implode("\n", $doorTransport->commands());

    expect($doorTransport->uploads()['/etc/wireguard/coresmith-door.conf.next'])->toContain("PublicKey = HOMEKEY=\nAllowedIPs = 10.205.0.2/32\n")
        ->and($homeTransport->uploads()['/etc/wireguard/coresmith-door.conf.next'])->toContain("Endpoint = 203.0.113.9:51871\n")
        ->and($doorCommands)->toContain('ufw allow 51871/udp')
        ->toContain('"@id":"door-' . $home->id . '"')
        ->toContain('"host":["beelink.coresmith.io","pencil.example","www.pencil.example"]')
        ->and(implode("\n", $homeTransport->commands()))->toContain('{"source":"static","ranges":["10.205.0.1/32"]}')
        ->and($frontDoor->state)->toBe(ServerFrontDoor::OPEN)
        ->and($frontDoor->hosts)->toEqual(['beelink.coresmith.io', 'pencil.example'])
        ->and($frontDoor->failing)->toEqual([])
        ->and($frontDoor->door_public_key)->toBe('DOORKEY=')
        ->and($frontDoor->home_public_key)->toBe('HOMEKEY=');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['content'] ?? null) === '203.0.113.9' && ($request->data()['name'] ?? null) === 'pencil.example' && ($request->data()['proxied'] ?? null) === false);
});

test('no handshake within a minute fails, naming the door and its UDP port', function () {
    [$home, $door] = frontDoorSyncServers();
    $frontDoor = frontDoorSyncRow($home, $door);
    frontDoorSyncTransports($home, $door, handshake: null);
    frontDoorSyncWeb();

    app()->call([new SyncServerFrontDoor($home), 'handle']);

    expect($frontDoor->fresh()->state)->toBe(ServerFrontDoor::FAILED)
        ->and($frontDoor->fresh()->error)->toBe("web-1 didn't answer over WireGuard. Allow UDP 51871 into web-1 (203.0.113.9) in your provider's firewall, then try again.");
});

test("domains the door can't pass on, or that don't point at it yet, are listed with why and left out of the count", function () {
    [$home, $door] = frontDoorSyncServers();
    $frontDoor = frontDoorSyncRow($home, $door);
    frontDoorSyncTransports($home, $door);
    frontDoorSyncWeb(pointedAt: ['beelink.coresmith.io' => '198.51.100.7', 'pencil.example' => '203.0.113.9'], statuses: ['pencil.example' => 502]);

    app()->call([new SyncServerFrontDoor($home), 'handle']);

    $frontDoor->refresh();

    expect($frontDoor->state)->toBe(ServerFrontDoor::OPEN)
        ->and($frontDoor->hosts)->toEqual([])
        ->and($frontDoor->failing)->toEqual([
            ['host' => 'beelink.coresmith.io', 'reason' => "beelink.coresmith.io doesn't point at web-1 yet.", 'kind' => 'dns'],
            ['host' => 'pencil.example', 'reason' => "pencil.example didn't load through web-1. Check that its app is deployed on beelink.", 'kind' => 'path'],
        ]);
});

test("on Cloud the instance's Cloudflare token is never used for a team's domains", function () {
    config(['coresmith.edition' => 'cloud']);
    CloudflareConnection::connect('cf-token');
    [$home, $door] = frontDoorSyncServers();
    frontDoorSyncRow($home, $door);
    frontDoorSyncTransports($home, $door);
    frontDoorSyncWeb();

    app()->call([new SyncServerFrontDoor($home), 'handle']);

    Http::assertNotSent(fn(Request $request): bool => str_starts_with($request->url(), FRONT_DOOR_SYNC_API));
});

test('turning off while the door is unreachable still cleans the home server, points DNS back and removes the row', function () {
    CloudflareConnection::connect('cf-token');
    [$home, $door] = frontDoorSyncServers();
    frontDoorSyncRow($home, $door, ['state' => ServerFrontDoor::CLOSING, 'hosts' => ['pencil.example'], 'home_public_key' => 'HOMEKEY=', 'door_public_key' => 'DOORKEY=']);
    [$homeTransport] = frontDoorSyncTransports($home, $door, doorReachable: false);
    frontDoorSyncWeb(records: ['pencil.example' => [['id' => 'a', 'type' => 'A', 'content' => '203.0.113.9']]]);

    app()->call([new SyncServerFrontDoor($home), 'handle']);

    expect(ServerFrontDoor::query()->count())->toBe(0)
        ->and(implode("\n", $homeTransport->commands()))
        ->toContain('systemctl disable --now wg-quick@coresmith-door')
        ->toContain("-X DELETE 'http://localhost:2019/config/apps/http/servers/coresmith/trusted_proxies'")
        ->and(Activity::query()->where('description', 'front_door_close_incomplete')->first()?->properties['steps'])->toContain('door route');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/a'));
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['type'] ?? null) === 'AAAA' && ($request->data()['content'] ?? null) === '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8');
});

test("turning off points back domains Coresmith moved to the door even when they didn't come through", function () {
    CloudflareConnection::connect('cf-token');
    [$home, $door] = frontDoorSyncServers();
    frontDoorSyncRow($home, $door, ['state' => ServerFrontDoor::CLOSING, 'hosts' => [], 'failing' => [['host' => 'pencil.example', 'reason' => "pencil.example didn't load through web-1. Check that its app is deployed on beelink.", 'kind' => 'path']], 'home_public_key' => 'HOMEKEY=', 'door_public_key' => 'DOORKEY=']);
    frontDoorSyncTransports($home, $door);
    frontDoorSyncWeb(records: ['pencil.example' => [['id' => 'a', 'type' => 'A', 'content' => '203.0.113.9']], 'beelink.coresmith.io' => [['id' => 'b', 'type' => 'A', 'content' => '203.0.113.9']]]);

    app()->call([new SyncServerFrontDoor($home), 'handle']);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/a'));
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/b'));
});

test("turning off one of two homes keeps the other's peer and the door's port", function () {
    [$home, $door] = frontDoorSyncServers();
    $other = Server::factory()->create(['team_id' => $home->team_id, 'host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    frontDoorSyncRow($home, $door, ['state' => ServerFrontDoor::CLOSING, 'hosts' => [], 'home_public_key' => 'HOMEKEY=', 'door_public_key' => 'DOORKEY=']);
    frontDoorSyncRow($other, $door, ['state' => ServerFrontDoor::OPEN, 'home_address' => '10.205.0.3', 'home_public_key' => 'OTHERKEY=', 'door_public_key' => 'DOORKEY=']);
    [, $doorTransport] = frontDoorSyncTransports($home, $door);
    frontDoorSyncWeb();

    app()->call([new SyncServerFrontDoor($home), 'handle']);

    $config = $doorTransport->uploads()['/etc/wireguard/coresmith-door.conf.next'] ?? '';

    expect($config)->toContain("PublicKey = OTHERKEY=\nAllowedIPs = 10.205.0.3/32\n")
        ->not->toContain('HOMEKEY=')
        ->and(implode("\n", $doorTransport->commands()))->not->toContain('ufw delete allow 51871/udp')
        ->not->toContain('systemctl disable --now wg-quick@coresmith-door')
        ->and(ServerFrontDoor::query()->pluck('server_id')->all())->toBe([$other->id]);
});

test('every hour at :35 Coresmith checks each open front door, and only open ones', function () {
    Queue::fake();
    [$home, $door] = frontDoorSyncServers();
    $other = Server::factory()->create(['team_id' => $home->team_id]);
    frontDoorSyncRow($home, $door, ['state' => ServerFrontDoor::OPEN]);
    frontDoorSyncRow($other, $door, ['state' => ServerFrontDoor::FAILED, 'home_address' => '10.205.0.3']);
    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:sync-front-doors');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('35 * * * *');

    $event->run(app());

    Queue::assertPushed(SyncServerFrontDoor::class, 1);
    Queue::assertPushed(SyncServerFrontDoor::class, fn(SyncServerFrontDoor $job): bool => $job->server->is($home));
});
