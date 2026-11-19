<?php

declare(strict_types=1);

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Jobs\SyncServerTunnel;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerTunnel;
use App\Services\Network\ServerAddresses;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Tunnel\Cloudflared;
use App\Services\Tunnel\TunnelHosts;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function tunnelPartsServer(array $attributes = []): Server
{
    return Server::factory()->create(['host_key_fingerprint' => null, ...$attributes]);
}

test("the tunnel carries Coresmith's own address and every public domain of the apps on the server", function () {
    config(['app.url' => 'https://beelink.coresmith.io']);
    $server = tunnelPartsServer(['runs_coresmith' => true]);
    Application::factory()->for($server->team)->for($server)->create(['domain' => 'Pencil.Example', 'additional_domains' => ['docs.pencil.example', 'preview.internal']]);
    Application::factory()->for($server->team)->for($server)->create(['domain' => null]);

    expect(app(TunnelHosts::class)->for($server))->toBe([
        'beelink.coresmith.io' => true,
        'pencil.example' => true,
        'docs.pencil.example' => true,
        'www.pencil.example' => false,
    ]);
});

test("a server that doesn't run Coresmith only carries its apps' domains", function () {
    config(['app.url' => 'https://beelink.coresmith.io']);
    $server = tunnelPartsServer(['runs_coresmith' => false]);
    Application::factory()->for($server->team)->for($server)->create(['domain' => 'shop.example.com', 'additional_domains' => []]);

    expect(app(TunnelHosts::class)->for($server))->toBe(['shop.example.com' => true]);
});

test("names that aren't public domains never go through the tunnel", function (string $host) {
    expect(TunnelHosts::routable($host))->toBeFalse();
})->with(['*.example.com', 'app.internal', 'nas.local', '192.0.2.7', 'beelink.tail1234.ts.net', 'localhost']);

test('with the tunnel on, Add an app and Add a domain show the CNAME to the tunnel', function () {
    $server = tunnelPartsServer(['public_addresses' => ['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']]]);

    expect(ServerAddresses::targetsFor($server))->toBe([
        ['type' => 'AAAA', 'value' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', 'closed' => false],
        ['type' => 'A', 'value' => '151.226.169.196', 'closed' => true],
    ]);

    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN, 'tunnel_id' => 'f00d']);

    expect(ServerAddresses::targetsFor($server))->toBe([
        ['type' => 'CNAME', 'value' => 'f00d.cfargotunnel.com', 'closed' => false, 'note' => ServerAddresses::TUNNEL_NOTE],
    ]);
});

test('a domain added, changed or removed on a server with the tunnel on updates the tunnel', function () {
    Queue::fake();
    $server = tunnelPartsServer();
    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN, 'tunnel_id' => 'f00d']);

    $application = Application::factory()->for($server->team)->for($server)->create(['domain' => 'pencil.example']);
    Queue::assertPushed(SyncServerTunnel::class, 1);

    $application->update(['additional_domains' => ['docs.pencil.example']]);
    Queue::assertPushed(SyncServerTunnel::class, 2);

    $application->update(['name' => 'pencil-2']);
    Queue::assertPushed(SyncServerTunnel::class, 2);

    $application->delete();
    Queue::assertPushed(SyncServerTunnel::class, 3);
});

test("apps on a server without the tunnel on don't touch it", function () {
    Queue::fake();
    $server = tunnelPartsServer();

    Application::factory()->for($server->team)->for($server)->create(['domain' => 'pencil.example']);

    Queue::assertNotPushed(SyncServerTunnel::class);
});

test('the connector runs as its own locked-down service and reads its token from a root-only file', function () {
    expect(Cloudflared::unit('/usr/bin/cloudflared'))
        ->toContain("EnvironmentFile=/etc/coresmith-tunnel/token.env\n")
        ->toContain("ExecStart=/usr/bin/cloudflared --no-autoupdate tunnel run\n")
        ->toContain("DynamicUser=yes\n")
        ->toContain("ProtectSystem=strict\n")
        ->and(Cloudflared::swap())
        ->toContain("chmod 600 '/etc/coresmith-tunnel/token.env.next' '/etc/coresmith-tunnel/unit.next'")
        ->toContain("if cmp -s '/etc/coresmith-tunnel/token.env.next' '/etc/coresmith-tunnel/token.env'")
        ->toContain('systemctl restart --no-block coresmith-tunnel');
});

test("a server without the connector gets Cloudflare's package first", function () {
    $server = tunnelPartsServer(['os_name' => 'ubuntu']);
    $transport = (new FakeSSHTransport)->respondToSequence('command -v cloudflared', [['', 0], ["/usr/bin/cloudflared\n", 0]]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    app(Cloudflared::class)->start(app(SSHConnectionManager::class)->for($server), 'run-token');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('https://pkg.cloudflare.com/cloudflare-main.gpg')
        ->toContain('https://pkg.cloudflare.com/cloudflared any main')
        ->toContain('cloudflared')
        ->and($transport->uploads()['/etc/coresmith-tunnel/token.env.next'])->toBe("TUNNEL_TOKEN=run-token\n");
});

test("an operating system Cloudflare doesn't package for is refused", function () {
    $server = tunnelPartsServer(['os_name' => 'arch']);
    $transport = (new FakeSSHTransport)->respondTo('command -v cloudflared', '');
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    expect(fn() => app(Cloudflared::class)->start(app(SSHConnectionManager::class)->for($server), 'run-token'))
        ->toThrow(UnsupportedOperatingSystemException::class);
});
