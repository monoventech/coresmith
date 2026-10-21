<?php

declare(strict_types=1);

use App\Services\Git\GitHub\PanelReachability;
use App\Services\Git\GitHub\Reachability;
use App\Services\Network\AddressResolver;
use Illuminate\Support\Facades\Cache;

function reachabilityResolving(array $map): void
{
    app()->instance(AddressResolver::class, new class($map) implements AddressResolver
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private readonly array $map) {}

        public function addresses(string $host): array
        {
            $this->asked[] = $host;

            return $this->map[$host] ?? [];
        }
    });
}

function reachabilityAsked(): array
{
    return app(AddressResolver::class)->asked;
}

test('the panel address is judged by what github could do with it', function (string $url, array $resolves, bool $reachable, ?string $reason, ?string $sentence) {
    config(['app.url' => $url]);
    reachabilityResolving($resolves);

    $verdict = app(PanelReachability::class)->verdict();

    expect($verdict)->toBeInstanceOf(Reachability::class)
        ->and($verdict->reachable)->toBe($reachable)
        ->and($verdict->reason)->toBe($reason)
        ->and($verdict->sentence())->toBe($sentence);
})->with([
    'a public name' => ['https://panel.example.com', ['panel.example.com' => ['140.82.112.3']], true, null, null],
    'a public name behind a private address' => ['https://panel.example.com', ['panel.example.com' => ['192.168.0.10']], false, 'private', 'GitHub cannot reach panel.example.com, 192.168.0.10 is not a public address.'],
    'a private address' => ['https://192.168.0.248', [], false, 'private', 'GitHub cannot reach 192.168.0.248, it is not a public address.'],
    'a shared address space address' => ['https://100.64.1.9', [], false, 'private', 'GitHub cannot reach 100.64.1.9, it is not a public address.'],
    'a unique local ipv6 address' => ['https://[fd00::1]', [], false, 'private', 'GitHub cannot reach fd00::1, it is not a public address.'],
    'a loopback address with a port' => ['https://127.0.0.1:8123', [], false, 'loopback', 'GitHub cannot reach 127.0.0.1, it only answers on this machine.'],
    'a loopback address anywhere in 127.0.0.0/8' => ['https://127.4.5.6', [], false, 'loopback', 'GitHub cannot reach 127.4.5.6, it only answers on this machine.'],
    'the ipv6 loopback address' => ['https://[::1]', [], false, 'loopback', 'GitHub cannot reach ::1, it only answers on this machine.'],
    'localhost over http' => ['http://localhost', [], false, 'loopback', 'GitHub cannot reach localhost, it only answers on this machine.'],
    'a localhost subdomain' => ['https://panel.localhost', [], false, 'loopback', 'GitHub cannot reach panel.localhost, it only answers on this machine.'],
    'a name that resolves to loopback' => ['https://panel.example.com', ['panel.example.com' => ['127.0.0.1']], false, 'loopback', 'GitHub cannot reach panel.example.com, it only answers on this machine.'],
    'a mdns name' => ['https://beelink.local', [], false, 'local', 'GitHub cannot reach beelink.local, it only resolves on your own network.'],
    'a lan name' => ['https://box.lan', [], false, 'local', 'GitHub cannot reach box.lan, it only resolves on your own network.'],
    'a home.arpa name' => ['https://nas.home.arpa', [], false, 'local', 'GitHub cannot reach nas.home.arpa, it only resolves on your own network.'],
    'a single label' => ['https://beelink', [], false, 'local', 'GitHub cannot reach beelink, it only resolves on your own network.'],
    'a public ip' => ['https://140.82.112.3', [], false, 'ip', 'GitHub cannot check a certificate for 140.82.112.3. Give the panel a domain name to get pushes as they happen.'],
    'a public ipv6 address' => ['https://[2606:4700::1111]', [], false, 'ip', 'GitHub cannot check a certificate for 2606:4700::1111. Give the panel a domain name to get pushes as they happen.'],
    'plain http' => ['http://panel.example.com', ['panel.example.com' => ['140.82.112.3']], false, 'http', 'This panel is served over http, and GitHub only delivers to https.'],
    'a name that does not resolve' => ['https://unresolvable.example.com', [], true, null, null],
    'a name that resolves to a mix' => ['https://panel.example.com', ['panel.example.com' => ['192.168.0.10', '140.82.112.3']], true, null, null],
    'upper case and a trailing dot' => ['https://Panel.Example.COM.', ['panel.example.com' => ['140.82.112.3']], true, null, null],
    'a private literal beats plain http' => ['http://192.168.0.5', [], false, 'private', 'GitHub cannot reach 192.168.0.5, it is not a public address.'],
    'a public literal beats plain http' => ['http://140.82.112.3', [], false, 'ip', 'GitHub cannot check a certificate for 140.82.112.3. Give the panel a domain name to get pushes as they happen.'],
    'a private resolution beats plain http' => ['http://panel.example.com', ['panel.example.com' => ['10.0.0.7']], false, 'private', 'GitHub cannot reach panel.example.com, 10.0.0.7 is not a public address.'],
    'loopback beats plain http' => ['http://127.0.0.1', [], false, 'loopback', 'GitHub cannot reach 127.0.0.1, it only answers on this machine.'],
    'a trailing newline on a loopback address' => ["https://127.0.0.1\n", [], false, 'loopback', 'GitHub cannot reach 127.0.0.1, it only answers on this machine.'],
    'a trailing newline on a private address' => ["https://192.168.0.248\n", [], false, 'private', 'GitHub cannot reach 192.168.0.248, it is not a public address.'],
    'a trailing newline on a local name' => ["https://beelink.local\n", [], false, 'local', 'GitHub cannot reach beelink.local, it only resolves on your own network.'],
    'a trailing newline on a public name' => ["https://panel.example.com\n", ['panel.example.com' => ['140.82.112.3']], true, null, null],
]);

