<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerTunnel;
use App\Services\Address\AddressChange;
use App\Services\Address\AddressGate;
use App\Services\Address\CloudflareConnection;
use App\Services\Address\PointAddress;
use App\Services\Tunnel\TunnelHosts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const ADDRESS_TUNNEL_API = 'https://api.cloudflare.test/client/v4';

beforeEach(function () {
    $this->addressState = sys_get_temp_dir() . '/coresmith-address-tunnel-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->addressState);
    config([
        'app.url' => 'https://192.168.0.248',
        'coresmith.address.site' => '/etc/caddy/coresmith/panel.caddy',
        'coresmith.address.state_path' => $this->addressState,
        'coresmith.address.cloudflare_api' => ADDRESS_TUNNEL_API,
    ]);
});

afterEach(fn() => File::deleteDirectory($this->addressState));

function addressTunnelOpen(array $hosts = ['shop.coresmith.io']): ServerTunnel
{
    $server = Server::factory()->create(['runs_coresmith' => true, 'ip_address' => '192.168.0.248', 'public_addresses' => ['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']]]);

    return ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN, 'account_id' => 'acc1', 'tunnel_id' => 'f00d', 'hosts' => $hosts]);
}

function addressTunnelCloudflare(string $account = 'acc1'): void
{
    Http::fake(function (Request $request) use ($account) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/zones?name=coresmith.io') => Http::response(['success' => true, 'errors' => [], 'result' => [['id' => 'z1', 'name' => 'coresmith.io', 'account' => ['id' => $account]]]]),
            str_contains($url, '/zones?name=') => Http::response(['success' => true, 'errors' => [], 'result' => []]),
            str_contains($url, '/dns_records?') => Http::response(['success' => true, 'errors' => [], 'result' => []]),
            default => Http::response(['success' => true, 'errors' => [], 'result' => ['id' => 'new']]),
        };
    });
}

test("pointing Coresmith's new domain while the tunnel carries this machine routes it through the tunnel", function () {
    CloudflareConnection::connect('cf-token');
    $tunnel = addressTunnelOpen();
    addressTunnelCloudflare();

    expect(app(PointAddress::class)->point('panel.coresmith.io'))->toBeNull()
        ->and($tunnel->fresh()->hosts)->toBe(['shop.coresmith.io', 'panel.coresmith.io']);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/cfd_tunnel/f00d/configurations')
        && collect($request->data()['config']['ingress'])->pluck('hostname')->filter()->unique()->values()->all() === ['shop.coresmith.io', 'panel.coresmith.io']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['type'] ?? null) === 'CNAME'
        && $request->data()['name'] === 'panel.coresmith.io' && $request->data()['content'] === 'f00d.cfargotunnel.com' && $request->data()['proxied'] === true);
    Http::assertNotSent(fn(Request $request): bool => in_array($request->data()['type'] ?? null, ['A', 'AAAA'], true));
});

test("a domain in another Cloudflare account than the tunnel isn't routed through it", function () {
    CloudflareConnection::connect('cf-token');
    $tunnel = addressTunnelOpen();
    addressTunnelCloudflare(account: 'acc2');

    expect(app(PointAddress::class)->point('panel.coresmith.io'))
        ->toBe("panel.coresmith.io is in a different Cloudflare account from this machine's tunnel, so Coresmith can't route it through the tunnel.")
        ->and($tunnel->fresh()->hosts)->toBe(['shop.coresmith.io']);

    Http::assertNotSent(fn(Request $request): bool => $request->method() !== 'GET');
});

test('a domain the tunnel carries passes the address check without being tested from outside', function () {
    addressTunnelOpen(['panel.coresmith.io']);
    Http::fake();

    $verdict = app(AddressGate::class)->check('panel.coresmith.io');

    expect($verdict->refusal)->toBeNull()
        ->and($verdict->notes)->toBe(["panel.coresmith.io reaches Coresmith through this machine's Cloudflare tunnel."]);
    Http::assertNothingSent();
});

test('after Coresmith moves, its old address stays on the tunnel so it can send people to the new one', function () {
    config(['app.url' => 'https://panel.coresmith.io']);
    $tunnel = addressTunnelOpen(['beelink.coresmith.io']);
    AddressChange::begin('beelink.coresmith.io', 'panel.coresmith.io');
    AddressChange::finish();

    expect(app(TunnelHosts::class)->for($tunnel->server))->toBe(['panel.coresmith.io' => true, 'beelink.coresmith.io' => false]);
});
