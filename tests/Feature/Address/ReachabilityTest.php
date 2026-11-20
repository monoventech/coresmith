<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Address\Reachability;
use App\Services\Network\AddressResolver;
use App\Services\Network\DirectReach;
use App\Services\Network\HostReach;
use App\Services\Network\LocalAddresses;
use App\Services\Network\ReachProbe;
use App\Services\Network\StableAddresses;

function reachabilityNetwork(array $records, array $local = ['127.0.0.1', '192.168.0.248'], array $stableIpv6 = []): void
{
    app()->instance(AddressResolver::class, new class($records) implements AddressResolver
    {
        public function __construct(private readonly array $records) {}

        public function addresses(string $host): array
        {
            return $this->records;
        }
    });

    app()->instance(LocalAddresses::class, new class($local) implements LocalAddresses
    {
        public function __construct(private readonly array $addresses) {}

        public function all(): array
        {
            return $this->addresses;
        }
    });

    app()->instance(StableAddresses::class, new class($stableIpv6) implements StableAddresses
    {
        public function __construct(private readonly array $addresses) {}

        public function ipv6(): array
        {
            return $this->addresses;
        }
    });
}

function reachabilityProbe(?DirectReach $direct, ?HostReach $host = null): object
{
    $probe = new class($direct, $host) implements ReachProbe
    {
        public array $asked = [];

        public function __construct(private readonly ?DirectReach $directReach, private readonly ?HostReach $hostReach) {}

        public function direct(): ?DirectReach
        {
            return $this->directReach;
        }

        public function host(string $host, string $from): ?HostReach
        {
            $this->asked[] = [$host, $from];

            return $this->hostReach;
        }
    };

    app()->instance(ReachProbe::class, $probe);

    return $probe;
}

test('a domain that points at a public address the internet reaches on 80 and 443 is ready', function () {
    reachabilityNetwork(['203.0.113.10'], ['127.0.0.1', '203.0.113.10']);
    reachabilityProbe(new DirectReach('203.0.113.10', true, true));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->ready)->toBeTrue()
        ->and($report->problems)->toBe([])
        ->and($report->notes)->toBe([]);
});

test('behind a router that forwards 80 and 443, the domain is ready and the note says how to open it at home too', function () {
    reachabilityNetwork(['151.226.169.196']);
    reachabilityProbe(new DirectReach('151.226.169.196', true, true));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->ready)->toBeTrue()
        ->and($report->notes)->toBe(["If coresmith.example.com doesn't load inside your own network, add 192.168.0.248 coresmith.example.com to your local DNS or /etc/hosts."]);
});

const REACHABILITY_BEST = "Best fix, with Cloudflare's proxy on";

const REACHABILITY_IF_YOURS = 'Only if the address is yours and you can forward ports 80 and 443';

function reachabilityRecord(string $type, string $value, string $name = 'coresmith.example.com', ?string $note = null): array
{
    return ['type' => $type, 'name' => $name, 'value' => $value, 'note' => $note];
}

test('a domain with no records yet says which record to add, ready to copy', function (?DirectReach $direct, array $stable, string $problem, array $records) {
    reachabilityNetwork([], ['127.0.0.1', '192.168.0.248'], $stable);
    reachabilityProbe($direct);

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->ready)->toBeFalse()
        ->and($report->problems)->toBe([$problem])
        ->and($report->records)->toBe($records);
})->with([
    'reachable over IPv4' => [new DirectReach('151.226.169.196', true, true), [], "coresmith.example.com doesn't point anywhere yet. Add an A record pointing at 151.226.169.196, then check again.", [reachabilityRecord('A', '151.226.169.196')]],
    'behind a router, with IPv6' => [new DirectReach('151.226.169.196', false, false), ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], "coresmith.example.com doesn't point anywhere yet, and this server's IPv4 address, 151.226.169.196, doesn't answer on ports 80 and 443 from the internet. Add this machine to Coresmith as a server and turn on IPv4 through Cloudflare on its page, then check again. If 151.226.169.196 is yours alone, you can instead forward ports 80 and 443 to 192.168.0.248 and add an A record for it.", [reachabilityRecord('A', '151.226.169.196', note: REACHABILITY_IF_YOURS)]],
    'no IPv4 reading, with IPv6' => [null, ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], "coresmith.example.com doesn't point anywhere yet. Add an AAAA record pointing at 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8 and turn on Cloudflare's proxy for it, so visitors without IPv6 reach it too. Then check again.", [reachabilityRecord('AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', note: REACHABILITY_BEST)]],
    'behind a router, no IPv6' => [new DirectReach('151.226.169.196', false, false), [], "coresmith.example.com doesn't point anywhere yet, and this server's IPv4 address, 151.226.169.196, doesn't answer on ports 80 and 443 from the internet. Add this machine to Coresmith as a server and turn on IPv4 through Cloudflare on its page, then check again. If 151.226.169.196 is yours alone, you can instead forward ports 80 and 443 to 192.168.0.248 and add an A record for it.", [reachabilityRecord('A', '151.226.169.196', note: REACHABILITY_IF_YOURS)]],
    'nothing known' => [null, [], "coresmith.example.com doesn't point anywhere yet. Add a DNS record that points it at this server, then check again.", []],
]);

