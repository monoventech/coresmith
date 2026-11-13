<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\TeamRole;
use App\Jobs\DetachApplication;
use App\Jobs\SyncApplicationAddresses;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Server\FirewalldFirewallManager;
use App\Services\Server\UfwFirewallManager;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Queue;
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

function appAddressesRun(Application $application, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new SyncApplicationAddresses($application))->handle(app(SSHConnectionManager::class), app(CaddyApiClient::class));
    } finally {
        app(SSHConnectionManager::class)->disconnectAll();
        SSHConnectionManager::createTransportUsing(null);
    }
}

function appAddressesApp(array $attributes = [], array $server = []): Application
{
    $host = Server::factory()->create(['os_name' => 'ubuntu', 'name' => 'web-01', ...$server]);

    return Application::factory()->for($host)->create(['slug' => 'notes', 'type' => ApplicationType::Js, 'base_port' => 8090, 'domain' => 'notes.example.com', ...$attributes]);
}

test('an app reachable on the tailnet listens on its server\'s Tailscale address only, which Coresmith reads but never changes', function () {
    $application = appAddressesApp(['tailnet' => true]);
    $transport = (new FakeSSHTransport)->respondTo('tailscale ip -4', "100.101.102.103\n")->respondTo("-X GET 'http://localhost:2019/config/apps/http/servers/coresmith-app-notes-preview'", "curl: (22) The requested URL returned error: 404\n", 22);

    appAddressesRun($application, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('"listen":["100.101.102.103:20009"]')
        ->and($commands)->not->toContain('ufw')
        ->and($commands)->not->toContain('tailscale up')
        ->and($application->fresh()->tailnet_ip)->toBe('100.101.102.103')
        ->and($application->fresh()->address_problem)->toBeNull();
});

test('without Tailscale running there is no tailnet address, and the app page says why', function () {
    $application = appAddressesApp(['tailnet' => true]);
    $transport = (new FakeSSHTransport)->respondTo('tailscale ip -4', '');

    appAddressesRun($application, $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('-X POST')
        ->and($application->fresh()->tailnet_ip)->toBeNull()
        ->and($application->fresh()->address_problem)->toBe("Tailscale isn't running on web-01, so there is no tailnet address. Start Tailscale there, then turn this on again.");
});

test('a preview opens its port on a server whose firewall Coresmith manages, and closes it again when it goes off', function () {
    $application = appAddressesApp(['preview' => true]);
    $on = new FakeSSHTransport;
    appAddressesRun($application, $on);

    $application->forceFill(['preview' => false])->save();
    $off = new FakeSSHTransport;
    appAddressesRun($application, $off);

    expect(implode("\n", $on->commands()))->toContain('"listen":[":20009"]')->toContain('ufw allow 20009/tcp')
        ->and(implode("\n", $off->commands()))->toContain('-X DELETE')->toContain('ufw delete allow 20009/tcp');
});

test('on the machine Coresmith runs on, a preview never touches the firewall, which is the owner\'s', function () {
    $application = appAddressesApp(['preview' => true], ['runs_coresmith' => true]);
    $transport = new FakeSSHTransport;

    appAddressesRun($application, $transport);

    expect(implode("\n", $transport->commands()))->toContain('"listen":[":20009"]')->not->toContain('ufw');
});

test('turning on an address saves it and applies it on the server, for a member allowed to change the app', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);
    $application = appAddressesApp(['team_id' => $team->id], ['team_id' => $team->id]);

    $this->actingAs($owner)->from(route('servers.applications.show', [$application->server, $application]))
        ->put(route('servers.applications.addresses.update', [$application->server, $application]), ['tailnet' => true, 'preview' => false])
        ->assertSessionHas('success', 'Saved.');

    expect($application->fresh()->tailnet)->toBeTrue()->and($application->fresh()->preview)->toBeFalse();
    Queue::assertPushed(SyncApplicationAddresses::class);
});

test('a developer cannot change the addresses of an app it did not add', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->members()->attach($developer, ['role' => TeamRole::Developer->value, 'sees_everything' => false]);
    $application = appAddressesApp(['team_id' => $team->id], ['team_id' => $team->id]);
    app(TeamAccess::class)->grant($team, $developer, $application);

    $this->actingAs($developer)->put(route('servers.applications.addresses.update', [$application->server, $application]), ['tailnet' => true, 'preview' => true])->assertForbidden();

    Queue::assertNothingPushed();
});

test('an app that is removed takes its addresses with it', function () {
    $application = appAddressesApp(['tailnet' => true, 'preview' => true]);
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new DetachApplication($application), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
        app(SSHConnectionManager::class)->disconnectAll();
    }

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("-X DELETE 'http://localhost:2019/config/apps/http/servers/coresmith-app-notes-tailnet'")
        ->and($commands)->toContain("-X DELETE 'http://localhost:2019/config/apps/http/servers/coresmith-app-notes-preview'")
        ->and($commands)->toContain('ufw delete allow 20009/tcp');
});
