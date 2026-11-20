<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\ServerTunnel;
use App\Models\Team;
use App\Models\User;
use App\Services\Address\CloudflareConnection;
use App\Services\Network\AddressResolver;
use App\Services\Tunnel\CloudflareTunnels;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

const TUNNEL_SETTINGS_API = 'https://api.cloudflare.test/client/v4';

beforeEach(function () {
    config(['app.url' => 'https://beelink.coresmith.io', 'coresmith.address.cloudflare_api' => TUNNEL_SETTINGS_API]);
    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'];
        }
    });
});

function tunnelSettingsOwner(): User
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

function tunnelSettingsMachine(?array $tunnel = null, ?bool $ipv4Open = false): Server
{
    $server = Server::factory()->create(['name' => 'beelink', 'runs_coresmith' => true, 'public_addresses' => ['ipv4' => '151.226.169.196', 'ipv4_open' => $ipv4Open]]);

    if ($tunnel !== null) {
        ServerTunnel::query()->create(['server_id' => $server->id, 'account_id' => 'acc1', 'tunnel_id' => 'f00d', ...$tunnel]);
    }

    return $server;
}

test("a tunnel that carries Coresmith's address clears the IPv6-only warning at once, while DNS still answers from before", function () {
    $this->travelTo(now()->setTime(12, 0));
    CloudflareConnection::connect('cf-token');
    $server = tunnelSettingsMachine(['state' => ServerTunnel::OPEN, 'hosts' => ['beelink.coresmith.io'], 'checked_at' => now()->subMinutes(5)]);

    $this->actingAs(tunnelSettingsOwner())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('address.ipv6_only', false)
            ->where('address.tunnel', 'carries')
            ->where('address.cloudflare.synced', 'today 11:55')
            ->where('address.cloudflare.tunnel', ['name' => 'beelink', 'url' => route('servers.show', $server)]));
});

test('the warning knows whether the tunnel is off, on without the address, or not on offer for this machine', function (?array $tunnel, ?bool $ipv4Open, bool $machine, ?string $state) {
    if ($machine) {
        tunnelSettingsMachine($tunnel, $ipv4Open);
    }

    $this->actingAs(tunnelSettingsOwner())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address.ipv6_only', true)->where('address.tunnel', $state));
})->with([
    'offered and off' => [null, false, true, 'off'],
    'on without the address yet' => [['state' => ServerTunnel::OPEN, 'hosts' => ['shop.coresmith.io']], false, true, 'on'],
    'still being set up' => [['state' => ServerTunnel::OPENING, 'hosts' => []], false, true, 'on'],
    'IPv4 answers here' => [null, true, true, null],
    'IPv4 not read yet' => [null, null, true, null],
    'this machine is not a server' => [null, null, false, null],
]);

test('Cloudflare stays connected while a tunnel uses its token, and says which tunnel to turn off first', function () {
    CloudflareConnection::connect('cf-token');
    tunnelSettingsMachine(['state' => ServerTunnel::OPEN, 'hosts' => ['beelink.coresmith.io']]);

    $this->actingAs(tunnelSettingsOwner())
        ->delete(route('settings.address.cloudflare.destroy'))
        ->assertRedirect()
        ->assertSessionHas('error', 'The Cloudflare tunnel on beelink uses this token. Turn that tunnel off first, then disconnect.');

    expect(CloudflareConnection::token())->toBe('cf-token');
});

test('a replacement token must still manage the tunnel, or the old one stays', function () {
    CloudflareConnection::connect('cf-old');
    tunnelSettingsMachine(['state' => ServerTunnel::OPEN, 'hosts' => ['beelink.coresmith.io']]);
    Http::fake(function (Request $request) {
        $url = $request->url();
        $good = $request->header('Authorization') === ['Bearer cf-new'];

        return match (true) {
            str_contains($url, '/user/tokens/verify') => Http::response(['success' => true, 'errors' => [], 'result' => ['status' => 'active']]),
            str_contains($url, '/zones?per_page=1') => Http::response(['success' => true, 'errors' => [], 'result' => [['id' => 'z1', 'name' => 'coresmith.io']]]),
            str_contains($url, '/accounts/acc1/cfd_tunnel') && $good => Http::response(['success' => true, 'errors' => [], 'result' => []]),
            default => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403),
        };
    });
    $owner = tunnelSettingsOwner();

    $this->actingAs($owner)
        ->post(route('settings.address.cloudflare.store'), ['token' => 'cf-dns-only'])
        ->assertSessionHasErrors(['token' => CloudflareTunnels::NO_TUNNEL_PERMISSION]);

    expect(CloudflareConnection::token())->toBe('cf-old');

    $this->actingAs($owner)
        ->post(route('settings.address.cloudflare.store'), ['token' => 'cf-new'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Token replaced.');

    expect(CloudflareConnection::token())->toBe('cf-new');
});

test('without a tunnel, a connected token can be disconnected and nothing asks about tunnels', function () {
    CloudflareConnection::connect('cf-token');
    tunnelSettingsMachine();

    $this->actingAs(tunnelSettingsOwner())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address.cloudflare.tunnel', null));

    $this->actingAs(tunnelSettingsOwner())
        ->delete(route('settings.address.cloudflare.destroy'))
        ->assertSessionHas('success', 'Cloudflare is disconnected. Coresmith no longer changes its DNS records.');

    expect(CloudflareConnection::token())->toBeNull();
});