test('a domain with no records on a server with a public address of its own says to open its firewall', function (array $stable, string $problem, array $records) {
    reachabilityNetwork([], ['127.0.0.1', '151.226.169.196'], $stable);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->problems)->toBe([$problem])
        ->and($report->records)->toBe($records);
})->with([
    'with IPv6' => [['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], "coresmith.example.com doesn't point anywhere yet, and this server's IPv4 address, 151.226.169.196, doesn't answer on ports 80 and 443 from the internet. Open ports 80 and 443 in your provider's firewall and add an A record pointing at 151.226.169.196, or add an AAAA record pointing at 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8 for visitors with IPv6. Then check again.", [reachabilityRecord('A', '151.226.169.196'), reachabilityRecord('AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8')]],
    'no IPv6' => [[], "coresmith.example.com doesn't point anywhere yet, and this server's IPv4 address, 151.226.169.196, doesn't answer on ports 80 and 443 from the internet. Open ports 80 and 443 in your provider's firewall, then add an A record pointing at 151.226.169.196.", [reachabilityRecord('A', '151.226.169.196')]],
]);

test('a domain that points at another address says which one this server has', function () {
    reachabilityNetwork(['198.51.100.7']);
    reachabilityProbe(new DirectReach('151.226.169.196', true, true));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->problems)->toBe(['coresmith.example.com points at 198.51.100.7, but this server\'s public address is 151.226.169.196. Point it at 151.226.169.196, then check again.'])
        ->and($report->records)->toBe([reachabilityRecord('A', '151.226.169.196')]);
});

test('behind a router that forwards nothing, the problem leads with IPv4 through Cloudflare and keeps port forwarding for an address of your own', function (array $stable, string $problem, array $records) {
    reachabilityNetwork(['151.226.169.196'], ['127.0.0.1', '192.168.0.248'], $stable);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->ready)->toBeFalse()
        ->and($report->problems)->toBe([$problem])
        ->and($report->records)->toBe($records);
})->with([
    'no IPv6' => [[], "coresmith.example.com points at 151.226.169.196, which doesn't answer on ports 80 and 443 from the internet. Add this machine to Coresmith as a server and turn on IPv4 through Cloudflare on its page, then check again. If 151.226.169.196 is yours alone, you can instead forward those ports to 192.168.0.248.", []],
    'with IPv6' => [['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], "coresmith.example.com points at 151.226.169.196, which doesn't answer on ports 80 and 443 from the internet. Add this machine to Coresmith as a server and turn on IPv4 through Cloudflare on its page, then check again. If 151.226.169.196 is yours alone, you can instead forward those ports to 192.168.0.248.", []],
]);

test('a server with its own public address that the internet cannot reach is told to open its provider firewall', function () {
    reachabilityNetwork(['203.0.113.10'], ['127.0.0.1', '203.0.113.10']);
    reachabilityProbe(new DirectReach('203.0.113.10', false, false));

    expect(app(Reachability::class)->check('coresmith.example.com')->problems)
        ->toBe(["coresmith.example.com points at 203.0.113.10, which doesn't answer on ports 80 and 443 from the internet. Open those ports in your provider's firewall, then check again."]);
});

test('an IPv6 record is checked from outside, from the address it names', function () {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork([$v6], ['127.0.0.1', '192.168.0.248', $v6], [$v6]);
    $probe = reachabilityProbe(new DirectReach('151.226.169.196', false, false), new HostReach($v6, true, true, true));

    $report = app(Reachability::class)->check('beelink.coresmith.io');

    expect($report->ready)->toBeTrue()
        ->and($report->problems)->toBe([])
        ->and($probe->asked)->toBe([['beelink.coresmith.io', $v6]]);
});

test('an IPv6 record nothing reaches is told to open the router\'s IPv6 firewall', function () {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork([$v6], ['127.0.0.1', '192.168.0.248', $v6], [$v6]);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false), new HostReach($v6, true, false, false));

    expect(app(Reachability::class)->check('beelink.coresmith.io')->problems)
        ->toBe(["beelink.coresmith.io points at {$v6}, which doesn't answer on ports 80 and 443 from the internet. Allow those ports to {$v6} in your router's IPv6 firewall, then check again."]);
});