test('names that only resolve on a private network are never looked up', function (string $host) {
    config(['app.url' => "https://{$host}"]);
    reachabilityResolving([$host => ['140.82.112.3']]);

    $verdict = app(PanelReachability::class)->verdict();

    expect($verdict->reachable)->toBeFalse()
        ->and($verdict->reason)->toBe('local')
        ->and($verdict->host)->toBe($host)
        ->and(reachabilityAsked())->toBe([]);
})->with(['panel.local', 'panel.lan', 'panel.home', 'panel.home.arpa', 'panel.internal', 'panel.localdomain', 'panel.test', 'panel.invalid', 'panel.example', 'home.arpa', 'single']);

test('an address that cannot be read as a host counts as this machine and is never looked up', function (string $url) {
    config(['app.url' => $url]);
    reachabilityResolving(['panel.example.com' => ['140.82.112.3'], '127.0.0.1' => ['140.82.112.3']]);

    $verdict = app(PanelReachability::class)->verdict();

    expect($verdict->reachable)->toBeFalse()
        ->and($verdict->reason)->toBe('loopback')
        ->and($verdict->host)->toBe('')
        ->and($verdict->sentence())->toBe('GitHub cannot reach this panel, it only answers on this machine.')
        ->and(reachabilityAsked())->toBe([]);
})->with([
    'an empty address' => [''],
    'a bare word' => ['panel'],
    'a newline inside the address' => ["https://127.0.0.1\n:8123"],
    'a newline inside a name' => ["https://panel.example.com\n.evil.com"],
    'a carriage return inside the address' => ["https://127.0.0.1\r:8123"],
    'a tab inside the address' => ["https://127.0.0.1\t:8123"],
    'an encoded newline in the host' => ['https://127.0.0.1%0A'],
    'a space in the host' => ['https://pa nel.example.com'],
    'a backslash in the address' => ['https://127.0.0.1\\@panel.example.com'],
]);

test('a public name that resolves publicly is looked up once and keeps its host', function () {
    config(['app.url' => 'https://panel.example.com:8443/base']);
    reachabilityResolving(['panel.example.com' => ['140.82.112.3']]);

    $verdict = app(PanelReachability::class)->verdict();

    expect($verdict->host)->toBe('panel.example.com')
        ->and($verdict->address)->toBeNull()
        ->and(reachabilityAsked())->toBe(['panel.example.com']);
});

