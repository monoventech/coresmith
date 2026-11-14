<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Events\DeploymentSucceeded;
use App\Jobs\CheckDomainReach;
use App\Jobs\SyncApplicationRoute;
use App\Listeners\CheckNewDomainsAfterDeploy;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Services\Address\AppDomainReach;
use App\Services\Network\AddressResolver;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    config(['coresmith.address.reach_url' => 'https://get.coresmith.io/v1/reach']);
});

function appReachIpv6(): string
{
    return '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
}

function appReachDns(array $records): void
{
    app()->instance(AddressResolver::class, new class($records) implements AddressResolver
    {
        public function __construct(private readonly array $records) {}

        public function addresses(string $host): array
        {
            return $this->records;
        }
    });
}

function appReachServerOutput(bool $ipv4Open, bool $ipv6 = true): string
{
    return implode("\n", [
        '== inet6',
        ...($ipv6 ? ['2a065902360e27001111222233334444 02 40 00 01     enp1s0', '2a065902360e27009ec7d3fffeb832b8 02 40 00 80     enp1s0'] : []),
        'fe80000000000000020000fffe000001 02 40 20 80     enp1s0',
        '== addresses',
        '127.0.0.1/8',
        '192.168.0.248/24',
        ...($ipv6 ? [appReachIpv6() . '/64'] : []),
        '== direct',
        (string) json_encode(['address' => '203.0.113.7', 'ports' => ['80' => $ipv4Open, '443' => $ipv4Open]]),
    ]);
}

function appReachSsh(string $serverOutput, ?string $hostAnswer = null): FakeSSHTransport
{
    $transport = (new FakeSSHTransport)->respondTo("echo '== inet6'", $serverOutput);

    if ($hostAnswer !== null) {
        $transport->respondTo('host=', $hostAnswer);
    }

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return $transport;
}

function appReachApp(array $attributes = []): Application
{
    $server = Server::factory()->create(['name' => 'beelink', 'host_key_fingerprint' => null]);

    return Application::factory()->for($server->team)->for($server)->create(['domain' => 'notes.example.net', ...$attributes]);
}

function appReachMember(Application $application, TeamRole $role): User
{
    $user = User::factory()->create(['current_team_id' => $application->team_id]);
    $application->team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $role !== TeamRole::Developer]);

    return $user->fresh();
}

test('a domain on a server whose IPv4 address answers is told to add an A record', function () {
    $application = appReachApp();
    appReachDns([]);
    appReachSsh(appReachServerOutput(ipv4Open: true));

    $report = app(AppDomainReach::class)->check($application)['notes.example.net'];

    expect($report->ready)->toBeFalse()
        ->and($report->problems)->toBe(["notes.example.net doesn't point anywhere yet. Add an A record for notes.example.net pointing at 203.0.113.7, then check again."]);
});

test('a domain on a home server nobody reaches over IPv4 is told why, with both fixes and its stable IPv6 address', function () {
    $application = appReachApp();
    appReachDns([]);
    appReachSsh(appReachServerOutput(ipv4Open: false));

    $report = app(AppDomainReach::class)->check($application)['notes.example.net'];

    expect($report->problems)->toBe(["notes.example.net doesn't point anywhere yet, and nothing on the internet reaches this server's IPv4 address, 203.0.113.7, on ports 80 and 443. Either your router isn't forwarding those ports to 192.168.0.248, or your internet provider shares that address with other customers, as many home connections do. The surest fix is IPv6 with Cloudflare's proxy: add an AAAA record for notes.example.net pointing at 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8 and turn the proxy on, so visitors without IPv6 reach it too. That needs notes.example.net's DNS on Cloudflare. If your router blocks incoming IPv6, allow ports 80 and 443 to 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8 in its IPv6 firewall. Add the A record only if 203.0.113.7 is yours and you can forward ports 80 and 443 to 192.168.0.248. Then check again."])
        ->and($report->records)->toBe([
            ['type' => 'AAAA', 'name' => 'notes.example.net', 'value' => appReachIpv6(), 'note' => "Best fix, with Cloudflare's proxy on"],
            ['type' => 'A', 'name' => 'notes.example.net', 'value' => '203.0.113.7', 'note' => 'Only if the address is yours and you can forward ports 80 and 443'],
        ]);
});

test('an AAAA record the internet reaches reads as reachable, asked from the server over IPv6', function () {
    $application = appReachApp();
    appReachDns([appReachIpv6()]);
    $transport = appReachSsh(appReachServerOutput(ipv4Open: false), json_encode(['address' => appReachIpv6(), 'http' => ['reachable' => true, 'status' => 308], 'https' => ['reachable' => true, 'status' => 200]]) . "\n200");

    $report = app(AppDomainReach::class)->check($application)['notes.example.net'];
    $asked = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'host='));

    expect($report->ready)->toBeTrue()
        ->and($report->problems)->toBe([])
        ->and($asked)->toContain('curl -6')
        ->and($asked)->toContain("--interface '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'")
        ->and($asked)->toContain('host=notes.example.net');
});