test('an IPv6 record for another address says which one this server has', function () {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork(['2a06:5902:360e:2700::99'], ['127.0.0.1', '192.168.0.248', $v6], [$v6]);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false));

    $report = app(Reachability::class)->check('beelink.coresmith.io');

    expect($report->problems)->toBe(["beelink.coresmith.io points at 2a06:5902:360e:2700::99, but this server's IPv6 address is {$v6}. Point it at {$v6}, then check again."])
        ->and($report->records)->toBe([reachabilityRecord('AAAA', $v6, 'beelink.coresmith.io')]);
});

test('an A record nothing reaches beside a working IPv6 record is ready, with a note on reaching visitors without IPv6', function (array $local, string $address, string $note) {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork([$address, $v6], [...$local, $v6], [$v6]);
    reachabilityProbe(new DirectReach($address, false, false), new HostReach($v6, true, true, true));

    $report = app(Reachability::class)->check('beelink.coresmith.io');

    expect($report->ready)->toBeTrue()
        ->and($report->notes)->toBe([$note])
        ->and($report->records)->toBe([]);
})->with([
    'behind a router' => [['127.0.0.1', '192.168.0.248'], '151.226.169.196', "Visitors without IPv6 can't open beelink.coresmith.io: its A record points at 151.226.169.196, which doesn't answer on ports 80 and 443. Add this machine to Coresmith as a server and turn on IPv4 through Cloudflare on its page to reach them. If 151.226.169.196 is yours alone, you can instead forward those ports to 192.168.0.248."],
    'with its own public address' => [['127.0.0.1', '203.0.113.10'], '203.0.113.10', "Visitors without IPv6 can't open beelink.coresmith.io: its A record points at 203.0.113.10, which doesn't answer on ports 80 and 443. Open those ports in your provider's firewall, or remove the A record and turn on Cloudflare's proxy for beelink.coresmith.io."],
]);

test('a domain with only an IPv6 address is ready, and the note says who it leaves out and what reaches them', function (array $local, ?DirectReach $direct, string $note, array $records) {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork([$v6], [...$local, $v6], [$v6]);
    reachabilityProbe($direct, new HostReach($v6, true, true, true));

    $report = app(Reachability::class)->check('beelink.coresmith.io');

    expect($report->ready)->toBeTrue()
        ->and($report->problems)->toBe([])
        ->and($report->notes)->toBe([$note])
        ->and($report->records)->toBe($records);
})->with([
    'its IPv4 address takes visitors' => [['127.0.0.1', '203.0.113.10'], new DirectReach('203.0.113.10', true, true), 'beelink.coresmith.io has only an IPv6 address, so visitors without IPv6 can\'t open it. This server answers on IPv4 too, so add an A record pointing at 203.0.113.10.', [reachabilityRecord('A', '203.0.113.10', 'beelink.coresmith.io')]],
    'its IPv4 address is closed or shared' => [['127.0.0.1', '192.168.0.248'], new DirectReach('151.226.169.196', false, false), "beelink.coresmith.io has only an IPv6 address, so visitors without IPv6 can't open it. Add this machine to Coresmith as a server and turn on IPv4 through Cloudflare on its page to reach them. If 151.226.169.196 is yours alone, you can instead forward ports 80 and 443 to 192.168.0.248 and add an A record for it.", [reachabilityRecord('A', '151.226.169.196', 'beelink.coresmith.io', REACHABILITY_IF_YOURS)]],
    'its own IPv4 address is closed' => [['127.0.0.1', '203.0.113.10'], new DirectReach('203.0.113.10', false, false), "beelink.coresmith.io has only an IPv6 address, so visitors without IPv6 can't open it. Open ports 80 and 443 in your provider's firewall and add an A record pointing at 203.0.113.10, or turn on Cloudflare's proxy for beelink.coresmith.io.", [reachabilityRecord('A', '203.0.113.10', 'beelink.coresmith.io')]],
    'it has no IPv4 route' => [['127.0.0.1', '192.168.0.248'], null, "beelink.coresmith.io has only an IPv6 address, so visitors without IPv6 can't open it. Turn on Cloudflare's proxy for beelink.coresmith.io, which answers them over IPv4.", [reachabilityRecord('AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', 'beelink.coresmith.io', REACHABILITY_BEST)]],
]);