test('a private verdict remembers the first address it found', function () {
    config(['app.url' => 'https://panel.example.com']);
    reachabilityResolving(['panel.example.com' => ['192.168.0.10', '10.0.0.4']]);

    $verdict = app(PanelReachability::class)->verdict();

    expect($verdict->reason)->toBe('private')
        ->and($verdict->address)->toBe('192.168.0.10')
        ->and($verdict->sentence())->toBe('GitHub cannot reach panel.example.com, 192.168.0.10 is not a public address.');
});

test('an explicit address is judged instead of the configured one', function () {
    config(['app.url' => 'https://panel.example.com']);
    reachabilityResolving(['panel.example.com' => ['140.82.112.3']]);

    $verdict = app(PanelReachability::class)->verdict('http://localhost');

    expect($verdict->reason)->toBe('loopback')
        ->and($verdict->host)->toBe('localhost');
});

test('the verdict is remembered for ten minutes, because the page asks on every render and the answer needs a lookup', function () {
    config(['app.url' => 'https://panel.example.com']);
    reachabilityResolving(['panel.example.com' => ['140.82.112.3']]);
    $reachability = app(PanelReachability::class);

    $first = $reachability->verdict();

    $this->travel(9)->minutes();
    $second = $reachability->verdict();

    expect($first->reachable)->toBeTrue()
        ->and($second)->toEqual($first)
        ->and(reachabilityAsked())->toBe(['panel.example.com'])
        ->and(Cache::has('panel-reachability:' . sha1('https://panel.example.com')))->toBeTrue();

    $this->travel(2)->minutes();
    $reachability->verdict();

    expect(reachabilityAsked())->toBe(['panel.example.com', 'panel.example.com']);
});

test('each address is remembered on its own', function () {
    reachabilityResolving(['panel.example.com' => ['140.82.112.3'], 'other.example.com' => ['192.168.0.9']]);
    $reachability = app(PanelReachability::class);

    $first = $reachability->verdict('https://panel.example.com');
    $second = $reachability->verdict('https://other.example.com');

    expect($first->reachable)->toBeTrue()
        ->and($second->reason)->toBe('private')
        ->and(Cache::has('panel-reachability:' . sha1('https://panel.example.com')))->toBeTrue()
        ->and(Cache::has('panel-reachability:' . sha1('https://other.example.com')))->toBeTrue();
});

test('a remembered verdict survives a cache that serialises its values', function () {
    config(['cache.stores.array.serialize' => true, 'app.url' => 'https://panel.example.com']);
    Cache::forgetDriver();
    reachabilityResolving(['panel.example.com' => ['192.168.0.10']]);
    $reachability = app(PanelReachability::class);

    $first = $reachability->verdict();
    $second = $reachability->verdict();

    expect($second)->toBeInstanceOf(Reachability::class)
        ->and($second)->toEqual($first)
        ->and($second->sentence())->toBe('GitHub cannot reach panel.example.com, 192.168.0.10 is not a public address.')
        ->and(reachabilityAsked())->toBe(['panel.example.com']);
});

test('a remembered value that is not a verdict is judged again', function (mixed $garbage) {
    config(['app.url' => 'https://panel.example.com']);
    reachabilityResolving(['panel.example.com' => ['140.82.112.3']]);
    Cache::put('panel-reachability:' . sha1('https://panel.example.com'), $garbage, 600);

    $verdict = app(PanelReachability::class)->verdict();

    expect($verdict->reachable)->toBeTrue()
        ->and(reachabilityAsked())->toBe(['panel.example.com']);
})->with([
    'a string' => ['nonsense'],
    'an empty list' => [[]],
    'values of the wrong type' => [['reachable' => 'yes', 'host' => 5, 'reason' => null, 'address' => null]],
    'a reason that is not a string' => [['reachable' => false, 'host' => 'panel.example.com', 'reason' => 7, 'address' => null]],
]);

test('a verdict says nothing about itself when github can reach the panel', function () {
    $verdict = new Reachability(true, 'panel.example.com', null, null);

    expect($verdict->sentence())->toBeNull();
});

test('a private address that is the host itself reads as the short sentence', function () {
    expect((new Reachability(false, '10.0.0.1', 'private', '10.0.0.1'))->sentence())->toBe('GitHub cannot reach 10.0.0.1, it is not a public address.')
        ->and((new Reachability(false, '10.0.0.1', 'private', null))->sentence())->toBe('GitHub cannot reach 10.0.0.1, it is not a public address.');
});
