<?php

declare(strict_types=1);

use App\Jobs\SyncServerTunnel;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerTunnel;
use App\Services\Address\CloudflareConnection;
use App\Services\Network\AddressResolver;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Tunnel\Cloudflared;
use App\Services\Tunnel\CloudflareTunnels;
use App\Services\Tunnel\TunnelSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

const TUNNEL_SYNC_API = 'https://api.cloudflare.test/client/v4';

beforeEach(function () {
    config(['coresmith.address.cloudflare_api' => TUNNEL_SYNC_API, 'app.url' => 'https://beelink.coresmith.io']);
    Sleep::fake();
    tunnelSyncResolves([]);
});

function tunnelSyncResolves(array $answers): void
{
    app()->instance(AddressResolver::class, new class($answers) implements AddressResolver
    {
        public function __construct(private readonly array $answers) {}

        public function addresses(string $host): array
        {
            return $this->answers[$host] ?? [];
        }
    });
}

function tunnelSyncAnswer(mixed $result): array
{
    return ['success' => true, 'errors' => [], 'result' => $result];
}

function tunnelSyncServer(array $attributes = []): Server
{
    return Server::factory()->create([
        'name' => 'beelink',
        'host_key_fingerprint' => null,
        'runs_coresmith' => true,
        'ip_address' => '192.168.0.248',
        'public_addresses' => ['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], 'checked_at' => now()->toIso8601String()],
        ...$attributes,
    ]);
}

function tunnelSyncTransport(): FakeSSHTransport
{
    $transport = (new FakeSSHTransport)->respondTo('command -v cloudflared', "/usr/bin/cloudflared\n");
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return $transport;
}

function tunnelSyncCloudflare(array $records = [], string $status = 'healthy', int $tunnels = 200, ?Closure $onConfigure = null): void
{
    Http::fake(function (Request $request) use ($records, $status, $tunnels, $onConfigure) {
        $url = $request->url();
        $method = $request->method();

        return match (true) {
            str_contains($url, '/zones?name=coresmith.io') => Http::response(tunnelSyncAnswer([['id' => 'z1', 'name' => 'coresmith.io', 'account' => ['id' => 'acc1']]])),
            str_contains($url, '/zones?name=pencil.example') => Http::response(tunnelSyncAnswer([['id' => 'z2', 'name' => 'pencil.example', 'account' => ['id' => 'acc1']]])),
            str_contains($url, '/zones?name=') => Http::response(tunnelSyncAnswer([])),
            str_contains($url, '/cfd_tunnel?') => $tunnels === 200 ? Http::response(tunnelSyncAnswer([])) : Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']], 'result' => null], $tunnels),
            str_ends_with($url, '/cfd_tunnel') && $method === 'POST' => Http::response(tunnelSyncAnswer(['id' => 'f00d'])),
            str_ends_with($url, '/cfd_tunnel/f00d/token') => Http::response(tunnelSyncAnswer('run-token')),
            str_ends_with($url, '/cfd_tunnel/f00d/configurations') => tap(Http::response(tunnelSyncAnswer([])), fn() => $onConfigure === null ? null : $onConfigure()),
            str_ends_with($url, '/cfd_tunnel/f00d') && $method === 'GET' => Http::response(tunnelSyncAnswer(['status' => $status])),
            str_contains($url, '/dns_records?') => Http::response(tunnelSyncAnswer($records[(string) Str::of($url)->after('name=')->before('&')] ?? [])),
            default => Http::response(tunnelSyncAnswer(['id' => 'new'])),
        };
    });
}

test('turning on makes the tunnel, starts the connector and routes the domains it can', function () {
    CloudflareConnection::connect('cf-token');
    $server = tunnelSyncServer();
    Application::factory()->for($server->team)->for($server)->create(['domain' => 'pencil.example']);
    Application::factory()->for($server->team)->for($server)->create(['domain' => 'notes.other.example']);
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPENING]);
    $transport = tunnelSyncTransport();
    tunnelSyncCloudflare(['beelink.coresmith.io' => [['id' => 'r6', 'type' => 'AAAA', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']]]);

    app()->call([new SyncServerTunnel($server), 'handle']);

    $tunnel->refresh();

    expect($tunnel->state)->toBe(ServerTunnel::OPEN)
        ->and($tunnel->tunnel_id)->toBe('f00d')
        ->and($tunnel->account_id)->toBe('acc1')
        ->and($tunnel->hosts)->toBe(['beelink.coresmith.io', 'pencil.example'])
        ->and($tunnel->skipped)->toBe([['host' => 'notes.other.example', 'reason' => "Coresmith's Cloudflare token can't see the zone notes.other.example is in. Add that zone to the token in Cloudflare, then check again."]])
        ->and($tunnel->error)->toBeNull()
        ->and($transport->uploads())->toMatchArray([
            '/etc/coresmith-tunnel/token.env.next' => "TUNNEL_TOKEN=run-token\n",
            '/etc/coresmith-tunnel/unit.next' => Cloudflared::unit('/usr/bin/cloudflared'),
        ])
        ->and($transport->commands())->toContain(Cloudflared::swap())
        ->and(implode("\n", $transport->commands()))->not->toContain('run-token')
        ->and(Activity::query()->where('description', 'tunnel_opened')->where('subject_id', $server->id)->exists())->toBeTrue();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/cfd_tunnel') && $request->data() === ['name' => TunnelSync::name($server), 'config_src' => 'cloudflare']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/configurations')
        && $request->data() === ['config' => ['ingress' => CloudflareTunnels::ingress(['beelink.coresmith.io', 'pencil.example', 'www.pencil.example'])]]);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/r6'));
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['name'] ?? null) === 'beelink.coresmith.io' && $request->data()['content'] === 'f00d.cfargotunnel.com');
});