test("an AAAA record a home router's IPv6 firewall blocks says to allow ports 80 and 443 there", function () {
    $application = appReachApp();
    appReachDns([appReachIpv6()]);
    appReachSsh(appReachServerOutput(ipv4Open: false), json_encode(['address' => appReachIpv6(), 'http' => ['reachable' => false, 'status' => null], 'https' => ['reachable' => false, 'status' => null]]) . "\n200");

    $report = app(AppDomainReach::class)->check($application)['notes.example.net'];

    expect($report->ready)->toBeFalse()
        ->and($report->problems)->toBe(['notes.example.net points at 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8, but nothing reaches it on ports 80 and 443 from the internet. Home routers often block incoming IPv6, so allow ports 80 and 443 to 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8 in your router\'s IPv6 firewall, then check again.']);
});

test('a temporary IPv6 address is never offered as the one to point a domain at', function () {
    $application = appReachApp();
    appReachDns([]);
    appReachSsh(appReachServerOutput(ipv4Open: false));

    $report = app(AppDomainReach::class)->check($application)['notes.example.net'];

    expect($report->problems[0])->not->toContain('2a06:5902:360e:2700:1111:2222:3333:4444');
});

test("the check stores each domain's reading on the app", function () {
    $this->travelTo(now()->startOfMinute());
    $application = appReachApp(['additional_domains' => ['www.notes.example.net']]);
    appReachDns([]);
    appReachSsh(appReachServerOutput(ipv4Open: true));

    app()->call([new CheckDomainReach($application), 'handle']);

    $reach = $application->fresh()->domain_reach;

    expect(array_keys($reach))->toBe(['notes.example.net', 'www.notes.example.net'])
        ->and($reach['notes.example.net']['ready'])->toBeFalse()
        ->and($reach['notes.example.net']['problems'][0])->toStartWith("notes.example.net doesn't point anywhere yet.")
        ->and($reach['notes.example.net']['records'])->toEqual([['type' => 'A', 'name' => 'notes.example.net', 'value' => '203.0.113.7', 'note' => null]])
        ->and($reach['notes.example.net']['checked_at'])->toBe(now()->toIso8601String());
});

test("the check keeps what it learned about the server's addresses, for the next domain added there", function () {
    $this->travelTo(now()->startOfMinute());
    $application = appReachApp();
    appReachDns([]);
    appReachSsh(appReachServerOutput(ipv4Open: false));

    app()->call([new CheckDomainReach($application), 'handle']);

    expect($application->server->fresh()->public_addresses)->toEqual([
        'ipv4' => '203.0.113.7',
        'ipv4_open' => false,
        'ipv6' => [appReachIpv6()],
        'checked_at' => now()->toIso8601String(),
    ])->and($application->server->fresh()->public_addresses['ipv4_open'])->toBeFalse();
});

test('a check that cannot reach the server says so for each domain', function () {
    $application = appReachApp();
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->refuseConnections());

    app()->call([new CheckDomainReach($application), 'handle']);

    $reading = $application->fresh()->domain_reach['notes.example.net'];

    expect($reading['ready'])->toBeNull()
        ->and($reading['problems'])->toBe([])
        ->and($reading['notes'])->toBe(["Coresmith couldn't reach beelink to check notes.example.net. It checks again after the next deploy, or when you press Check again."]);
});

test('adding a domain to a live app checks it from outside once it is routed', function () {
    Bus::fake();
    $application = appReachApp(['current_release' => '20261113-120000']);
    $owner = appReachMember($application, TeamRole::Owner);

    $this->actingAs($owner)->put(route('servers.applications.update', [$application->server, $application]), ['additional_domains' => ['www.notes.example.net']])
        ->assertSessionHasNoErrors();

    Bus::assertChained([SyncApplicationRoute::class, CheckDomainReach::class]);
});

test('removing a domain only routes the app again', function () {
    Bus::fake();
    $application = appReachApp(['current_release' => '20261113-120000', 'additional_domains' => ['www.notes.example.net']]);
    $owner = appReachMember($application, TeamRole::Owner);

    $this->actingAs($owner)->put(route('servers.applications.update', [$application->server, $application]), ['additional_domains' => []])
        ->assertSessionHasNoErrors();

    Bus::assertDispatched(SyncApplicationRoute::class);
    Bus::assertNotDispatched(CheckDomainReach::class);
});

