<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\SyncServerTunnel;
use App\Models\Server;
use App\Models\ServerTunnel;
use App\Models\Team;
use App\Models\User;
use App\Services\Address\CloudflareConnection;
use App\Services\Tunnel\CloudflareTunnels;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const TUNNEL_CONTROLLER_API = 'https://api.cloudflare.test/client/v4';

beforeEach(function () {
    config(['coresmith.address.cloudflare_api' => TUNNEL_CONTROLLER_API, 'app.url' => 'https://beelink.coresmith.io']);
    Queue::fake();
});

function tunnelControllerUser(Team $team, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function tunnelControllerServer(array $addresses = ['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => []]): Server
{
    return Server::factory()->create(['runs_coresmith' => true, 'public_addresses' => $addresses]);
}

function tunnelControllerCloudflare(int $tunnels = 200): void
{
    Http::fake(function (Request $request) use ($tunnels) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/user/tokens/verify') => Http::response(['success' => true, 'errors' => [], 'result' => ['status' => 'active']]),
            str_contains($url, '/zones?per_page=1') => Http::response(['success' => true, 'errors' => [], 'result' => [['id' => 'z1']]]),
            str_contains($url, '/zones?name=coresmith.io') => Http::response(['success' => true, 'errors' => [], 'result' => [['id' => 'z1', 'name' => 'coresmith.io', 'account' => ['id' => 'acc1']]]]),
            str_contains($url, '/zones?name=') => Http::response(['success' => true, 'errors' => [], 'result' => []]),
            str_contains($url, '/cfd_tunnel?') => $tunnels === 200
                ? Http::response(['success' => true, 'errors' => [], 'result' => []])
                : Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']], 'result' => null], $tunnels),
            default => Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 500),
        };
    });
}

test('an owner turns the tunnel on with a token, which is kept encrypted and checked first', function () {
    $server = tunnelControllerServer();
    tunnelControllerCloudflare();

    $this->actingAs(tunnelControllerUser($server->team))
        ->post(route('servers.tunnel.store', $server), ['token' => 'cf-token'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Setting up the tunnel. This takes a minute or two.');

    expect($server->tunnel()->first())->state->toBe(ServerTunnel::OPENING)->error->toBeNull()
        ->and(CloudflareConnection::token())->toBe('cf-token');
    Queue::assertPushed(SyncServerTunnel::class, fn(SyncServerTunnel $job): bool => $job->server->is($server));
});

test('a token that cannot manage tunnels is refused in the dialog, and nothing is kept', function () {
    $server = tunnelControllerServer();
    tunnelControllerCloudflare(tunnels: 403);

    $this->actingAs(tunnelControllerUser($server->team))
        ->post(route('servers.tunnel.store', $server), ['token' => 'cf-token'])
        ->assertSessionHasErrors(['token' => CloudflareTunnels::NO_TUNNEL_PERMISSION]);

    expect($server->tunnel()->exists())->toBeFalse()
        ->and(CloudflareConnection::token())->toBeNull();
    Queue::assertNothingPushed();
});

test('turning on without a token, when none is connected, asks for one', function () {
    $server = tunnelControllerServer();
    Http::fake();

    $this->actingAs(tunnelControllerUser($server->team))
        ->post(route('servers.tunnel.store', $server))
        ->assertSessionHasErrors(['token' => 'Paste a Cloudflare API token to turn this on.']);

    Queue::assertNothingPushed();
});

test('a connected token is used again, and a failed tunnel can be tried again', function () {
    CloudflareConnection::connect('cf-token');
    $server = tunnelControllerServer();
    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::FAILED, 'error' => 'Something went wrong.']);
    tunnelControllerCloudflare();

    $this->actingAs(tunnelControllerUser($server->team))
        ->post(route('servers.tunnel.store', $server))
        ->assertSessionHas('success', 'Setting up the tunnel. This takes a minute or two.');

    expect($server->tunnel()->first())->state->toBe(ServerTunnel::OPENING)->error->toBeNull();
    Queue::assertPushed(SyncServerTunnel::class);
});

test('people who do not manage Coresmith cannot turn it on or off', function (TeamRole $role) {
    $server = tunnelControllerServer();
    $user = tunnelControllerUser($server->team, $role);

    $this->actingAs($user)->post(route('servers.tunnel.store', $server), ['token' => 'cf-token'])->assertForbidden();
    $this->actingAs($user)->delete(route('servers.tunnel.destroy', $server))->assertForbidden();

    Queue::assertNothingPushed();
})->with([TeamRole::Admin, TeamRole::Member]);

test('Coresmith Cloud has no tunnel', function () {
    config(['coresmith.edition' => 'cloud']);
    $server = tunnelControllerServer();
    Http::fake();

    $response = $this->actingAs(tunnelControllerUser($server->team))->post(route('servers.tunnel.store', $server), ['token' => 'cf-token']);

    expect($response->isSuccessful() || $response->getSession()?->has('success'))->toBeFalse()
        ->and($server->tunnel()->exists())->toBeFalse()
        ->and(CloudflareConnection::token())->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('turning off marks the tunnel as closing and starts the work', function () {
    $server = tunnelControllerServer();
    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN, 'hosts' => ['beelink.coresmith.io']]);

    $this->actingAs(tunnelControllerUser($server->team))
        ->delete(route('servers.tunnel.destroy', $server))
        ->assertSessionHas('success', 'Turning off the tunnel.');

    expect($server->tunnel()->first()->state)->toBe(ServerTunnel::CLOSING);
    Queue::assertPushed(SyncServerTunnel::class);
});

test('turning off waits until the tunnel finishes setting up', function () {
    $server = tunnelControllerServer();
    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPENING]);

    $this->actingAs(tunnelControllerUser($server->team))
        ->delete(route('servers.tunnel.destroy', $server))
        ->assertSessionHas('error', 'Coresmith is still setting up the tunnel. Turn it off once it finishes.');

    expect($server->tunnel()->first()->state)->toBe(ServerTunnel::OPENING);
    Queue::assertNothingPushed();
});

test("the server page offers the tunnel when the internet can't reach its IPv4 address", function () {
    $server = tunnelControllerServer();

    $this->actingAs(tunnelControllerUser($server->team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn($page) => $page
            ->where('tunnel.state', 'off')
            ->where('tunnel.ipv4', '151.226.169.196')
            ->where('tunnel.cloudflare', false)
            ->where('tunnel.can_manage', true));
});

test('a server the internet reaches on IPv4 has no tunnel card, unless a tunnel is on', function () {
    $server = tunnelControllerServer(['ipv4' => '203.0.113.7', 'ipv4_open' => true, 'ipv6' => []]);
    $owner = tunnelControllerUser($server->team);

    $this->actingAs($owner)->get(route('servers.show', $server))->assertInertia(fn($page) => $page->where('tunnel', null));

    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN, 'hosts' => ['beelink.coresmith.io'], 'checked_at' => now()]);

    $this->actingAs($owner)->get(route('servers.show', $server))->assertInertia(fn($page) => $page
        ->where('tunnel.state', 'open')
        ->where('tunnel.hosts', ['beelink.coresmith.io'])
        ->where('tunnel.checked', 'now'));
});
