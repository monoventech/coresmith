<?php

declare(strict_types=1);

use App\Exceptions\CloudflareRefused;
use App\Services\Address\CloudflareDns;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TUNNEL_DNS_API = 'https://api.cloudflare.test/client/v4';

const TUNNEL_DNS_TARGET = 'f00d.cfargotunnel.com';

const TUNNEL_DNS_OWN = ['151.226.169.196', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'];

beforeEach(fn() => config(['coresmith.address.cloudflare_api' => TUNNEL_DNS_API]));

function tunnelDnsAnswer(mixed $result): array
{
    return ['success' => true, 'errors' => [], 'result' => $result];
}

function tunnelDnsWith(array $records, bool $refuseAlias = false): void
{
    Http::fake(function (Request $request) use ($records, $refuseAlias) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/dns_records?') => Http::response(tunnelDnsAnswer(str_contains($url, 'type=CNAME') ? array_values(array_filter($records, fn(array $record): bool => $record['type'] === 'CNAME')) : $records)),
            $refuseAlias && $request->method() === 'POST' && ($request->data()['type'] ?? null) === 'CNAME' => Http::response(['success' => false, 'errors' => [['code' => 81053, 'message' => 'An A, AAAA, or CNAME record with that host already exists.']], 'result' => null], 400),
            default => Http::response(tunnelDnsAnswer(['id' => 'new'])),
        };
    });
}

function tunnelDnsSent(string $method, ?string $type = null): array
{
    return Http::recorded(fn(Request $request): bool => $request->method() === $method && ($type === null || ($request->data()['type'] ?? null) === $type))->values()->all();
}

test('a domain with no records gets a proxied CNAME to the tunnel', function () {
    tunnelDnsWith([]);

    expect(app(CloudflareDns::class)->routeToTunnel('cf-token', 'z1', 'pencil.example', TUNNEL_DNS_TARGET, TUNNEL_DNS_OWN))->toBeNull();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['type' => 'CNAME', 'name' => 'pencil.example', 'content' => TUNNEL_DNS_TARGET, 'ttl' => 1, 'proxied' => true, 'comment' => CloudflareDns::COMMENT]);
});

test("records that point at this server are replaced by the tunnel's CNAME", function () {
    tunnelDnsWith([
        ['id' => 'r4', 'type' => 'A', 'content' => '151.226.169.196'],
        ['id' => 'r6', 'type' => 'AAAA', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'],
        ['id' => 'txt', 'type' => 'TXT', 'content' => 'v=spf1 -all'],
    ]);

    expect(app(CloudflareDns::class)->routeToTunnel('cf-token', 'z1', 'pencil.example', TUNNEL_DNS_TARGET, TUNNEL_DNS_OWN))->toBeNull();

    $deleted = array_map(fn(array $pair): string => basename($pair[0]->url()), tunnelDnsSent('DELETE'));

    expect($deleted)->toBe(['r4', 'r6'])
        ->and(tunnelDnsSent('POST', 'CNAME'))->toHaveCount(1);
});

test('a record that points somewhere else is left alone, and nothing changes', function () {
    tunnelDnsWith([['id' => 'r4', 'type' => 'A', 'content' => '198.51.100.9']]);

    expect(app(CloudflareDns::class)->routeToTunnel('cf-token', 'z1', 'pencil.example', TUNNEL_DNS_TARGET, TUNNEL_DNS_OWN))
        ->toBe("pencil.example points at 198.51.100.9, which isn't this server, so Coresmith left it alone.")
        ->and(tunnelDnsSent('DELETE'))->toBe([])
        ->and(tunnelDnsSent('POST'))->toBe([]);
});

test('a CNAME already at the tunnel only has its proxy turned on', function () {
    tunnelDnsWith([['id' => 'c1', 'type' => 'CNAME', 'content' => TUNNEL_DNS_TARGET, 'proxied' => false]]);

    expect(app(CloudflareDns::class)->routeToTunnel('cf-token', 'z1', 'pencil.example', TUNNEL_DNS_TARGET, TUNNEL_DNS_OWN))->toBeNull();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/dns_records/c1') && $request->data() === ['proxied' => true]);
    expect(tunnelDnsSent('POST'))->toBe([]);
});

test('a CNAME to somewhere else is left alone', function () {
    tunnelDnsWith([['id' => 'c1', 'type' => 'CNAME', 'content' => 'pages.example.net', 'proxied' => true]]);

    expect(app(CloudflareDns::class)->routeToTunnel('cf-token', 'z1', 'pencil.example', TUNNEL_DNS_TARGET, TUNNEL_DNS_OWN))
        ->toBe('pencil.example points at pages.example.net, so Coresmith left it alone.')
        ->and(tunnelDnsSent('PATCH'))->toBe([]);
});

test('a www twin with no records is skipped quietly instead of being created', function () {
    tunnelDnsWith([]);

    expect(app(CloudflareDns::class)->routeToTunnel('cf-token', 'z1', 'www.pencil.example', TUNNEL_DNS_TARGET, TUNNEL_DNS_OWN, create: false))->toBe('')
        ->and(tunnelDnsSent('POST'))->toBe([]);
});

test('when Cloudflare refuses the CNAME, the records it replaced come back', function () {
    tunnelDnsWith([['id' => 'r6', 'type' => 'AAAA', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', 'proxied' => false, 'ttl' => 300]], refuseAlias: true);

    expect(fn() => app(CloudflareDns::class)->routeToTunnel('cf-token', 'z1', 'pencil.example', TUNNEL_DNS_TARGET, TUNNEL_DNS_OWN))
        ->toThrow(CloudflareRefused::class, 'Cloudflare refused the change: An A, AAAA, or CNAME record with that host already exists.');

    $restored = array_map(fn(array $pair): array => $pair[0]->data(), tunnelDnsSent('POST', 'AAAA'));

    expect($restored)->toBe([['type' => 'AAAA', 'name' => 'pencil.example', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', 'ttl' => 300, 'proxied' => false, 'comment' => CloudflareDns::COMMENT]]);
});

test("turning off removes only the tunnel's CNAME and points the domain back at the server", function () {
    tunnelDnsWith([
        ['id' => 'c1', 'type' => 'CNAME', 'content' => TUNNEL_DNS_TARGET, 'proxied' => true],
    ]);

    app(CloudflareDns::class)->routeAway('cf-token', 'z1', 'pencil.example', TUNNEL_DNS_TARGET, [['type' => 'AAAA', 'value' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']]);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/c1'));
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['type' => 'AAAA', 'name' => 'pencil.example', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', 'ttl' => 1, 'proxied' => false, 'comment' => CloudflareDns::COMMENT]);
});

test('turning off leaves a CNAME that someone pointed elsewhere', function () {
    tunnelDnsWith([['id' => 'c1', 'type' => 'CNAME', 'content' => 'pages.example.net', 'proxied' => true]]);

    app(CloudflareDns::class)->routeAway('cf-token', 'z1', 'pencil.example', TUNNEL_DNS_TARGET, [['type' => 'AAAA', 'value' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']]);

    expect(tunnelDnsSent('DELETE'))->toBe([])
        ->and(tunnelDnsSent('POST'))->toBe([]);
});
