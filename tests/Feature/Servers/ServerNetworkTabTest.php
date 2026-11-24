<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Models\ServerTunnel;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

function networkTabUser(Team $team, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function networkTabServer(array $addresses): Server
{
    return Server::factory()->create([
        'name' => 'beelink',
        'status' => ServerStatus::Active,
        'ip_address' => '192.168.0.248',
        'public_addresses' => $addresses,
    ]);
}

test('the Network tab lists the addresses and says IPv4 visitors come in through Cloudflare while the tunnel carries them', function () {
    $server = networkTabServer(['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => ['2a06:5902:360e:2700::1'], 'private' => ['192.168.0.248/24', '10.205.0.2/24'], 'checked_at' => now()->subMinutes(5)->toIso8601String()]);
    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN, 'hosts' => ['beelink.coresmith.io'], 'checked_at' => now()]);

    $this->actingAs(networkTabUser($server->team))
        ->get(route('servers.network', $server))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->component('servers/Network')
            ->where('addresses.rows', [
                ['label' => 'IPv4', 'values' => ['151.226.169.196'], 'note' => "Visitors can't reach it from the internet, so they come in through Cloudflare.", 'tone' => 'success'],
                ['label' => 'IPv6', 'values' => ['2a06:5902:360e:2700::1'], 'note' => null, 'tone' => null],
                ['label' => 'Private', 'values' => ['10.205.0.2'], 'note' => null, 'tone' => null],
            ])
            ->where('addresses.checked', 'today ' . now()->subMinutes(5)->format('H:i'))
            ->where('tunnel.state', 'open')
            ->where('server.tabs.network', route('servers.network', $server)));
});

test('the Network tab names the server visitors come in through', function () {
    $server = networkTabServer(['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => []]);
    $door = Server::factory()->create(['team_id' => $server->team_id, 'name' => 'web-1']);
    ServerFrontDoor::query()->create(['server_id' => $server->id, 'door_server_id' => $door->id, 'state' => ServerFrontDoor::OPEN, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1', 'home_address' => '10.205.0.2']);

    $this->actingAs(networkTabUser($server->team))
        ->get(route('servers.network', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('addresses.rows.0.note', "Visitors can't reach it from the internet, so they come in through web-1.")
            ->where('addresses.rows.1', ['label' => 'IPv6', 'values' => [], 'note' => null, 'tone' => null])
            ->where('addresses.checked', null)
            ->where('frontDoor.state', 'open'));
});

test('a server whose IPv4 address answers shows only its addresses on the Network tab', function () {
    $server = networkTabServer(['ipv4' => '203.0.113.7', 'ipv4_open' => true, 'ipv6' => []]);

    $this->actingAs(networkTabUser($server->team))
        ->get(route('servers.network', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('addresses.rows.0', ['label' => 'IPv4', 'values' => ['203.0.113.7'], 'note' => 'Visitors reach it from the internet.', 'tone' => 'success'])
            ->where('tunnel', null)
            ->where('frontDoor', null));
});

test('a closed IPv4 address with no way in is a warning on the Network tab', function () {
    $server = networkTabServer(['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => []]);

    $this->actingAs(networkTabUser($server->team))
        ->get(route('servers.network', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('addresses.rows.0.note', "Visitors can't reach it from the internet.")
            ->where('addresses.rows.0.tone', 'warning'));
});

test("another team's server has no Network tab to open", function () {
    $server = networkTabServer(['ipv4' => '203.0.113.7', 'ipv4_open' => true]);

    $this->actingAs(networkTabUser(Team::factory()->create()))
        ->get(route('servers.network', $server))
        ->assertNotFound();
});

test('the Apps tab carries no IPv4 cards, only a notice when IPv4 visitors cannot get in', function (TeamRole $role, string $action) {
    $server = networkTabServer(['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => ['2a06:5902:360e:2700::1']]);

    $this->actingAs(networkTabUser($server->team, $role))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->missing('tunnel')
            ->missing('frontDoor')
            ->where('network', [
                'text' => "Visitors can't reach this server's IPv4 address from the internet, so people without IPv6 can't open the apps here.",
                'action' => $action,
                'href' => route('servers.network', $server),
            ]));
})->with([
    'an owner' => [TeamRole::Owner, 'Bring IPv4 visitors in'],
    'a member' => [TeamRole::Member, 'See the Network tab'],
]);

test('with no IPv6 address either, the notice says visitors cannot reach the server at all', function () {
    $server = networkTabServer(['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => []]);

    $this->actingAs(networkTabUser($server->team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('network.text', "Visitors can't reach this server from the internet. Its IPv4 address doesn't answer, and it has no IPv6 address."));
});

test('a way in that did not finish is a notice on the Apps tab', function (Closure $way, string $text) {
    $server = networkTabServer(['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => ['2a06:5902:360e:2700::1']]);
    $way($server);

    $this->actingAs(networkTabUser($server->team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('network.text', $text)->where('network.action', 'See what went wrong'));
})->with([
    'IPv4 through Cloudflare' => [fn(Server $server) => ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::FAILED, 'error' => 'Cloudflare refused the token.']), "IPv4 through Cloudflare didn't finish, so people without IPv6 can't open the apps here."],
    'IPv4 through another server' => [function (Server $server) {
        $door = Server::factory()->create(['team_id' => $server->team_id, 'name' => 'web-1']);
        ServerFrontDoor::query()->create(['server_id' => $server->id, 'door_server_id' => $door->id, 'state' => ServerFrontDoor::FAILED, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1', 'home_address' => '10.205.0.2']);
    }, "IPv4 through web-1 didn't finish, so people without IPv6 can't open the apps here."],
]);

test('the Apps tab says nothing about IPv4 while visitors get in', function (array $addresses, ?string $tunnel) {
    $server = networkTabServer($addresses);

    if ($tunnel !== null) {
        ServerTunnel::query()->create(['server_id' => $server->id, 'state' => $tunnel, 'checked_at' => now()]);
    }

    $this->actingAs(networkTabUser($server->team))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('network', null));
})->with([
    'IPv4 answers' => [['ipv4' => '203.0.113.7', 'ipv4_open' => true, 'ipv6' => []], null],
    'not checked yet' => [['ipv4' => null], null],
    'through Cloudflare' => [['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => []], ServerTunnel::OPEN],
    'while the tunnel opens' => [['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => []], ServerTunnel::OPENING],
]);

test('the Network tab sits between Databases and Hardening', function () {
    $server = networkTabServer(['ipv4' => '203.0.113.7', 'ipv4_open' => true]);

    $this->actingAs(networkTabUser($server->team))
        ->get(route('servers.network', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('server.tabs', [
                'apps' => route('servers.show', $server),
                'databases' => route('servers.databases.index', $server),
                'network' => route('servers.network', $server),
                'hardening' => route('servers.hardening', $server),
                'activity' => route('servers.activity', $server),
            ]));
});
