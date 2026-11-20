<?php

declare(strict_types=1);

use App\Exceptions\CloudflareRefused;
use App\Services\Address\CloudflareDns;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const FRONT_DOOR_DNS_API = 'https://api.cloudflare.test/client/v4';

const FRONT_DOOR_DNS_OWN = ['151.226.169.196', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'];

const FRONT_DOOR_DNS_DOOR = [['type' => 'A', 'value' => '203.0.113.9'], ['type' => 'AAAA', 'value' => '2001:db8::9']];

beforeEach(fn() => config(['coresmith.address.cloudflare_api' => FRONT_DOOR_DNS_API]));

function frontDoorDnsWith(array $records, bool $refuseAddresses = false): void
{
    Http::fake(function (Request $request) use ($records, $refuseAddresses) {
        return match (true) {
            str_contains($request->url(), '/dns_records?') => Http::response(['success' => true, 'errors' => [], 'result' => $records]),
            $refuseAddresses && $request->method() === 'POST' && ($request->data()['content'] ?? null) === '203.0.113.9' => Http::response(['success' => false, 'errors' => [['code' => 81057, 'message' => 'Record already exists.']], 'result' => null], 400),
            default => Http::response(['success' => true, 'errors' => [], 'result' => ['id' => 'new']]),
        };
    });
}

function frontDoorDnsPosted(): array
{
    return Http::recorded(fn(Request $request): bool => $request->method() === 'POST')->map(fn(array $pair): array => [$pair[0]->data()['type'], $pair[0]->data()['content'], $pair[0]->data()['proxied']])->values()->all();
}

function frontDoorDnsDeleted(): array
{
    return Http::recorded(fn(Request $request): bool => $request->method() === 'DELETE')->map(fn(array $pair): string => basename($pair[0]->url()))->values()->all();
}

test("a domain that points at the home server is pointed at the door instead, over IPv4 and IPv6 and without Cloudflare's proxy", function () {
    frontDoorDnsWith([['id' => 'r6', 'type' => 'AAAA', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'], ['id' => 'txt', 'type' => 'TXT', 'content' => 'v=spf1 -all']]);

    expect(app(CloudflareDns::class)->pointAtDoor('cf-token', 'z1', 'pencil.example', FRONT_DOOR_DNS_DOOR, FRONT_DOOR_DNS_OWN))->toBeNull()
        ->and(frontDoorDnsDeleted())->toBe(['r6'])
        ->and(frontDoorDnsPosted())->toBe([['A', '203.0.113.9', false], ['AAAA', '2001:db8::9', false]]);
});

test('a domain already pointed at the door is left as it is', function () {
    frontDoorDnsWith([['id' => 'a', 'type' => 'A', 'content' => '203.0.113.9'], ['id' => 'b', 'type' => 'AAAA', 'content' => '2001:db8::9']]);

    expect(app(CloudflareDns::class)->pointAtDoor('cf-token', 'z1', 'pencil.example', FRONT_DOOR_DNS_DOOR, FRONT_DOOR_DNS_OWN))->toBeNull();

    Http::assertNotSent(fn(Request $request): bool => $request->method() !== 'GET');
});

test('a domain that points somewhere else is left alone, with the reason', function (array $records, string $reason) {
    frontDoorDnsWith($records);

    expect(app(CloudflareDns::class)->pointAtDoor('cf-token', 'z1', 'pencil.example', FRONT_DOOR_DNS_DOOR, FRONT_DOOR_DNS_OWN))->toBe($reason);

    Http::assertNotSent(fn(Request $request): bool => $request->method() !== 'GET');
})->with([
    'another address' => [[['id' => 'x', 'type' => 'A', 'content' => '198.51.100.7']], "pencil.example points at 198.51.100.7, which isn't this server, so Coresmith left it alone."],
    'an alias' => [[['id' => 'c', 'type' => 'CNAME', 'content' => 'shop.other.example']], 'pencil.example points at shop.other.example, so Coresmith left it alone.'],
]);

test('a domain with no records is only pointed when Coresmith may create them', function () {
    frontDoorDnsWith([]);

    expect(app(CloudflareDns::class)->pointAtDoor('cf-token', 'z1', 'pencil.example', FRONT_DOOR_DNS_DOOR, FRONT_DOOR_DNS_OWN, create: false))->toBe('');

    Http::assertNotSent(fn(Request $request): bool => $request->method() !== 'GET');
});

test('when Cloudflare refuses a door record, the home records come back', function () {
    frontDoorDnsWith([['id' => 'r6', 'type' => 'AAAA', 'content' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', 'ttl' => 300]], refuseAddresses: true);

    expect(fn() => app(CloudflareDns::class)->pointAtDoor('cf-token', 'z1', 'pencil.example', FRONT_DOOR_DNS_DOOR, FRONT_DOOR_DNS_OWN))->toThrow(CloudflareRefused::class)
        ->and(frontDoorDnsPosted())->toContain(['AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', false]);
});

test("pointing back removes the door's records and puts back the home server's open addresses", function () {
    frontDoorDnsWith([['id' => 'a', 'type' => 'A', 'content' => '203.0.113.9'], ['id' => 'b', 'type' => 'AAAA', 'content' => '2001:db8::9'], ['id' => 'txt', 'type' => 'TXT', 'content' => 'v=spf1 -all']]);

    app(CloudflareDns::class)->pointBack('cf-token', 'z1', 'pencil.example', ['203.0.113.9', '2001:db8::9'], [['type' => 'AAAA', 'value' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']]);

    expect(frontDoorDnsDeleted())->toBe(['a', 'b'])
        ->and(frontDoorDnsPosted())->toBe([['AAAA', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', false]]);
});

test('pointing back a domain the door never held changes nothing', function () {
    frontDoorDnsWith([['id' => 'x', 'type' => 'A', 'content' => '198.51.100.7']]);

    app(CloudflareDns::class)->pointBack('cf-token', 'z1', 'pencil.example', ['203.0.113.9'], [['type' => 'AAAA', 'value' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8']]);

    Http::assertNotSent(fn(Request $request): bool => $request->method() !== 'GET');
});
