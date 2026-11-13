<?php

declare(strict_types=1);

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
        ->and($report->notes)->toBe(["Inside your own network, your router may not loop back to 151.226.169.196. If coresmith.example.com doesn't load there, add 192.168.0.248 coresmith.example.com to your local DNS or /etc/hosts."]);
});

function reachabilityRecord(string $type, string $value, string $name = 'coresmith.example.com'): array
{
    return ['type' => $type, 'name' => $name, 'value' => $value];
}

test('a domain with no records yet says which record to add, ready to copy', function (?DirectReach $direct, array $stable, string $problem, array $records) {
    reachabilityNetwork([], ['127.0.0.1', '192.168.0.248'], $stable);
    reachabilityProbe($direct);

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->ready)->toBeFalse()
        ->and($report->problems)->toBe([$problem])
        ->and($report->records)->toBe($records);
})->with([
    'reachable over IPv4' => [new DirectReach('151.226.169.196', true, true), [], "coresmith.example.com doesn't point anywhere yet. Add an A record for coresmith.example.com pointing at 151.226.169.196, then check again.", [reachabilityRecord('A', '151.226.169.196')]],
    'behind a router, with IPv6' => [new DirectReach('151.226.169.196', false, false), ['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], "coresmith.example.com doesn't point anywhere yet, and nothing on the internet reaches this server's IPv4 address, 151.226.169.196, on ports 80 and 443. Either your router isn't forwarding those ports to 192.168.0.248, or your internet provider shares that address with other customers, as many home connections do. If you can forward them, add an A record for coresmith.example.com pointing at 151.226.169.196. If you can't, add an AAAA record for coresmith.example.com pointing at 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8. That reaches visitors with IPv6; to reach everyone else as well, put coresmith.example.com behind a proxy that answers on IPv4, such as Cloudflare's. Then check again.", [reachabilityRecord('A', '151.226.169.196'), reachabilityRecord('AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8')]],
    'behind a router, no IPv6' => [new DirectReach('151.226.169.196', false, false), [], "coresmith.example.com doesn't point anywhere yet, and nothing on the internet reaches this server's IPv4 address, 151.226.169.196, on ports 80 and 443. Forward TCP ports 80 and 443 on your router to 192.168.0.248, then add an A record for coresmith.example.com pointing at 151.226.169.196. If your internet provider shares that address with other customers, this server needs an IPv6 address before anyone can reach it.", [reachabilityRecord('A', '151.226.169.196')]],
    'nothing known' => [null, [], "coresmith.example.com doesn't point anywhere yet. Add a DNS record that points it at this server, then check again.", []],
]);

test('a domain with no records on a server with a public address of its own says to open its firewall', function (array $stable, string $problem, array $records) {
    reachabilityNetwork([], ['127.0.0.1', '151.226.169.196'], $stable);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->problems)->toBe([$problem])
        ->and($report->records)->toBe($records);
})->with([
    'with IPv6' => [['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], "coresmith.example.com doesn't point anywhere yet, and nothing on the internet reaches this server's IPv4 address, 151.226.169.196, on ports 80 and 443. Open ports 80 and 443 in your provider's firewall and add an A record for coresmith.example.com pointing at 151.226.169.196, or add an AAAA record for coresmith.example.com pointing at 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8, which only reaches visitors with IPv6. Then check again.", [reachabilityRecord('A', '151.226.169.196'), reachabilityRecord('AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8')]],
    'no IPv6' => [[], "coresmith.example.com doesn't point anywhere yet, and nothing on the internet reaches this server's IPv4 address, 151.226.169.196, on ports 80 and 443. Open ports 80 and 443 in your provider's firewall, then add an A record for coresmith.example.com pointing at 151.226.169.196.", [reachabilityRecord('A', '151.226.169.196')]],
]);

test('a domain that points at another address says which one this server has', function () {
    reachabilityNetwork(['198.51.100.7']);
    reachabilityProbe(new DirectReach('151.226.169.196', true, true));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->problems)->toBe(['coresmith.example.com points at 198.51.100.7, but this server\'s public address is 151.226.169.196. Point it at 151.226.169.196, then check again.'])
        ->and($report->records)->toBe([reachabilityRecord('A', '151.226.169.196')]);
});

test('behind a router that forwards nothing, the problem names the ports, the machine and the IPv6 way out', function (array $stable, string $problem, array $records) {
    reachabilityNetwork(['151.226.169.196'], ['127.0.0.1', '192.168.0.248'], $stable);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false));

    $report = app(Reachability::class)->check('coresmith.example.com');

    expect($report->ready)->toBeFalse()
        ->and($report->problems)->toBe([$problem])
        ->and($report->records)->toBe($records);
})->with([
    'no IPv6' => [[], 'coresmith.example.com points at 151.226.169.196, but nothing reaches this server on ports 80 and 443 from the internet. Forward TCP ports 80 and 443 on your router to 192.168.0.248, then check again. If your internet provider shares that address with other customers, this server needs an IPv6 address before anyone can reach it.', []],
    'with IPv6' => [['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], 'coresmith.example.com points at 151.226.169.196, but nothing reaches this server on ports 80 and 443 from the internet. Forward TCP ports 80 and 443 on your router to 192.168.0.248, then check again. If you can\'t, or your internet provider shares that address with other customers, use IPv6: add an AAAA record for coresmith.example.com pointing at 2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8, remove the A record, and allow ports 80 and 443 to it in your router\'s IPv6 firewall. To reach visitors without IPv6 too, put coresmith.example.com behind a proxy that answers on IPv4, such as Cloudflare\'s.', [reachabilityRecord('AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8')]],
]);

test('a server with its own public address that the internet cannot reach is told to open its provider firewall', function () {
    reachabilityNetwork(['203.0.113.10'], ['127.0.0.1', '203.0.113.10']);
    reachabilityProbe(new DirectReach('203.0.113.10', false, false));

    expect(app(Reachability::class)->check('coresmith.example.com')->problems)
        ->toBe(["coresmith.example.com points at 203.0.113.10, but nothing reaches this server on ports 80 and 443 from the internet. Open ports 80 and 443 in your provider's firewall, then check again."]);
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
        ->toBe(["beelink.coresmith.io points at {$v6}, but nothing reaches it on ports 80 and 443 from the internet. Home routers often block incoming IPv6, so allow ports 80 and 443 to {$v6} in your router's IPv6 firewall, then check again."]);
});

test('an IPv6 record for another address says which one this server has', function () {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork(['2a06:5902:360e:2700::99'], ['127.0.0.1', '192.168.0.248', $v6], [$v6]);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false));

    $report = app(Reachability::class)->check('beelink.coresmith.io');

    expect($report->problems)->toBe(["beelink.coresmith.io points at 2a06:5902:360e:2700::99, but this server's IPv6 address is {$v6}. Point it at {$v6}, then check again."])
        ->and($report->records)->toBe([reachabilityRecord('AAAA', $v6, 'beelink.coresmith.io')]);
});

