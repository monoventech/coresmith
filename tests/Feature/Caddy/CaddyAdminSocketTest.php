<?php

declare(strict_types=1);

use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\Caddy\CaddyAdminSocket;
use App\Services\Caddy\CaddyInstaller;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

const ADMIN_SOCKET_PROBE = "curl -fsS --max-time 10 --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/' >/dev/null";

function adminSocketServer(array $meta = []): Server
{
    return Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => $meta]);
}

function adminSocketMove(Server $server, FakeSSHTransport $transport): void
{
    app(CaddyAdminSocket::class)->move((new SSHConnection($server, $transport))->connect());
}

function adminSocketRunning(): string
{
    return json_encode([
        'admin' => ['listen' => 'localhost:2019', 'origins' => ['localhost:2019'], 'enforce_origin' => true],
        'apps' => ['http' => ['servers' => ['coresmith' => ['listen' => [':443'], 'routes' => [['@id' => 'app-1']], 'logs' => new stdClass]]]],
    ]);
}

beforeEach(function () {
    Sleep::fake();
});

test('a server on tcp loads its running config with the socket listener through the old endpoint, then saves it', function () {
    $server = adminSocketServer(['cpu_cores' => 2]);
    $transport = (new FakeSSHTransport)
        ->respondToSequence(ADMIN_SOCKET_PROBE, [['curl: (7) Failed to connect', 7], ['', 0]])
        ->respondTo("-X GET 'http://localhost:2019/config/'", adminSocketRunning());

    adminSocketMove($server, $transport);

    $commands = $transport->commands();
    $loaded = json_decode($transport->uploads()['/etc/caddy/caddy-config.socket.json'] ?? '', false);

    expect($transport->uploads()[CaddyInstaller::ADMIN_TMPFILES] ?? null)->toBe("d /run/caddy-admin 0700 caddy caddy -\n")
        ->and($loaded->admin)->toEqual((object) ['listen' => 'unix//run/caddy-admin/admin.sock'])
        ->and($loaded->apps->http->servers->coresmith->routes)->toEqual([(object) ['@id' => 'app-1']])
        ->and($loaded->apps->http->servers->coresmith->logs)->toEqual(new stdClass)
        ->and($commands)->toContain("curl -fsS --max-time 30 -X POST 'http://localhost:2019/load' -H 'Origin: http://localhost:2019' -H 'Content-Type: application/json' --data-binary @/etc/caddy/caddy-config.socket.json")
        ->and(array_search('rm -f /etc/caddy/caddy-config.socket.json', $commands, true))->toBeGreaterThan(array_search("curl -fsS --max-time 30 -X POST 'http://localhost:2019/load' -H 'Origin: http://localhost:2019' -H 'Content-Type: application/json' --data-binary @/etc/caddy/caddy-config.socket.json", $commands, true))
        ->and(end($commands))->toBe("sh -c 'curl -fsS --max-time 30 -X GET --unix-socket /run/caddy-admin/admin.sock '\\''http://localhost/config/'\\'' > /etc/caddy/caddy-config.staged.json && mv -f /etc/caddy/caddy-config.staged.json /etc/caddy/caddy-config.json'")
        ->and($server->fresh()->meta)->toBeInAnyKeyOrder(['cpu_cores' => 2, 'caddy_admin' => 'socket']);
});

test('a server already answering on the socket only saves its config and is recorded as moved', function () {
    $server = adminSocketServer(['caddy_admin_error' => 'an old failure']);
    $transport = new FakeSSHTransport;

    adminSocketMove($server, $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('/load')
        ->and($server->fresh()->meta)->toBe(['caddy_admin' => 'socket']);
});

test('a socket that never answers puts caddy back on its old settings, and the server stays on tcp', function () {
    $server = adminSocketServer();
    $transport = (new FakeSSHTransport)
        ->respondTo(ADMIN_SOCKET_PROBE, 'curl: (7) Failed to connect', 7)
        ->respondTo("-X GET 'http://localhost:2019/config/'", adminSocketRunning());

    expect(fn() => adminSocketMove($server, $transport))
        ->toThrow(RuntimeException::class, 'The web server took the new settings but stopped answering, so Coresmith restarted it on its saved ones.');

    expect($transport->commands())->toContain('systemctl restart caddy')
        ->and(implode("\n", $transport->commands()))->not->toContain('mv -f /etc/caddy/caddy-config.staged.json')
        ->and($server->fresh()->caddyAdminOnSocket())->toBeFalse();
});

test('caddy refusing the new settings leaves everything as it was', function () {
    $server = adminSocketServer();
    $transport = (new FakeSSHTransport)
        ->respondTo(ADMIN_SOCKET_PROBE, 'curl: (7) Failed to connect', 7)
        ->respondTo("-X GET 'http://localhost:2019/config/'", adminSocketRunning())
        ->respondTo("'http://localhost:2019/load'", 'curl: (22) The requested URL returned error: 400', 22);

    expect(fn() => adminSocketMove($server, $transport))->toThrow(SSHCommandFailedException::class);

    expect($transport->commands())->not->toContain('systemctl restart caddy')
        ->toContain('rm -f /etc/caddy/caddy-config.socket.json')
        ->and($server->fresh()->caddyAdminOnSocket())->toBeFalse();
});

test('a config that cannot be saved after the switch puts caddy back on its saved settings', function () {
    $server = adminSocketServer();
    $transport = (new FakeSSHTransport)
        ->respondToSequence(ADMIN_SOCKET_PROBE, [['curl: (7) Failed to connect', 7], ['', 0]])
        ->respondTo("-X GET 'http://localhost:2019/config/'", adminSocketRunning())
        ->respondTo('mv -f /etc/caddy/caddy-config.staged.json', 'No space left on device', 1);

    expect(fn() => adminSocketMove($server, $transport))->toThrow(SSHCommandFailedException::class);

    expect($transport->commands())->toContain('systemctl restart caddy')
        ->and($server->fresh()->caddyAdminOnSocket())->toBeFalse();
});
