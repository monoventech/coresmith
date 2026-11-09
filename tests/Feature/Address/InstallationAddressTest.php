<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamDomain;
use App\Services\Address\CertificateSource;
use App\Services\Address\InstallationAddress;
use App\Services\Network\AddressResolver;
use App\Services\Network\LocalAddresses;

function addressRulesFor(array $dns, array $local = ['127.0.0.1', '192.168.0.248']): InstallationAddress
{
    app()->instance(AddressResolver::class, new class($dns) implements AddressResolver
    {
        public function __construct(private readonly array $records) {}

        public function addresses(string $host): array
        {
            return $this->records[$host] ?? [];
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

    return app(InstallationAddress::class);
}

beforeEach(fn() => config(['app.url' => 'https://192.168.0.248']));

test('what people paste is reduced to the host', function (string $input, string $host) {
    expect(InstallationAddress::normalise($input))->toBe($host);
})->with([
    'a full address' => ['https://Coresmith.Example.com/settings', 'coresmith.example.com'],
    'spaces and a trailing dot' => ['  coresmith.example.com.  ', 'coresmith.example.com'],
    'with a port' => ['coresmith.example.com:443', 'coresmith.example.com'],
    'an ip address' => ['192.168.0.248', '192.168.0.248'],
    'an ipv6 address in brackets' => ['https://[fd00::248]/', 'fd00::248'],
]);

test('each address is served with the certificate Caddy can get for it', function (string $host, CertificateSource $source) {
    expect(InstallationAddress::certificate($host))->toBe($source);
})->with([
    'a public domain' => ['coresmith.example.com', CertificateSource::Public],
    'a tailscale name' => ['beelink.tail1234.ts.net', CertificateSource::Tailscale],
    'an internal name' => ['coresmith.internal', CertificateSource::Internal],
    'a home network name' => ['coresmith.home.arpa', CertificateSource::Internal],
    'a local name' => ['beelink.local', CertificateSource::Internal],
    'localhost' => ['localhost', CertificateSource::Internal],
    'an ip address' => ['192.168.0.248', CertificateSource::Internal],
]);

test('the current address is the host coresmith links to', function () {
    config(['app.url' => 'https://coresmith.example.com']);

    expect(addressRulesFor([])->host())->toBe('coresmith.example.com');
});

test('only an install whose site file coresmith can write manages its own address', function () {
    config(['coresmith.address.site' => '']);
    expect(addressRulesFor([])->managed())->toBeFalse();

    config(['coresmith.address.site' => '/etc/caddy/coresmith/panel.caddy']);
    expect(addressRulesFor([])->managed())->toBeTrue();
});

test('an address that would not work is refused with what to do', function (string $host, array $dns, string $refusal) {
    expect(addressRulesFor($dns)->refusal($host))->toBe($refusal);
})->with([
    'the current one' => ['192.168.0.248', [], "That is already Coresmith's address."],
    'nothing' => ['', [], 'Enter the address Coresmith should answer on, like coresmith.example.com.'],
    'a single word' => ['beelink', [], "That doesn't look like a domain. Try coresmith.example.com."],
    'not a name' => ['core smith.example.com', [], "That doesn't look like a domain. Try coresmith.example.com."],
    'a wildcard' => ['*.example.com', [], "That doesn't look like a domain. Try coresmith.example.com."],
    'an ip of another machine' => ['192.168.0.9', [], "192.168.0.9 isn't one of this server's addresses."],
    'a name with no records' => ['coresmith.example.com', [], "coresmith.example.com doesn't point anywhere yet. Add a DNS record that points it at this server, then try again."],
    'a name for another machine on the network' => ['coresmith.example.com', ['coresmith.example.com' => ['192.168.0.9']], "coresmith.example.com points at 192.168.0.9, which isn't this server. Point it at this server, then try again."],
]);

test('a name another team already opens on is refused', function () {
    TeamDomain::factory()->for(Team::factory())->create(['hostname' => 'coresmith.example.com']);

    expect(addressRulesFor(['coresmith.example.com' => ['203.0.113.40']])->refusal('coresmith.example.com'))
        ->toBe('A team already opens on coresmith.example.com. Remove it from that team first.');
});

test('an address that reaches this server is accepted', function (string $host, array $dns) {
    expect(addressRulesFor($dns, ['127.0.0.1', '192.168.0.248', '100.101.102.103'])->refusal($host))->toBeNull();
})->with([
    'a name for this machine' => ['coresmith.home.arpa', ['coresmith.home.arpa' => ['192.168.0.248']]],
    'a public name, perhaps through a router' => ['coresmith.example.com', ['coresmith.example.com' => ['93.184.215.14']]],
    'its tailscale name' => ['beelink.tail1234.ts.net', ['beelink.tail1234.ts.net' => ['100.101.102.103']]],
    'another of its own addresses' => ['100.101.102.103', []],
]);
