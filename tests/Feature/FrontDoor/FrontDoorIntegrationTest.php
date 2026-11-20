<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\SyncServerFrontDoor;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Models\Team;
use App\Models\User;
use App\Services\Address\AddressGate;
use App\Services\Address\AppDomainReach;
use App\Services\Address\ReachReport;
use App\Services\Network\AddressResolver;
use App\Services\Network\ServerAddresses;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    $this->addressState = sys_get_temp_dir() . '/coresmith-address-front-door-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->addressState);
    config(['coresmith.address.site' => '/etc/caddy/coresmith/panel.caddy', 'coresmith.address.state_path' => $this->addressState]);
});

afterEach(fn() => File::deleteDirectory($this->addressState));

function frontDoorIntegrationOpen(array $hosts, array $home = [], array $doorAddresses = ['ipv4' => '203.0.113.9', 'ipv4_open' => true, 'ipv6' => ['2001:db8::9']]): ServerFrontDoor
{
    $homeServer = Server::factory()->create(['name' => 'beelink', 'host_key_fingerprint' => null, 'public_addresses' => ['ipv4' => '151.226.169.196', 'ipv4_open' => false, 'ipv6' => ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']], ...$home]);
    $door = Server::factory()->create(['team_id' => $homeServer->team_id, 'name' => 'web-1', 'public_addresses' => $doorAddresses]);

    return ServerFrontDoor::query()->create([
        'server_id' => $homeServer->id,
        'door_server_id' => $door->id,
        'state' => ServerFrontDoor::OPEN,
        'subnet' => '10.205.0.0/24',
        'door_address' => '10.205.0.1',
        'home_address' => '10.205.0.2',
        'hosts' => $hosts,
    ]);
}

test("while a server takes visitors through another one, Add domain shows that server's addresses", function () {
    $frontDoor = frontDoorIntegrationOpen([]);

    expect(ServerAddresses::targetsFor($frontDoor->home))->toBe([
        ['type' => 'A', 'value' => '203.0.113.9', 'closed' => false, 'note' => 'Points at web-1, which passes visitors on to this server.'],
        ['type' => 'AAAA', 'value' => '2001:db8::9', 'closed' => false, 'note' => 'Points at web-1, which passes visitors on to this server.'],
    ]);
});

test('an app domain the other server carries reads as reaching this server through it', function () {
    Queue::fake();
    $frontDoor = frontDoorIntegrationOpen(['notes.example.net']);
    $application = Application::factory()->for($frontDoor->home->team)->for($frontDoor->home)->create(['domain' => 'notes.example.net']);
    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['203.0.113.9'];
        }
    });
    $transport = (new FakeSSHTransport)->respondTo("echo '== inet6'", "== inet6\n== addresses\n127.0.0.1/8\n192.168.0.248/24\n== direct\n" . json_encode(['address' => '151.226.169.196', 'ports' => ['80' => false, '443' => false]]));
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    Http::fake();

    $report = app(AppDomainReach::class)->check($application)['notes.example.net'];

    expect($report->ready)->toBeTrue()
        ->and($report->notes)->toBe(['notes.example.net reaches this server through web-1.']);
});

function frontDoorIntegrationReach(ServerFrontDoor $frontDoor, string $domain, ?string $reading = null): ReachReport
{
    Queue::fake();
    $application = Application::factory()->for($frontDoor->home->team)->for($frontDoor->home)->create(['domain' => $domain]);
    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'];
        }
    });
    $transport = (new FakeSSHTransport)->respondTo("echo '== inet6'", "== inet6\n== addresses\n127.0.0.1/8\n192.168.0.248/24\n== direct\n" . json_encode(['address' => '151.226.169.196', 'ports' => ['80' => false, '443' => false]]));
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    Http::fake();

    return app(AppDomainReach::class)->check($application)[$reading ?? $domain];
}

test("an app domain that doesn't point at the other server yet says so and lists the records to set", function () {
    $frontDoor = frontDoorIntegrationOpen([]);
    $frontDoor->forceFill(['failing' => [['host' => 'notes.example.net', 'reason' => "notes.example.net doesn't point at web-1 yet.", 'kind' => 'dns']]])->save();

    $report = frontDoorIntegrationReach($frontDoor, 'notes.example.net');

    expect($report->ready)->toBeFalse()
        ->and($report->problems)->toBe(["notes.example.net doesn't point at web-1 yet."])
        ->and($report->records)->toBe([
            ['type' => 'A', 'name' => 'notes.example.net', 'value' => '203.0.113.9', 'note' => null],
            ['type' => 'AAAA', 'name' => 'notes.example.net', 'value' => '2001:db8::9', 'note' => null],
        ]);
});

test("an app domain that doesn't load through the other server says why, with no records to change", function () {
    $frontDoor = frontDoorIntegrationOpen([]);
    $frontDoor->forceFill(['failing' => [['host' => 'notes.example.net', 'reason' => "notes.example.net didn't load through web-1. Check that its app is deployed on beelink.", 'kind' => 'path']]])->save();

    $report = frontDoorIntegrationReach($frontDoor, 'notes.example.net');

    expect($report->ready)->toBeFalse()
        ->and($report->problems)->toBe(["notes.example.net didn't load through web-1. Check that its app is deployed on beelink."])
        ->and($report->records)->toBe([]);
});