test('the ingress sends certificate checks to port 80 and everything else to Caddy on 443 under the right name', function () {
    expect(CloudflareTunnels::ingress(['pencil.example']))->toBe([
        ['hostname' => 'pencil.example', 'path' => '^/\.well-known/acme-challenge/', 'service' => 'http://127.0.0.1:80'],
        ['hostname' => 'pencil.example', 'service' => 'https://127.0.0.1:443', 'originRequest' => ['originServerName' => 'pencil.example', 'noTLSVerify' => true]],
        ['service' => 'http_status:404'],
    ]);
});

test('without a Cloudflare token the tunnel stops and says to connect it', function () {
    $server = tunnelSyncServer();
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPENING]);
    tunnelSyncTransport();
    Http::fake();

    app()->call([new SyncServerTunnel($server), 'handle']);

    expect($tunnel->fresh())->state->toBe(ServerTunnel::FAILED)->error->toBe(TunnelSync::NO_TOKEN);
    Http::assertNothingSent();
});

test('a token that cannot manage tunnels stops before anything changes', function () {
    CloudflareConnection::connect('cf-token');
    $server = tunnelSyncServer();
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPENING]);
    $transport = tunnelSyncTransport();
    tunnelSyncCloudflare(tunnels: 403);

    app()->call([new SyncServerTunnel($server), 'handle']);

    expect($tunnel->fresh())->state->toBe(ServerTunnel::FAILED)->error->toBe(CloudflareTunnels::NO_TUNNEL_PERMISSION)
        ->and($transport->uploads())->toBe([]);
    Http::assertNotSent(fn(Request $request): bool => $request->method() !== 'GET');
});

test('a connector Cloudflare never sees connect leaves the DNS alone and says what to check', function () {
    CloudflareConnection::connect('cf-token');
    $server = tunnelSyncServer();
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPENING]);
    tunnelSyncTransport();
    tunnelSyncCloudflare(status: 'inactive');

    app()->call([new SyncServerTunnel($server), 'handle']);

    expect($tunnel->fresh())->state->toBe(ServerTunnel::FAILED)
        ->error->toBe("Cloudflare's connector is running on beelink, but Cloudflare hasn't seen it connect after a minute. Check that beelink can reach the internet, then try again.");
    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), '/dns_records') && $request->method() !== 'GET');
});

