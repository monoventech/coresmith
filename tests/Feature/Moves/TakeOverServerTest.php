<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\SyncApplicationRoute;
use App\Jobs\TakeOverServer;
use App\Models\Application;
use App\Models\Server;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\KeyPairGenerator;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function takeOverServerMoving(): Server
{
    $cloud = (new KeyPairGenerator)->generate('coresmith-cloud');

    return Server::factory()->create([
        'name' => 'web-01',
        'ip_address' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_private_key' => $cloud->privateKey,
        'ssh_public_key' => $cloud->publicKey,
        'host_key_fingerprint' => (string) HostKeyFingerprint::fromPublicKey(FakeSSHTransport::HOST_KEY),
        'status' => ServerStatus::Moving,
        'takeover' => ['state' => 'waiting', 'cloud_key' => $cloud->publicKey, 'attempts' => 0, 'error' => null],
    ]);
}

function takeOverServerRun(Server $server, FakeSSHTransport $transport): Server
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new TakeOverServer($server), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
        app(SSHConnectionManager::class)->disconnectAll();
    }

    return $server->fresh();
}

test('a moved server is taken over with this panel\'s own key and coresmith cloud\'s key is removed', function () {
    $server = takeOverServerMoving();
    $cloud = $server->ssh_public_key;
    $transport = new FakeSSHTransport;

    $moved = takeOverServerRun($server, $transport);
    $commands = implode("\n", $transport->commands());

    expect($moved->status)->toBe(ServerStatus::Active)
        ->and($moved->takeover)->toMatchArray(['state' => 'moved', 'error' => null, 'attempts' => 1])
        ->and($moved->ssh_public_key)->not->toBe($cloud)
        ->and($moved->ssh_private_key)->not->toBe($server->ssh_private_key)
        ->and(array_map(FakeSSHTransport::keyBlob(...), $transport->loginKeys()))->toBe([FakeSSHTransport::keyBlob($cloud), FakeSSHTransport::keyBlob($moved->ssh_public_key)])
        ->and($commands)->toContain(FakeSSHTransport::keyBlob($moved->ssh_public_key))
        ->and($commands)->toContain("blob='" . FakeSSHTransport::keyBlob($cloud) . "'")
        ->and($commands)->toContain(SecurityHardening::MANAGED_KEYS_BEGIN);
});

test('once a server is taken over, each app that moved onto it puts its own route in place of the one it had', function () {
    Queue::fake();
    $server = takeOverServerMoving();
    $moved = Application::factory()->create(['team_id' => $server->team_id, 'server_id' => $server->id, 'former_route_id' => 'app-01jbx4z3v8m2q7k6t5r9w0y1ce']);
    $stayed = Application::factory()->create(['team_id' => $server->team_id, 'server_id' => $server->id]);

    takeOverServerRun($server, new FakeSSHTransport);

    Queue::assertPushed(SyncApplicationRoute::class, fn(SyncApplicationRoute $job): bool => $job->application->is($moved));
    Queue::assertNotPushed(SyncApplicationRoute::class, fn(SyncApplicationRoute $job): bool => $job->application->is($stayed));
});

test('a takeover that stops leaves the routes as they were', function () {
    Queue::fake();
    $server = takeOverServerMoving();
    Application::factory()->create(['team_id' => $server->team_id, 'server_id' => $server->id, 'former_route_id' => 'app-01jbx4z3v8m2q7k6t5r9w0y1ce']);

    takeOverServerRun($server, (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('impostor')));

    Queue::assertNotPushed(SyncApplicationRoute::class);
});

test('a host key that is not the one coresmith cloud recorded stops the takeover before anything changes', function () {
    $server = takeOverServerMoving();
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('impostor'));

    $moved = takeOverServerRun($server, $transport);

    expect($moved->status)->toBe(ServerStatus::Moving)
        ->and($moved->takeover)->toMatchArray(['state' => 'failed', 'attempts' => 1])
        ->and($moved->takeover['error'])->toBe("web-01 showed a different host key than the Coresmith it came from recorded, so nothing was changed. Check it's the right server, accept or reject the new key, then try again.")
        ->and($moved->pending_host_key_fingerprint)->not->toBeNull()
        ->and($moved->ssh_public_key)->toBe($server->ssh_public_key)
        ->and($transport->commands())->toBe([]);
});

test('a new key the server will not accept is taken out again and coresmith cloud keeps its access', function () {
    $server = takeOverServerMoving();
    $transport = (new FakeSSHTransport)->onlyAccept($server->ssh_public_key);

    $moved = takeOverServerRun($server, $transport);
    $added = FakeSSHTransport::keyBlob($transport->loginKeys()[1]);

    expect($moved->status)->toBe(ServerStatus::Moving)
        ->and($moved->takeover['state'])->toBe('failed')
        ->and($moved->takeover['error'])->toBe("web-01 didn't accept Coresmith's new key, so the move put its keys back as they were. Try again.")
        ->and($moved->ssh_public_key)->toBe($server->ssh_public_key)
        ->and(implode("\n", $transport->commands()))->toContain("blob='{$added}'")
        ->and(implode("\n", $transport->commands()))->not->toContain("blob='" . FakeSSHTransport::keyBlob($server->ssh_public_key) . "'");
});

test('coresmith cloud\'s key no longer opening the server is said plainly', function () {
    $server = takeOverServerMoving();
    $transport = (new FakeSSHTransport)->failLogin();

    $moved = takeOverServerRun($server, $transport);

    expect($moved->takeover['error'])->toBe("web-01 no longer accepts the key of the Coresmith it came from, so the move can't take it over. Add the server here again instead.")
        ->and($transport->commands())->toBe([]);
});

test('a server that cannot be reached waits for another try', function () {
    $server = takeOverServerMoving();
    $transport = (new FakeSSHTransport)->refuseConnections();

    $moved = takeOverServerRun($server, $transport);

    expect($moved->status)->toBe(ServerStatus::Moving)
        ->and($moved->takeover)->toMatchArray(['state' => 'failed', 'attempts' => 1])
        ->and($moved->takeover['error'])->toBe("Coresmith couldn't reach web-01 at 203.0.113.10:22. Check it's on and reachable, then try again.");
});

test('a server whose ssh user cannot be used fails with a reason instead of hanging', function () {
    $server = takeOverServerMoving();
    $server->forceFill(['ssh_user' => 'root; reboot'])->save();
    $transport = new FakeSSHTransport;

    $moved = takeOverServerRun($server, $transport);

    expect($moved->takeover['state'])->toBe('failed')
        ->and($moved->takeover['error'])->toStartWith('Taking over web-01 stopped:')
        ->and($transport->connected())->toBeFalse();
});

test('a server that is not moving is left alone', function () {
    $server = takeOverServerMoving();
    $server->forceFill(['status' => ServerStatus::Active])->save();
    $transport = new FakeSSHTransport;

    takeOverServerRun($server, $transport);

    expect($transport->connected())->toBeFalse()->and($transport->loginAttempted())->toBeFalse();
});
