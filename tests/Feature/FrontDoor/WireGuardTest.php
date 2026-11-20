<?php

declare(strict_types=1);

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\Server;
use App\Services\FrontDoor\WireGuard;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Tests\Support\FakeSSHTransport;

function wireGuardConnection(FakeSSHTransport $transport, array $attributes = []): SSHConnection
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return app(SSHConnectionManager::class)->for(Server::factory()->create(['name' => 'web-1', 'host_key_fingerprint' => null, 'os_name' => 'ubuntu', ...$attributes]));
}

test("the config names its address, port and peers, and takes its private key from the server's own key file", function () {
    $transport = new FakeSSHTransport;

    app(WireGuard::class)->configure(wireGuardConnection($transport), '10.205.2.1', 51871, [['key' => 'HOMEKEY=', 'allowed' => '10.205.2.2/32']]);

    expect($transport->uploads()['/etc/wireguard/coresmith-door.conf.next'])
        ->toContain("Address = 10.205.2.1/24\n")
        ->toContain("ListenPort = 51871\n")
        ->toContain("PostUp = wg set %i private-key /etc/wireguard/coresmith-door.key\n")
        ->toContain("[Peer]\nPublicKey = HOMEKEY=\nAllowedIPs = 10.205.2.2/32\n")
        ->not->toContain('PrivateKey')
        ->and(implode("\n", $transport->commands()))->toContain('wg-quick@coresmith-door');
});

test('a home peer dials the door and keeps the link open', function () {
    $transport = new FakeSSHTransport;

    app(WireGuard::class)->configure(wireGuardConnection($transport), '10.205.2.2', null, [['key' => 'DOORKEY=', 'allowed' => '10.205.2.1/32', 'endpoint' => '203.0.113.9:51871', 'keepalive' => 25]]);

    expect($transport->uploads()['/etc/wireguard/coresmith-door.conf.next'])
        ->toContain("Endpoint = 203.0.113.9:51871\n")
        ->toContain("PersistentKeepalive = 25\n")
        ->not->toContain('ListenPort');
});

test('a subnet or port another service uses is refused with what clashes', function () {
    $taken = (new FakeSSHTransport)
        ->respondTo('ip -4 -o addr', "2: eth0    inet 10.205.2.7/24 brd 10.205.2.255 scope global eth0\n")
        ->respondTo('ip -4 route', '')
        ->respondTo('ss -Hulpn', '');

    expect(app(WireGuard::class)->clash(wireGuardConnection($taken), '10.205.2.0/24', null))->toBe('10.205.2.0/24 is already in use on web-1.');

    $port = (new FakeSSHTransport)
        ->respondTo('ip -4 -o addr', '')
        ->respondTo('ip -4 route', '')
        ->respondTo('ss -Hulpn', "UNCONN 0      0            0.0.0.0:51871      0.0.0.0:*\n")
        ->respondTo('listen-port', '');

    expect(app(WireGuard::class)->clash(wireGuardConnection($port), '10.205.2.0/24', 51871))->toBe('UDP 51871 is already in use on web-1.');
});

test("the front door's own interface and port are never a clash", function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('ip -4 -o addr', "7: coresmith-door    inet 10.205.2.1/24 scope global coresmith-door\n")
        ->respondTo('ip -4 route', "10.205.2.0/24 dev coresmith-door proto kernel scope link src 10.205.2.1\n")
        ->respondTo('ss -Hulpn', "UNCONN 0      0            0.0.0.0:51871      0.0.0.0:*\n")
        ->respondTo('listen-port', "51871\n");

    expect(app(WireGuard::class)->clash(wireGuardConnection($transport), '10.205.2.0/24', 51871))->toBeNull();
});

test('an unsupported distribution is refused before anything is installed', function () {
    $transport = (new FakeSSHTransport)->respondTo('command -v wg', '');

    expect(fn() => app(WireGuard::class)->install(wireGuardConnection($transport, ['os_name' => 'arch'])))
        ->toThrow(UnsupportedOperatingSystemException::class)
        ->and(implode("\n", $transport->commands()))->not->toContain('wireguard-tools');
});

test('a server without WireGuard gets wireguard-tools from its own package manager', function () {
    $transport = (new FakeSSHTransport)->respondToSequence('command -v wg', [['', 0], ["/usr/bin/wg\n", 0]]);

    app(WireGuard::class)->install(wireGuardConnection($transport));

    expect(implode("\n", $transport->commands()))->toContain('wireguard-tools');
});

test("a server's key is made once, on the server, and only its public half is read", function () {
    $transport = (new FakeSSHTransport)->respondTo('wg pubkey', "PUBKEY=\n");

    expect(app(WireGuard::class)->publicKey(wireGuardConnection($transport)))->toBe('PUBKEY=')
        ->and(implode("\n", $transport->commands()))
        ->toContain('umask 077')
        ->toContain("[ -s '/etc/wireguard/coresmith-door.key' ] || wg genkey >");
});

test('a handshake age is read from wg show', function () {
    $this->travelTo(CarbonImmutable::createFromTimestamp(1795139030));
    $transport = (new FakeSSHTransport)->respondTo('latest-handshakes', "DOORKEY=\t1795139000\nOTHER=\t0\n");
    $connection = wireGuardConnection($transport);

    expect(app(WireGuard::class)->handshakeAge($connection, 'DOORKEY='))->toBe(30)
        ->and(app(WireGuard::class)->handshakeAge($connection, 'OTHER='))->toBeNull()
        ->and(app(WireGuard::class)->handshakeAge($connection, 'MISSING='))->toBeNull();
});

test('removing takes the interface down and deletes its config and key, even when they are already gone', function () {
    $transport = new FakeSSHTransport;

    app(WireGuard::class)->remove(wireGuardConnection($transport));

    expect(implode("\n", $transport->commands()))
        ->toContain('systemctl disable --now wg-quick@coresmith-door')
        ->toContain("rm -f '/etc/wireguard/coresmith-door.conf' '/etc/wireguard/coresmith-door.key'");
});
