<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\SyncServerFrontDoor;
use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Models\ServerTunnel;
use App\Models\Team;
use App\Models\User;
use App\Services\Address\CloudflareConnection;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(fn() => Queue::fake());

function frontDoorControllerUser(Team $team, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => true]);

    return $user->fresh();
}

function frontDoorControllerServers(): array
{
    $home = Server::factory()->create(['name' => 'beelink', 'status' => ServerStatus::Active, 'public_addresses' => ['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => []]]);
    $door = Server::factory()->create(['team_id' => $home->team_id, 'name' => 'web-1', 'status' => ServerStatus::Active, 'public_addresses' => ['ipv4' => '203.0.113.9', 'ipv4_open' => true, 'ipv6' => []]]);

    return [$home, $door];
}

test("an owner turns the front door on through another of the team's servers", function () {
    [$home, $door] = frontDoorControllerServers();

    $this->actingAs(frontDoorControllerUser($home->team))
        ->post(route('servers.front-door.store', $home), ['door' => $door->id])
        ->assertRedirect()
        ->assertSessionHas('success', 'Linking beelink and web-1. This takes a minute or two.');

    expect(ServerFrontDoor::query()->first())
        ->state->toBe(ServerFrontDoor::OPENING)
        ->door_server_id->toBe($door->id)
        ->subnet->toBe('10.205.0.0/24')
        ->home_address->toBe('10.205.0.2');
    Queue::assertPushed(SyncServerFrontDoor::class, fn(SyncServerFrontDoor $job): bool => $job->server->is($home));
});

test('a door with no room left is refused next to the server picker, and nothing starts', function () {
    [$home, $door] = frontDoorControllerServers();

    for ($host = 2; $host <= 254; $host++) {
        ServerFrontDoor::query()->create(['server_id' => Server::factory()->create(['team_id' => $door->team_id])->id, 'door_server_id' => $door->id, 'state' => ServerFrontDoor::OPEN, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1', 'home_address' => "10.205.0.{$host}"]);
    }

    $this->actingAs(frontDoorControllerUser($home->team))
        ->post(route('servers.front-door.store', $home), ['door' => $door->id])
        ->assertSessionHasErrors(['door' => 'web-1 already takes visitors in for 253 servers, the most one server can. Pick another server.']);

    expect($home->frontDoor()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('only owners can turn it on or off', function (TeamRole $role) {
    [$home, $door] = frontDoorControllerServers();
    $user = frontDoorControllerUser($home->team, $role);
    app(TeamAccess::class)->grant($home->team, $user, $home);

    $this->actingAs($user)->post(route('servers.front-door.store', $home), ['door' => $door->id])->assertForbidden();
    $this->actingAs($user)->delete(route('servers.front-door.destroy', $home))->assertForbidden();

    expect(ServerFrontDoor::query()->count())->toBe(0);
})->with([TeamRole::Admin, TeamRole::Developer]);

test('the door has to be another active server in the team whose IPv4 address answers', function (Closure $door) {
    [$home] = frontDoorControllerServers();

    $this->actingAs(frontDoorControllerUser($home->team))
        ->post(route('servers.front-door.store', $home), ['door' => $door($home)->id])
        ->assertSessionHasErrors(['door' => 'Pick one of your servers whose IPv4 address the internet can reach.']);

    expect(ServerFrontDoor::query()->count())->toBe(0);
})->with([
    'the same server' => [fn(Server $home): Server => $home],
    'another team' => [fn(Server $home): Server => Server::factory()->create(['status' => ServerStatus::Active, 'public_addresses' => ['ipv4' => '203.0.113.10', 'ipv4_open' => true]])],
    'IPv4 closed' => [fn(Server $home): Server => Server::factory()->create(['team_id' => $home->team_id, 'status' => ServerStatus::Active, 'public_addresses' => ['ipv4' => '203.0.113.11', 'ipv4_open' => false]])],
    'not ready yet' => [fn(Server $home): Server => Server::factory()->create(['team_id' => $home->team_id, 'status' => ServerStatus::Pending, 'public_addresses' => ['ipv4' => '203.0.113.12', 'ipv4_open' => true]])],
]);

test('the tunnel and the front door exclude each other', function () {
    [$home, $door] = frontDoorControllerServers();
    $owner = frontDoorControllerUser($home->team);
    $tunnel = ServerTunnel::query()->create(['server_id' => $home->id, 'state' => ServerTunnel::OPEN]);

    $this->actingAs($owner)
        ->post(route('servers.front-door.store', $home), ['door' => $door->id])
        ->assertSessionHas('error', 'IPv4 through Cloudflare is on. Turn it off first.');

    $tunnel->delete();
    ServerFrontDoor::query()->create(['server_id' => $home->id, 'door_server_id' => $door->id, 'state' => ServerFrontDoor::OPEN, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1', 'home_address' => '10.205.0.2']);

    $this->actingAs($owner)
        ->post(route('servers.tunnel.store', $home), ['token' => 'cf-token'])
        ->assertSessionHas('error', 'IPv4 through another server is on. Turn it off first.');

    expect(ServerTunnel::query()->count())->toBe(0);
});

test('turning it off marks it closing and starts the job', function () {
    [$home, $door] = frontDoorControllerServers();
    $frontDoor = ServerFrontDoor::query()->create(['server_id' => $home->id, 'door_server_id' => $door->id, 'state' => ServerFrontDoor::OPEN, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1', 'home_address' => '10.205.0.2']);

    $this->actingAs(frontDoorControllerUser($home->team))
        ->delete(route('servers.front-door.destroy', $home))
        ->assertSessionHas('success', 'Turning off IPv4 through web-1.');

    expect($frontDoor->fresh()->state)->toBe(ServerFrontDoor::CLOSING);
    Queue::assertPushed(SyncServerFrontDoor::class);
});

test('a door that takes visitors in for another server cannot be removed', function () {
    [$home, $door] = frontDoorControllerServers();
    ServerFrontDoor::query()->create(['server_id' => $home->id, 'door_server_id' => $door->id, 'state' => ServerFrontDoor::OPEN, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1', 'home_address' => '10.205.0.2']);

    $this->actingAs(frontDoorControllerUser($home->team))
        ->delete(route('servers.destroy', $door))
        ->assertSessionHas('error', "beelink takes visitors through web-1. Turn that off on beelink's Network tab first.");

    expect($door->fresh())->not->toBeNull();
});

test('a server that takes visitors through another one cannot be detached until that is off', function () {
    [$home, $door] = frontDoorControllerServers();
    ServerFrontDoor::query()->create(['server_id' => $home->id, 'door_server_id' => $door->id, 'state' => ServerFrontDoor::OPEN, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1', 'home_address' => '10.205.0.2']);

    $this->actingAs(frontDoorControllerUser($home->team))
        ->delete(route('servers.destroy', $home))
        ->assertSessionHas('error', "beelink takes visitors through web-1. Turn that off on beelink's Network tab first.");

    expect($home->fresh()->trashed())->toBeFalse();
});

test('the server\'s Network tab offers the team\'s other open servers as doors, and never the server itself', function () {
    [$home, $door] = frontDoorControllerServers();
    Server::factory()->create(['team_id' => $home->team_id, 'status' => ServerStatus::Active, 'public_addresses' => ['ipv4' => '203.0.113.11', 'ipv4_open' => false]]);

    $this->actingAs(frontDoorControllerUser($home->team))
        ->get(route('servers.network', $home))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('frontDoor.state', 'off')
            ->where('frontDoor.doors', [['key' => $door->id, 'name' => 'web-1', 'ipv4' => '203.0.113.9']])
            ->where('frontDoor.tunnel_on', false)
            ->where('frontDoor.can_manage', true));
});

test('a server whose IPv4 address answers has no front door card unless one is on', function () {
    [, $door] = frontDoorControllerServers();

    $this->actingAs(frontDoorControllerUser($door->team))
        ->get(route('servers.network', $door))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('frontDoor', null));
});

test('domains that do not point at the door yet come with the records to set', function () {
    [$home, $door] = frontDoorControllerServers();
    ServerFrontDoor::query()->create([
        'server_id' => $home->id, 'door_server_id' => $door->id, 'state' => ServerFrontDoor::OPEN, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1', 'home_address' => '10.205.0.2',
        'hosts' => [], 'failing' => [['host' => 'pencil.example', 'reason' => "pencil.example doesn't point at web-1 yet.", 'kind' => 'dns'], ['host' => 'shop.example', 'reason' => "shop.example didn't load through web-1.", 'kind' => 'path']],
    ]);

    $this->actingAs(frontDoorControllerUser($home->team))
        ->get(route('servers.network', $home))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('frontDoor.state', 'open')
            ->where('frontDoor.door', ['name' => 'web-1', 'url' => route('servers.show', $door)])
            ->where('frontDoor.records', [['type' => 'A', 'name' => 'pencil.example', 'value' => '203.0.113.9']]));
});

test('the card knows whether Coresmith points the domains itself', function (string $edition, bool $token, bool $automatic) {
    config(['coresmith.edition' => $edition]);

    if ($token) {
        CloudflareConnection::connect('cf-token');
    }

    [$home] = frontDoorControllerServers();

    $this->actingAs(frontDoorControllerUser($home->team))
        ->get(route('servers.network', $home))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('frontDoor.automatic_dns', $automatic));
})->with([
    'self-hosted with a token' => ['self-hosted', true, true],
    'self-hosted without one' => ['self-hosted', false, false],
    'Cloud, even with the operator\'s token' => ['cloud', true, false],
]);