test('an A record nothing reaches beside a working IPv6 record is ready, with a note to remove the A record', function () {
    $v6 = '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8';
    reachabilityNetwork(['151.226.169.196', $v6], ['127.0.0.1', '192.168.0.248', $v6], [$v6]);
    reachabilityProbe(new DirectReach('151.226.169.196', false, false), new HostReach($v6, true, true, true));

    $report = app(Reachability::class)->check('beelink.coresmith.io');

    expect($report->ready)->toBeTrue()
        ->and($report->notes)->toBe(["The A record for beelink.coresmith.io points at 151.226.169.196, which the internet can't reach on ports 80 and 443. Remove it so visitors without IPv6 aren't left waiting. To reach them, put beelink.coresmith.io behind a proxy that answers on IPv4, such as Cloudflare's."])
        ->and($report->records)->toBe([]);
});

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
    'its IPv4 address takes visitors' => [['127.0.0.1', '203.0.113.10'], new DirectReach('203.0.113.10', true, true), 'beelink.coresmith.io has only an IPv6 address, so visitors on networks without IPv6 can\'t open it. The internet reaches this server\'s IPv4 address too, so add an A record for beelink.coresmith.io pointing at 203.0.113.10.', [reachabilityRecord('A', '203.0.113.10', 'beelink.coresmith.io')]],
    'its IPv4 address is closed or shared' => [['127.0.0.1', '192.168.0.248'], new DirectReach('151.226.169.196', false, false), 'beelink.coresmith.io has only an IPv6 address, so visitors on networks without IPv6 can\'t open it. Nothing on the internet reaches this server\'s IPv4 address, 151.226.169.196, on ports 80 and 443, so if you can open or forward those ports, add an A record for beelink.coresmith.io pointing at 151.226.169.196. If your internet provider shares that address with other customers, put beelink.coresmith.io behind a proxy that answers on IPv4, such as Cloudflare\'s.', [reachabilityRecord('A', '151.226.169.196', 'beelink.coresmith.io')]],
    'its IPv4 address is unknown' => [['127.0.0.1', '192.168.0.248'], null, 'beelink.coresmith.io has only an IPv6 address, so visitors on networks without IPv6 can\'t open it. To reach them, add an A record for this server\'s IPv4 address if the internet reaches it on ports 80 and 443, or put beelink.coresmith.io behind a proxy that answers on IPv4, such as Cloudflare\'s.', []],
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