test('a deploy checks every domain not yet confirmed reachable, and leaves reachable ones alone', function () {
    Bus::fake([CheckDomainReach::class]);
    $fresh = appReachApp();
    $unreachable = appReachApp(['domain_reach' => ['notes.example.net' => ['ready' => false, 'problems' => ['notes.example.net needs an AAAA record.'], 'notes' => [], 'checked_at' => now()->toIso8601String()]]]);
    $reachable = appReachApp(['domain_reach' => ['notes.example.net' => ['ready' => true, 'problems' => [], 'notes' => [], 'checked_at' => now()->toIso8601String()]]]);

    foreach ([$fresh, $unreachable, $reachable] as $application) {
        app(CheckNewDomainsAfterDeploy::class)->handle(new DeploymentSucceeded(Deployment::factory()->for($application)->create()));
    }

    Bus::assertDispatchedTimes(CheckDomainReach::class, 2);
    Bus::assertNotDispatched(CheckDomainReach::class, fn(CheckDomainReach $job): bool => $job->application->is($reachable));
});

test('the deploy listener is wired to successful deploys', function () {
    Event::fake();

    Event::assertListening(DeploymentSucceeded::class, CheckNewDomainsAfterDeploy::class);
});

test('anyone who can change an app can check its domains again, and nobody else can', function () {
    Bus::fake([CheckDomainReach::class]);
    $application = appReachApp();
    $owner = appReachMember($application, TeamRole::Owner);
    $deployer = appReachMember($application, TeamRole::Member);

    $this->actingAs($deployer)->post(route('servers.applications.domains.check', [$application->server, $application]))->assertForbidden();
    $this->actingAs($owner)->post(route('servers.applications.domains.check', [$application->server, $application]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Checking from outside. The results show here in a few seconds.');

    Bus::assertDispatchedTimes(CheckDomainReach::class, 1);
});

test("the app page shows each domain's last reading, the records it asks for, and where to check again", function () {
    $this->travelTo(now()->setTime(12, 0));
    $record = ['type' => 'AAAA', 'name' => 'notes.example.net', 'value' => appReachIpv6()];
    $application = appReachApp([
        'additional_domains' => ['www.notes.example.net'],
        'domain_reach' => [
            'notes.example.net' => ['ready' => false, 'problems' => ['notes.example.net needs an AAAA record.'], 'notes' => [], 'records' => [$record], 'checked_at' => now()->subMinutes(5)->toIso8601String()],
            'www.notes.example.net' => ['ready' => true, 'problems' => [], 'notes' => [], 'checked_at' => now()->subHour()->toIso8601String()],
            'old.example.net' => ['ready' => true, 'problems' => [], 'notes' => [], 'checked_at' => now()->subDay()->toIso8601String()],
        ],
    ]);
    $owner = appReachMember($application, TeamRole::Owner);

    $this->actingAs($owner)->get(route('servers.applications.show', [$application->server, $application]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('application.domain_reach', [
                ['domain' => 'notes.example.net', 'ready' => false, 'problems' => ['notes.example.net needs an AAAA record.'], 'notes' => [], 'records' => [$record], 'checked' => 'today 11:55'],
                ['domain' => 'www.notes.example.net', 'ready' => true, 'problems' => [], 'notes' => [], 'records' => [], 'checked' => 'today 11:00'],
            ])
            ->where('application.domain_check_href', route('servers.applications.domains.check', [$application->server, $application])));
});

test("Add an app shows the server's addresses to point a domain at, from its last reading", function () {
    $application = appReachApp();
    $application->server->forceFill(['public_addresses' => ['ipv4' => '203.0.113.7', 'ipv4_open' => true, 'ipv6' => [], 'checked_at' => '2026-11-13T12:00:00+00:00']])->save();
    $owner = appReachMember($application, TeamRole::Owner);

    $this->actingAs($owner)->get(route('servers.applications.create', $application->server))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('server.dns_targets', [['type' => 'A', 'value' => '203.0.113.7', 'closed' => false]]));
});

test("the Add a domain dialog shows the server's addresses from its last reading, and nothing before one", function (?array $addresses, array $targets) {
    $application = appReachApp();
    $application->server->forceFill(['public_addresses' => $addresses])->save();
    $owner = appReachMember($application, TeamRole::Owner);

    $this->actingAs($owner)->get(route('servers.applications.show', [$application->server, $application]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('application.dns_targets', $targets));
})->with([
    'IPv4 open, with IPv6' => [['ipv4' => '203.0.113.7', 'ipv4_open' => true, 'ipv6' => [appReachIpv6()], 'checked_at' => '2026-11-13T12:00:00+00:00'], [['type' => 'A', 'value' => '203.0.113.7', 'closed' => false], ['type' => 'AAAA', 'value' => appReachIpv6(), 'closed' => false]]],
    'IPv4 closed, with IPv6' => [['ipv4' => '203.0.113.7', 'ipv4_open' => false, 'ipv6' => [appReachIpv6()], 'checked_at' => '2026-11-13T12:00:00+00:00'], [['type' => 'AAAA', 'value' => appReachIpv6(), 'closed' => false], ['type' => 'A', 'value' => '203.0.113.7', 'closed' => true]]],
    'no reading yet' => [null, []],
]);
