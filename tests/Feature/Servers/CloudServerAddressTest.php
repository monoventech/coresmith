<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Network\LocalAddresses;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

function cloudAddressAdd(User $user, Team $team, string $address): TestResponse
{
    return test()->actingAs($user)->post(route('servers.store'), [
        'team_id' => $team->id,
        'name' => 'web-1',
        'ip_address' => $address,
        'ssh_port' => 22,
        'ssh_user' => 'root',
    ]);
}

function cloudAddressAddOnCloud(string $address): TestResponse
{
    CloudBilling::enable();
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    return cloudAddressAdd(User::query()->findOrFail($team->owner_id), $team, $address);
}

beforeEach(function () {
    Queue::fake();
    FakeStripe::fake([FakeStripe::url('subscriptions/*') => fn() => Http::response(FakeStripe::subscription('sub_live', 'cus_live', 'price_test_pro'))]);
    app()->instance(LocalAddresses::class, new class implements LocalAddresses
    {
        public function all(): array
        {
            return ['127.0.0.1', '::1', '203.0.113.10', '2001:db8::10'];
        }
    });
});

test('Coresmith Cloud refuses a server at an address only its own network could reach', function (string $address) {
    cloudAddressAddOnCloud($address)
        ->assertSessionHasErrors(['ip_address' => "That's a private address, so Coresmith Cloud can't reach it. Use your server's public IP address."]);

    expect(Server::query()->count())->toBe(0);
})->with([
    'loopback' => '127.0.0.1',
    'a home network' => '192.168.0.248',
    'a 10.x network' => '10.0.0.5',
    'a 172.16.x network' => '172.16.4.2',
    'the cloud metadata address' => '169.254.169.254',
    'Tailscale and carrier NAT' => '100.100.100.100',
    'the first shared address' => '100.64.0.0',
    'the last shared address' => '100.127.255.255',
    'no address' => '0.0.0.0',
    'IPv6 loopback' => '::1',
    'IPv6 link-local' => 'fe80::1',
    'IPv6 unique local' => 'fd00::1',
    'IPv4 written as IPv6' => '::ffff:10.0.0.1',
]);

test("Coresmith Cloud refuses its own machine's public address", function (string $address) {
    cloudAddressAddOnCloud($address)
        ->assertSessionHasErrors(['ip_address' => "That address belongs to Coresmith Cloud. Use your server's public IP address."]);

    expect(Server::query()->count())->toBe(0);
})->with(['IPv4' => '203.0.113.10', 'IPv6' => '2001:db8::10']);

test('Coresmith Cloud adds a server at a public address', function (string $address) {
    cloudAddressAddOnCloud($address)->assertSessionHasNoErrors();

    expect(Server::query()->sole()->ip_address)->toBe($address);
})->with([
    'IPv4' => '203.0.113.40',
    'IPv6' => '2001:db8::40',
    'just below the shared range' => '100.63.255.255',
    'just above the shared range' => '100.128.0.1',
]);

test('self-hosted Coresmith still adds servers on a private network or Tailscale', function (string $address) {
    $team = Team::factory()->owned()->create();

    cloudAddressAdd(User::query()->findOrFail($team->owner_id), $team, $address)->assertSessionHasNoErrors();

    expect(Server::query()->sole()->ip_address)->toBe($address);
})->with(['a home network' => '192.168.5.15', 'a 10.x network' => '10.0.0.5', 'Tailscale' => '100.100.100.100']);
