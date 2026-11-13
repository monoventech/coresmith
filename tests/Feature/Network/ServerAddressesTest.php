<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\ReadServerAddresses;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeSSHTransport;

beforeEach(fn() => config(['coresmith.address.reach_url' => 'https://get.coresmith.io/v1/reach']));

function serverAddressesOutput(?bool $ipv4Open): string
{
    return implode("\n", [
        '== inet6',
        '2a065902360e27001111222233334444 02 40 00 01     enp1s0',
        '2a065902360e27009ec7d3fffeb832b8 02 40 00 80     enp1s0',
        '== addresses',
        '192.168.0.248/24',
        '== direct',
        $ipv4Open === null ? 'curl: (28) Connection timed out' : (string) json_encode(['address' => '203.0.113.7', 'ports' => ['80' => $ipv4Open, '443' => $ipv4Open]]),
    ]);
}

function serverAddressesReading(string $checked, bool $open = true): array
{
    return ['ipv4' => '203.0.113.7', 'ipv4_open' => $open, 'ipv6' => ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], 'checked_at' => $checked];
}

test('reading a server keeps its public IPv4 address, whether visitors reach it, and its stable IPv6 addresses', function () {
    $this->travelTo(now()->startOfMinute());
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo("echo '== inet6'", serverAddressesOutput(false)));

    app()->call([new ReadServerAddresses($server), 'handle']);

    expect($server->fresh()->public_addresses)->toEqual(serverAddressesReading(now()->toIso8601String(), open: false))
        ->and($server->fresh()->public_addresses['ipv4_open'])->toBeFalse();
});

test("a reading that couldn't ask get.coresmith.io keeps the IPv4 address it had", function () {
    $this->travelTo(now()->startOfMinute());
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'public_addresses' => serverAddressesReading('2026-11-01T00:00:00+00:00')]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo("echo '== inet6'", serverAddressesOutput(null)));

    app()->call([new ReadServerAddresses($server), 'handle']);

    expect($server->fresh()->public_addresses)->toEqual(serverAddressesReading(now()->toIso8601String()))
        ->and($server->fresh()->public_addresses['ipv4_open'])->toBeTrue();
});

test('a server Coresmith cannot reach keeps its last reading', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'public_addresses' => serverAddressesReading('2026-11-01T00:00:00+00:00')]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->refuseConnections());

    app()->call([new ReadServerAddresses($server), 'handle']);

    expect($server->fresh()->public_addresses)->toEqual(serverAddressesReading('2026-11-01T00:00:00+00:00'));
});

test('the hourly pass reads active servers with no reading, or one older than a week', function () {
    Bus::fake([ReadServerAddresses::class]);
    $unread = Server::factory()->create(['status' => ServerStatus::Active]);
    $stale = Server::factory()->create(['status' => ServerStatus::Active, 'public_addresses' => serverAddressesReading(now()->subDays(8)->toIso8601String())]);
    Server::factory()->create(['status' => ServerStatus::Active, 'public_addresses' => serverAddressesReading(now()->subDay()->toIso8601String())]);
    Server::factory()->create(['status' => ServerStatus::Active, 'unreachable_since' => now()]);

    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:read-server-addresses');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('40 * * * *');

    $event->run(app());

    Bus::assertDispatchedTimes(ReadServerAddresses::class, 2);
    Bus::assertDispatched(ReadServerAddresses::class, fn(ReadServerAddresses $job): bool => $job->server->is($unread));
    Bus::assertDispatched(ReadServerAddresses::class, fn(ReadServerAddresses $job): bool => $job->server->is($stale));
});
