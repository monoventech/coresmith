<?php

declare(strict_types=1);

use App\Exceptions\ProvisioningVerificationException;
use App\Models\Server;
use App\Services\Server\ProvisioningVerifier;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function provisioningVerifierRun(FakeSSHTransport $transport, array $meta = ['caddy_admin' => 'socket'], string $user = 'root'): void
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_user' => $user, 'meta' => $meta]);

    (new ProvisioningVerifier)->verify((new SSHConnection($server, $transport))->connect());
}

function provisioningVerifierTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)
        ->respondTo('echo reachable || echo blocked', "blocked\n")
        ->respondTo('echo open || echo closed', "closed\n");
}

test('a server whose admin api answers on its socket and nowhere else passes', function () {
    $transport = provisioningVerifierTransport();

    provisioningVerifierRun($transport, user: 'deploy');

    expect($transport->commands())->toContain('sudo bash -c ' . escapeshellarg("curl -fsS --max-time 10 --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/' >/dev/null"))
        ->toContain('curl -s --max-time 5 -o /dev/null http://127.0.0.1:2019/config/ && echo open || echo closed');
});

test('a server whose admin api still answers on its old port fails, since any local program could reach it', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('echo reachable || echo blocked', "blocked\n")
        ->respondTo('echo open || echo closed', "open\n");

    expect(fn() => provisioningVerifierRun($transport))
        ->toThrow(ProvisioningVerificationException::class, 'Caddy still answers on 127.0.0.1:2019, where any program on the server can reach it. Provisioning has been halted.');
});

test('a server not yet moved off tcp is only checked for being unreachable from outside', function () {
    $transport = provisioningVerifierTransport();

    provisioningVerifierRun($transport, []);

    expect(implode("\n", $transport->commands()))->toContain("'http://localhost:2019/config/'")
        ->not->toContain('echo open || echo closed');
});
