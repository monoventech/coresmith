<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\AuditServer;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\AuthorizedKeyCommand;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeSSHTransport;

const CONNECT_PASSWORD = 'n0t-in-any-log';

function connectTransport(FakeSSHTransport $transport): FakeSSHTransport
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return $transport;
}

function connectPendingServer(Team $team, array $attributes = []): Server
{
    return Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Pending, 'host_key_fingerprint' => null, 'last_connected_at' => null, ...$attributes]);
}

function connectMember(Team $team, TeamRole $role = TeamRole::Admin): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

beforeEach(fn() => Queue::fake());

afterEach(fn() => SSHConnectionManager::createTransportUsing(null));

test('a server is added without Coresmith dialing it, and the next step is the command on its page', function () {
    $team = Team::factory()->create();
    $transport = connectTransport(new FakeSSHTransport);

    $this->actingAs(connectMember($team))->post(route('servers.store'), [
        'team_id' => $team->id,
        'name' => 'web-1',
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
    ])->assertSessionHas('success', 'Server added. Run the command below on it to connect.');

    expect($transport->calls())->toBe([]);
});

test('Coresmith never takes a server password: one sent anyway is dropped unused, and there is no password route to connect', function () {
    $team = Team::factory()->create();
    $transport = connectTransport(new FakeSSHTransport);

    $this->actingAs(connectMember($team))->post(route('servers.store'), [
        'team_id' => $team->id,
        'name' => 'web-1',
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'password' => CONNECT_PASSWORD,
    ])->assertSessionHas('success', 'Server added. Run the command below on it to connect.');

    expect($transport->calls())->toBe([])
        ->and(Route::has('servers.connect'))->toBeFalse()
        ->and(json_encode(Server::query()->where('name', 'web-1')->sole()->getAttributes()))->not->toContain(CONNECT_PASSWORD);
});

test('testing the connection answers right away and starts the check', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team);
    connectTransport(new FakeSSHTransport);

    $this->actingAs(connectMember($team))
        ->from(route('servers.show', $server))
        ->post(route('servers.test-connection', $server))
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('success', 'Connected. Coresmith is checking web-1 before it provisions.');

    expect($server->fresh()->last_connected_at)->not->toBeNull();

    Queue::assertPushed(AuditServer::class);
});

test('a failed connection test says so and keeps the reason on the card', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team);
    connectTransport((new FakeSSHTransport)->failLogin());

    $this->actingAs(connectMember($team))
        ->post(route('servers.test-connection', $server))
        ->assertSessionHas('error', "Coresmith couldn't connect to web-1.");

    expect($server->fresh()->last_connection_error)->toBe("The server refused Coresmith's key for root. Run the command below on the server, then test the connection again.");

    Queue::assertNotPushed(AuditServer::class);
});

test('a server whose health is already watched has no connection test, and Coresmith never dials it from here', function (ServerStatus $status) {
    $team = Team::factory()->create();
    $server = connectPendingServer($team, ['status' => $status, 'last_connection_error' => 'The server took the connection but stopped answering. It may be overloaded or rebooting.']);
    $transport = connectTransport(new FakeSSHTransport);

    $this->actingAs(connectMember($team))
        ->post(route('servers.test-connection', $server))
        ->assertNotFound();

    expect($transport->calls())->toBe([])
        ->and($server->fresh()->last_connection_error)->toBe('The server took the connection but stopped answering. It may be overloaded or rebooting.');
})->with([ServerStatus::Active, ServerStatus::Moving]);

test('a connected server that has finished provisioning is only told it is connected', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team, ['status' => ServerStatus::Failed]);
    connectTransport(new FakeSSHTransport);

    $this->actingAs(connectMember($team))
        ->post(route('servers.test-connection', $server))
        ->assertSessionHas('success', 'Connected.');
});

test('the connect card carries the command for the server user and the last failure', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team, ['ssh_user' => 'deploy', 'last_connection_error' => "The server refused Coresmith's key for deploy. Run the command below on the server, then test the connection again."]);

    $this->actingAs(connectMember($team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('server.provisioning.connect_command', AuthorizedKeyCommand::forPerson('deploy', $server->ssh_public_key))
            ->where('server.provisioning.connect_error', "The server refused Coresmith's key for deploy. Run the command below on the server, then test the connection again."));
});

test('the card knows the server is connected only while the last attempt worked', function (?string $connectedAt, ?string $error, bool $connected) {
    $team = Team::factory()->create();
    $server = connectPendingServer($team, ['last_connected_at' => $connectedAt, 'last_connection_error' => $error]);

    $this->actingAs(connectMember($team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('server.provisioning.connected', $connected));
})->with([
    'never connected' => [null, null, false],
    'connected' => ['2026-11-08 10:00:00', null, true],
    'connected once, refused since' => ['2026-11-08 10:00:00', "The server refused Coresmith's key for root. Run the command below on the server, then test the connection again.", false],
]);

test('no server card offers a password sign-in, whatever state the server is in', function (ServerStatus $status) {
    $team = Team::factory()->create();
    $server = connectPendingServer($team, ['status' => $status]);

    $this->actingAs(connectMember($team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->missing('server.provisioning.password_sign_in'));
})->with([ServerStatus::Pending, ServerStatus::Failed]);
