<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\ProvisionServer;
use App\Jobs\TestSSHConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function teamMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('a guest is sent to login', function () {
    $this->get(route('servers.index'))->assertRedirect(route('login'));
});

test('the list only shows servers from the users own teams', function () {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();
    $user = teamMember($mine, TeamRole::Member);

    $visible = Server::factory()->for($mine)->create(['name' => 'mine']);
    Server::factory()->for($theirs)->create(['name' => 'theirs']);

    $this->actingAs($user)
        ->get(route('servers.index'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->component('Dashboard')
            ->has('servers', 1)
            ->where('servers.0.id', $visible->id));
});

test('another teams server cannot be viewed', function () {
    $user = teamMember(Team::factory()->create(), TeamRole::Owner);
    $server = Server::factory()->for(Team::factory()->create())->create();

    $this->actingAs($user)->get(route('servers.show', $server))->assertForbidden();
});

test('a member of the owning team can view the server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page->component('servers/Show')->where('server.id', $server->id));
});

test('the private key is never sent to the browser', function (string $route) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['ssh_private_key' => 'PRIVATE-KEY-MATERIAL']);

    $response = $this->actingAs(teamMember($team, TeamRole::Admin))->get(route($route, $server))->assertOk();

    expect($response->getContent())->not->toContain('PRIVATE-KEY-MATERIAL')
        ->and($response->getContent())->not->toContain('ssh_private_key');
})->with(['servers.show', 'servers.databases.index', 'servers.hardening', 'servers.activity']);

test('a member cannot create a server', function () {
    $team = Team::factory()->create();

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->post(route('servers.store'), [
            'team_id' => $team->id,
            'name' => 'web-1',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ])
        ->assertForbidden();

    expect(Server::count())->toBe(0);
});

test('an admin can create a server and it gets a generated key pair', function () {
    $team = Team::factory()->create();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.store'), [
            'team_id' => $team->id,
            'name' => 'web-1',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ])
        ->assertRedirect();

    $server = Server::firstWhere('name', 'web-1');

    expect($server)->not->toBeNull()
        ->and($server->ssh_public_key)->toStartWith('ssh-ed25519 ')
        ->and($server->ssh_private_key)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($server->fresh()->status)->toBe(ServerStatus::Pending);
});

test('a server cannot be created inside a team the user does not belong to', function () {
    $outsider = teamMember(Team::factory()->create(), TeamRole::Owner);
    $victim = Team::factory()->create();

    $this->actingAs($outsider)
        ->post(route('servers.store'), [
            'team_id' => $victim->id,
            'name' => 'intruder',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ])
        ->assertForbidden();

    expect(Server::count())->toBe(0);
});

test('server input is validated', function (array $payload, string $field) {
    $team = Team::factory()->create();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.store'), array_merge([
            'team_id' => $team->id,
            'name' => 'web-1',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ], $payload))
        ->assertSessionHasErrors($field);
})->with([
    [['ip_address' => 'not-an-ip'], 'ip_address'],
    [['ip_address' => '203.0.113.10; rm -rf /'], 'ip_address'],
    [['ssh_port' => 0], 'ssh_port'],
    [['ssh_port' => 70000], 'ssh_port'],
    [['ssh_user' => 'root; rm -rf /'], 'ssh_user'],
    [['ssh_user' => 'Root'], 'ssh_user'],
    [['name' => ''], 'name'],
]);

test('a member cannot delete a server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->delete(route('servers.destroy', $server))
        ->assertForbidden();

    expect(Server::find($server->id))->not->toBeNull();
});

test('an admin can delete a server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->delete(route('servers.destroy', $server))
        ->assertRedirect(route('dashboard'));

    expect(Server::find($server->id))->toBeNull()
        ->and(Server::withTrashed()->find($server->id))->not->toBeNull();
});

test('a member cannot update a server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->put(route('servers.update', $server), ['name' => 'renamed'])
        ->assertForbidden();

    expect($server->fresh()->name)->toBe('web-1');
});

test('an admin can rename a server without resending its other settings', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'ssh_port' => 2222]);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->put(route('servers.update', $server), ['name' => 'web-renamed'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($server->fresh()->name)->toBe('web-renamed')
        ->and($server->fresh()->ssh_port)->toBe(2222);
});

test('provisioning another teams server is refused and queues nothing', function () {
    Queue::fake();
    $outsider = teamMember(Team::factory()->create(), TeamRole::Owner);
    $server = Server::factory()->for(Team::factory()->create())->create();

    $this->actingAs($outsider)->post(route('servers.provision', $server))->assertForbidden();

    Queue::assertNothingPushed();
});

test('an admin can start provisioning', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.provision', $server))
        ->assertRedirect();

    Queue::assertPushed(ProvisionServer::class);
});

test('a member cannot trigger a connection test', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->post(route('servers.test-connection', $server))
        ->assertForbidden();

    Queue::assertNotPushed(TestSSHConnection::class);
});

test('every server route requires authentication', function (string $method, string $route) {
    $server = Server::factory()->create();
    $url = in_array($route, ['servers.index', 'servers.create'], true)
        ? route($route)
        : route($route, $server);

    $this->{$method}($url)->assertRedirect(route('login'));
})->with([
    ['get', 'servers.index'],
    ['get', 'servers.create'],
    ['get', 'servers.show'],
    ['put', 'servers.update'],
    ['delete', 'servers.destroy'],
    ['post', 'servers.provision'],
    ['post', 'servers.test-connection'],
    ['post', 'servers.reboot'],
    ['post', 'servers.prune-releases'],
]);