test("once this machine is a server in Coresmith, the advice names this machine's page", function () {
    Server::factory()->create(['runs_coresmith' => true]);
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork([$v6], ['127.0.0.1', '192.168.0.248', $v6], [$v6]);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false), new HostReach($v6, true, true, true));

    expect(app(Reachability::class)->check('beelink.coresmith.io')->notes)
        ->toBe(["beelink.coresmith.io has only an IPv6 address, so visitors without IPv6 can't open it. Turn on IPv4 through Cloudflare on this machine's page to reach them. If 151.226.169.196 is yours alone, you can instead forward ports 80 and 443 to 192.168.0.248 and add an A record for it."]);
});

test('on Cloud, which has no tunnel, a closed IPv4 behind a router keeps the advice that works without one', function (array $records, array $stable, array $problems, array $notes, array $rows) {
    config(['coresmith.edition' => 'cloud']);
    reachabilityNetwork($records, ['127.0.0.1', '192.168.0.248'], $stable);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false), new HostReach('2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', true, true, true));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->problems)->toBe($problems)
        ->and($report->notes)->toBe($notes)
        ->and($report->records)->toBe($rows);
})->with([
    'no records, no IPv6' => [[], [], ["coresmith.example.com doesn't point anywhere yet, and this server's IPv4 address, 151.226.169.196, doesn't answer on ports 80 and 443 from the internet. Forward ports 80 and 443 on your router to 192.168.0.248, then add an A record pointing at 151.226.169.196. If your provider shares that address with other customers, use IPv6."], [], [reachabilityRecord('A', '151.226.169.196')]],
    'an A record, no IPv6' => [['151.226.169.196'], [], ["coresmith.example.com points at 151.226.169.196, which doesn't answer on ports 80 and 443 from the internet. Forward those ports on your router to 192.168.0.248, then check again. If your provider shares that address with other customers, use IPv6."], [], []],
    'only an IPv6 record' => [['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], [], ["coresmith.example.com has only an IPv6 address, so visitors without IPv6 can't open it. Turn on Cloudflare's proxy for coresmith.example.com to fix it. If you can forward ports 80 and 443 to 192.168.0.248, an A record pointing at 151.226.169.196 works too."], [reachabilityRecord('AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', note: REACHABILITY_BEST), reachabilityRecord('A', '151.226.169.196', note: REACHABILITY_IF_YOURS)]],
]);

test("a domain behind Cloudflare's proxy is never told to point back at this server", function () {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork(['104.21.3.4', '172.67.181.49', '2606:4700:3033::ac43:b831'], ['127.0.0.1', '192.168.0.248', $v6], [$v6]);
    $probe = reachabilityProbe(new DirectReach('151.226.169.196', false, false), new HostReach($v6, false, false, false));

    $report = app(Reachability::class)->check('beelink.coresmith.io');

    expect($report->ready)->toBeNull()
        ->and($report->problems)->toBe([])
        ->and($report->notes)->toBe(["beelink.coresmith.io goes through Cloudflare's proxy, so Coresmith can't test it from outside. In Cloudflare, point beelink.coresmith.io at this server and set SSL/TLS to Full (strict)."])
        ->and($report->records)->toBe([reachabilityRecord('AAAA', $v6, 'beelink.coresmith.io')])
        ->and($probe->asked)->toBe([]);
});

test("only the address family Cloudflare's proxy carries is left untested", function () {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork(['104.21.3.4', $v6], ['127.0.0.1', '192.168.0.248', $v6], [$v6]);
    $probe = reachabilityProbe(new DirectReach('151.226.169.196', false, false), new HostReach($v6, true, true, true));

    $report = app(Reachability::class)->check('beelink.coresmith.io');

    expect($report->ready)->toBeTrue()
        ->and($report->problems)->toBe([])
        ->and($report->notes)->toBe(["beelink.coresmith.io goes through Cloudflare's proxy, so Coresmith can't test it from outside. In Cloudflare, point beelink.coresmith.io at this server and set SSL/TLS to Full (strict)."])
        ->and($probe->asked)->toBe([['beelink.coresmith.io', $v6]]);
});

test('when the reach check itself cannot be asked, coresmith says so and does not block', function () {
    reachabilityNetwork(['151.226.169.196']);
    reachabilityProbe(null);

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->ready)->toBeNull()
        ->and($report->problems)->toBe([])
        ->and($report->notes)->toBe(["Coresmith couldn't ask get.coresmith.io whether coresmith.example.com can be reached from the internet, so it didn't check."]);
});