test('an app domain Coresmith could not point at the other server gives the reason and the records to set', function () {
    $frontDoor = frontDoorIntegrationOpen([]);
    $frontDoor->forceFill(['skipped' => [['host' => 'notes.example.net', 'reason' => 'notes.example.net is a CNAME. Point it at web-1 yourself.']]])->save();

    $report = frontDoorIntegrationReach($frontDoor, 'notes.example.net');

    expect($report->problems)->toBe(['notes.example.net is a CNAME. Point it at web-1 yourself.'])
        ->and($report->records)->toHaveCount(2);
});

test('a domain added since the other server last checked reads as being added to it', function () {
    $report = frontDoorIntegrationReach(frontDoorIntegrationOpen([]), 'notes.example.net');

    expect($report->ready)->toBeNull()
        ->and($report->problems)->toBe([])
        ->and($report->notes)->toBe(['Coresmith is adding notes.example.net to web-1. Check again in a minute.']);
});

test('a www twin nobody has set up reads as optional, with the records that point it at the other server', function () {
    $report = frontDoorIntegrationReach(frontDoorIntegrationOpen(['example.net']), 'example.net', 'www.example.net');

    expect($report->problems)->toBe([])
        ->and($report->notes)->toBe(['www.example.net sends visitors to example.net once it points at web-1.'])
        ->and(array_column($report->records, 'value'))->toBe(['203.0.113.9', '2001:db8::9']);
});

test("a wildcard domain the other server can't take gets the usual check", function () {
    $report = frontDoorIntegrationReach(frontDoorIntegrationOpen([]), '*.notes.example.net');

    expect(implode(' ', [...$report->problems, ...$report->notes]))->not->toContain('web-1');
});

test("Coresmith's own address through this machine's front door passes the address check without an outside test", function () {
    frontDoorIntegrationOpen(['panel.example.com'], ['runs_coresmith' => true]);
    Http::fake();

    $verdict = app(AddressGate::class)->check('panel.example.com');

    expect($verdict->refusal)->toBeNull()
        ->and($verdict->notes)->toBe(['panel.example.com reaches Coresmith through web-1.']);
    Http::assertNothingSent();
});

test("while this machine's front door is on, moving Coresmith to another address waits until it's off", function () {
    frontDoorIntegrationOpen(['panel.example.com'], ['runs_coresmith' => true]);

    $verdict = app(AddressGate::class)->check('new.example.com');

    expect($verdict->refusal)->toBe("Coresmith can't change its address while visitors come in through web-1. Turn off IPv4 through another server on this machine's page, change the address, then turn it on again.")
        ->and($verdict->pointable)->toBeFalse();
});

test("Settings stops warning about IPv6 once this machine's front door carries Coresmith's address", function () {
    config(['app.url' => 'https://beelink.example.com']);
    $frontDoor = frontDoorIntegrationOpen(['beelink.example.com'], ['runs_coresmith' => true]);
    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'];
        }
    });
    $owner = User::factory()->create();
    Team::query()->findOrFail($frontDoor->home->team_id)->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner->fresh())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address.ipv6_only', false)->where('address.front_door', null));
});

test("while this machine's front door doesn't carry Coresmith's address yet, Settings names the other server", function () {
    config(['app.url' => 'https://beelink.example.com']);
    $frontDoor = frontDoorIntegrationOpen([], ['runs_coresmith' => true]);
    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'];
        }
    });
    $owner = User::factory()->create();
    Team::query()->findOrFail($frontDoor->home->team_id)->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner->fresh())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address.ipv6_only', true)->where('address.front_door', 'web-1'));
});

test('a domain added, changed or removed on a server that takes visitors through another one updates the front door', function () {
    Queue::fake();
    $frontDoor = frontDoorIntegrationOpen([]);
    $home = $frontDoor->home;

    $application = Application::factory()->for($home->team)->for($home)->create(['domain' => 'notes.example.net']);
    Queue::assertPushed(SyncServerFrontDoor::class, 1);

    $application->update(['additional_domains' => ['docs.example.net']]);
    Queue::assertPushed(SyncServerFrontDoor::class, 2);

    $application->update(['name' => 'notes-2']);
    Queue::assertPushed(SyncServerFrontDoor::class, 2);

    $application->delete();
    Queue::assertPushed(SyncServerFrontDoor::class, 3);
    Queue::assertPushed(SyncServerFrontDoor::class, fn(SyncServerFrontDoor $job): bool => $job->server->is($home));
});

test("domain changes leave a front door alone while it isn't open", function (string $state) {
    Queue::fake();
    $frontDoor = frontDoorIntegrationOpen([]);
    $frontDoor->forceFill(['state' => $state])->save();

    Application::factory()->for($frontDoor->home->team)->for($frontDoor->home)->create(['domain' => 'notes.example.net']);

    Queue::assertNotPushed(SyncServerFrontDoor::class);
})->with([ServerFrontDoor::OPENING, ServerFrontDoor::FAILED, ServerFrontDoor::CLOSING]);
