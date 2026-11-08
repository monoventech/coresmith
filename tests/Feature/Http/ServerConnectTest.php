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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

test('a password connects a pending server and Coresmith takes it from there', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team, ['ssh_user' => 'maya']);
    connectTransport((new FakeSSHTransport)->acceptPassword('maya', CONNECT_PASSWORD));

    $this->actingAs(connectMember($team))
        ->from(route('servers.show', $server))
        ->post(route('servers.connect', $server), ['ssh_user' => 'maya', 'password' => CONNECT_PASSWORD])
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('success', 'Connected. Coresmith added its key and is checking web-1 before it provisions.');

    expect($server->fresh()->ssh_user)->toBe('root');

    Queue::assertPushed(AuditServer::class);
});

test('a refused password comes back to the card with the reason, and the password is not kept anywhere', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team);
    connectTransport((new FakeSSHTransport)->acceptPassword('maya', 'the real one'));

    $this->actingAs(connectMember($team))
        ->from(route('servers.show', $server))
        ->post(route('servers.connect', $server), ['ssh_user' => 'maya', 'password' => CONNECT_PASSWORD])
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('error', "Coresmith couldn't connect to web-1.");

    expect($server->fresh()->last_connection_error)->toBe('The server refused the password for maya. Check it and try again.')
        ->and(json_encode(session()->all()))->not->toContain(CONNECT_PASSWORD)
        ->and(json_encode(DB::table('servers')->get()))->not->toContain(CONNECT_PASSWORD);
});

test('connecting with a password needs the user and the password, and the user must be a linux user', function (array $payload, string $field) {
    $team = Team::factory()->create();
    $server = connectPendingServer($team);
    $transport = connectTransport(new FakeSSHTransport);

    $this->actingAs(connectMember($team))
        ->post(route('servers.connect', $server), $payload)
        ->assertSessionHasErrors($field);

    expect($transport->connected())->toBeFalse();
})->with([
    'no password' => [['ssh_user' => 'maya'], 'password'],
    'no user' => [['password' => CONNECT_PASSWORD], 'ssh_user'],
    'not a linux user' => [['ssh_user' => 'maya; reboot', 'password' => CONNECT_PASSWORD], 'ssh_user'],
]);

test('a member cannot connect a server with a password, and Coresmith never dials it', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team);
    $transport = connectTransport((new FakeSSHTransport)->acceptPassword('maya', CONNECT_PASSWORD));

    $this->actingAs(connectMember($team, TeamRole::Member))
        ->post(route('servers.connect', $server), ['ssh_user' => 'maya', 'password' => CONNECT_PASSWORD])
        ->assertForbidden();

    expect($transport->passwordLogins())->toBe([]);
});

test('a server past connecting offers no password sign-in', function (ServerStatus $status) {
    $team = Team::factory()->create();
    $server = connectPendingServer($team, ['status' => $status]);
    $transport = connectTransport((new FakeSSHTransport)->acceptPassword('maya', CONNECT_PASSWORD));

    $this->actingAs(connectMember($team))
        ->post(route('servers.connect', $server), ['ssh_user' => 'maya', 'password' => CONNECT_PASSWORD])
        ->assertNotFound();

    expect($transport->passwordLogins())->toBe([]);
})->with([ServerStatus::Active, ServerStatus::Provisioning, ServerStatus::Failed, ServerStatus::Creating]);

test('password attempts on one server are limited, so its fail2ban never sees Coresmith guessing', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team);
    $transport = connectTransport(new FakeSSHTransport);
    $admin = connectMember($team);

    foreach (range(1, 4) as $attempt) {
        $this->actingAs($admin)->post(route('servers.connect', $server), ['ssh_user' => 'maya', 'password' => "guess {$attempt}"])->assertRedirect();
    }

    $this->actingAs($admin)
        ->withHeaders(['X-Inertia' => 'true'])
        ->from(route('servers.show', $server))
        ->post(route('servers.connect', $server), ['ssh_user' => 'maya', 'password' => 'guess 5'])
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('error', 'Too many attempts. Try again in 10 minutes.');

    expect($transport->passwordLogins())->toHaveCount(4);
});

test('password attempts across all of one person\'s servers are limited too, so Coresmith is no password-guessing proxy', function () {
    $team = Team::factory()->create();
    $transport = connectTransport(new FakeSSHTransport);
    $admin = connectMember($team);

    foreach (range(1, 8) as $index) {
        $server = connectPendingServer($team, ['name' => "web-{$index}"]);

        foreach (range(1, 4) as $attempt) {
            $this->actingAs($admin)->post(route('servers.connect', $server), ['ssh_user' => 'maya', 'password' => "guess {$attempt}"]);
        }
    }

    expect($transport->passwordLogins())->toHaveCount(30);
});

test('a server added with a password is connected on the spot, with the form values a browser sends', function () {
    $team = Team::factory()->create();
    connectTransport((new FakeSSHTransport)->acceptPassword('maya', CONNECT_PASSWORD));

    $response = $this->actingAs(connectMember($team))->post(route('servers.store'), [
        'team_id' => $team->id,
        'name' => 'web-1',
        'ip_address' => '203.0.113.10',
        'ssh_port' => '22',
        'ssh_user' => 'maya',
        'password' => CONNECT_PASSWORD,
    ]);

    $server = Server::firstWhere('name', 'web-1');

    $response->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('success', 'Server added and connected. Coresmith is checking web-1 before it provisions.');

    expect($server->ssh_user)->toBe('root')
        ->and($server->last_connected_at)->not->toBeNull()
        ->and(json_encode(DB::table('servers')->get()))->not->toContain(CONNECT_PASSWORD);
});

test('a server added with a password that does not work is still added, and says so', function () {
    $team = Team::factory()->create();
    connectTransport((new FakeSSHTransport)->refusePasswords());

    $this->actingAs(connectMember($team))->post(route('servers.store'), [
        'team_id' => $team->id,
        'name' => 'web-1',
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'maya',
        'password' => CONNECT_PASSWORD,
    ])->assertSessionHas('error', "Server added, but Coresmith couldn't connect to it.");

    $server = Server::firstWhere('name', 'web-1');

    expect($server->ssh_user)->toBe('maya')
        ->and($server->last_connection_error)->toBe("This server doesn't take passwords over SSH. Run the command below on it instead.");
});

test('a server added without a password says how to connect it, and Coresmith does not dial it yet', function () {
    $team = Team::factory()->create();
    $transport = connectTransport(new FakeSSHTransport);

    $this->actingAs(connectMember($team))->post(route('servers.store'), [
        'team_id' => $team->id,
        'name' => 'web-1',
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'password' => '',
    ])->assertSessionHas('success', 'Server added. Connect it with a password or one command.');

    expect($transport->calls())->toBe([]);
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
    $server = connectPendingServer($team, ['ssh_user' => 'deploy', 'last_connection_error' => 'The server refused the password for deploy. Check it and try again.']);

    $this->actingAs(connectMember($team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('server.provisioning.connect_command', AuthorizedKeyCommand::forPerson('deploy', $server->ssh_public_key))
            ->where('server.provisioning.connect_error', 'The server refused the password for deploy. Check it and try again.')
            ->where('server.provisioning.password_sign_in', true));
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

test('a server past connecting shows no password sign-in on its card', function () {
    $team = Team::factory()->create();
    $server = connectPendingServer($team, ['status' => ServerStatus::Failed]);

    $this->actingAs(connectMember($team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('server.provisioning.password_sign_in', false));
});