test('a check that fails while the tunnel is on keeps it on and shows why', function () {
    CloudflareConnection::connect('cf-token');
    $server = tunnelSyncServer();
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN, 'account_id' => 'acc1', 'tunnel_id' => 'f00d', 'hosts' => ['beelink.coresmith.io']]);
    tunnelSyncTransport();
    tunnelSyncCloudflare(tunnels: 403);

    app()->call([new SyncServerTunnel($server), 'handle']);

    expect($tunnel->fresh())->state->toBe(ServerTunnel::OPEN)->error->toBe(CloudflareTunnels::NO_TUNNEL_PERMISSION);
});

test('turning off points the domains back at the server, removes the connector and the tunnel', function () {
    CloudflareConnection::connect('cf-token');
    $server = tunnelSyncServer();
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::CLOSING, 'account_id' => 'acc1', 'tunnel_id' => 'f00d', 'hosts' => ['pencil.example']]);
    $transport = tunnelSyncTransport();
    tunnelSyncCloudflare(['pencil.example' => [['id' => 'c1', 'type' => 'CNAME', 'content' => 'f00d.cfargotunnel.com', 'proxied' => true]]]);

    app()->call([new SyncServerTunnel($server), 'handle']);

    expect(ServerTunnel::query()->find($tunnel->id))->toBeNull()
        ->and(implode("\n", $transport->commands()))->toContain('systemctl disable --now coresmith-tunnel')
        ->and(Activity::query()->where('description', 'tunnel_closed')->where('subject_id', $server->id)->exists())->toBeTrue();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/c1'));
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && $request->data() === ['type' => 'AAAA', 'name' => 'pencil.example', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', 'ttl' => 1, 'proxied' => false, 'comment' => 'Managed by Coresmith']);
    Http::assertNotSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['type'] ?? null) === 'A');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/cfd_tunnel/f00d'));
});

test('a turn off pressed while a check runs is carried out when the check ends', function () {
    CloudflareConnection::connect('cf-token');
    $server = tunnelSyncServer();
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN, 'account_id' => 'acc1', 'tunnel_id' => 'f00d', 'hosts' => []]);
    $transport = tunnelSyncTransport();
    tunnelSyncCloudflare(onConfigure: fn() => ServerTunnel::query()->whereKey($tunnel->id)->update(['state' => ServerTunnel::CLOSING]));

    app()->call([new SyncServerTunnel($server), 'handle']);

    expect(ServerTunnel::query()->find($tunnel->id))->toBeNull()
        ->and(implode("\n", $transport->commands()))->toContain('systemctl disable --now coresmith-tunnel');
});

test('a domain that already answers from somewhere Coresmith cannot see in Cloudflare is left alone', function () {
    CloudflareConnection::connect('cf-token');
    $server = tunnelSyncServer();
    Application::factory()->for($server->team)->for($server)->create(['domain' => 'pencil.example', 'additional_domains' => []]);
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPENING]);
    tunnelSyncTransport();
    tunnelSyncResolves(['pencil.example' => ['104.21.5.5', '2606:4700:3030::6815:505']]);
    tunnelSyncCloudflare();

    app()->call([new SyncServerTunnel($server), 'handle']);

    $tunnel->refresh();

    expect($tunnel->state)->toBe(ServerTunnel::OPEN)
        ->and($tunnel->hosts)->toBe(['beelink.coresmith.io'])
        ->and($tunnel->skipped)->toBe([['host' => 'pencil.example', 'reason' => "pencil.example already answers from somewhere Coresmith can't see in Cloudflare, so Coresmith left it alone."]]);
    Http::assertNotSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['name'] ?? null) === 'pencil.example');
});
