<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Server\FirewalldFirewallManager;
use App\Services\Server\UfwFirewallManager;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

test('an app without a domain is reached on a port outside every app block, one per block', function (int $base, int $port) {
    expect((new Application(['base_port' => $base]))->addressPort())->toBe($port);
})->with([
    'the first block' => [8000, 20000],
    'the tenth block' => [8090, 20009],
    'the last block' => [9990, 20199],
]);

test('an address without a domain is a plain HTTP server of its own that serves the app as its domain does', function () {
    $application = Application::factory()->make(['slug' => 'notes', 'type' => ApplicationType::Js, 'base_port' => 8090, 'domain' => 'notes.example.com']);
    $builder = new CaddyRouteBuilder;

    $server = $builder->addressServer($application, '100.101.102.103:20009', 'tailnet');

    expect($server['listen'])->toBe(['100.101.102.103:20009'])
        ->and($server['automatic_https'])->toBe(['disable' => true])
        ->and($server['routes'])->toEqual([['handle' => $builder->build($application)['handle'], 'terminal' => true]])
        ->and(CaddyRouteBuilder::addressServerId($application, 'tailnet'))->toBe('coresmith-app-notes-tailnet');
});

function appAddressesConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

    return (new SSHConnection($server, $transport))->connect();
}

test('an address is set on caddy as a server of its own and saved, and removed the same way', function () {
    $transport = new FakeSSHTransport;
    $connection = appAddressesConnection($transport);
    $application = Application::factory()->create(['slug' => 'notes', 'type' => ApplicationType::Js, 'base_port' => 8090, 'domain' => 'notes.example.com', 'server_id' => $connection->server()->id]);
    $client = app(CaddyApiClient::class);

    $client->putAddress($connection, $application, 'preview', ':20009');
    $client->removeAddress($connection, $application, 'preview');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("-X POST 'http://localhost:2019/config/apps/http/servers/coresmith-app-notes-preview'")
        ->and($commands)->toContain('"listen":[":20009"]')
        ->and($commands)->toContain("-X DELETE 'http://localhost:2019/config/apps/http/servers/coresmith-app-notes-preview'")
        ->and($commands)->toContain('caddy');
});

test('a firewall rule that was allowed can be taken away again', function (string $manager, string $command) {
    expect((new $manager)->remove(20009))->toBe($command);
})->with([
    'ufw' => [UfwFirewallManager::class, 'ufw delete allow 20009/tcp'],
    'firewalld' => [FirewalldFirewallManager::class, 'firewall-cmd --permanent --remove-port=20009/tcp && firewall-cmd --reload'],
]);
