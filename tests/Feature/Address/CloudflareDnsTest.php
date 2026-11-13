<?php

declare(strict_types=1);

use App\Services\Address\CloudflareDns;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const CLOUDFLARE_DNS_API = 'https://api.cloudflare.test/client/v4';

beforeEach(fn() => config(['coresmith.address.cloudflare_api' => CLOUDFLARE_DNS_API]));

function cloudflareDnsAnswer(array $result): array
{
    return ['success' => true, 'errors' => [], 'result' => $result];
}

function cloudflareDnsRefusal(string $message, int $code = 9109): array
{
    return ['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'result' => null];
}

test('a token that is active and sees a zone is accepted', function () {
    Http::fake([
        CLOUDFLARE_DNS_API . '/user/tokens/verify' => Http::response(cloudflareDnsAnswer(['status' => 'active'])),
        CLOUDFLARE_DNS_API . '/zones?per_page=1' => Http::response(cloudflareDnsAnswer([['id' => 'z1', 'name' => 'coresmith.io']])),
    ]);

    expect(app(CloudflareDns::class)->refusal('good-token'))->toBeNull();

    Http::assertSent(fn(Request $request): bool => $request->hasHeader('Authorization', 'Bearer good-token'));
});

test('a token Cloudflare turns down, or one that sees no zone, is refused with what to do', function (array $verify, array $zones, string $refusal) {
    Http::fake([
        CLOUDFLARE_DNS_API . '/user/tokens/verify' => Http::response(...$verify),
        CLOUDFLARE_DNS_API . '/zones?per_page=1' => Http::response(cloudflareDnsAnswer($zones)),
    ]);

    expect(app(CloudflareDns::class)->refusal('a-token'))->toBe($refusal);
})->with([
    'not a token' => [[cloudflareDnsRefusal('Invalid API Token', 1000), 401], [], "Cloudflare doesn't accept that token. Create one in Cloudflare under My Profile, API Tokens, from the Edit zone DNS template, then paste it here."],
    'a revoked token' => [[cloudflareDnsAnswer(['status' => 'disabled'])], [], "Cloudflare doesn't accept that token. Create one in Cloudflare under My Profile, API Tokens, from the Edit zone DNS template, then paste it here."],
    'no zone in sight' => [[cloudflareDnsAnswer(['status' => 'active'])], [], "That token can't see any of your zones. Create one from the Edit zone DNS template, with your domain's zone under Zone Resources."],
]);

test("a host's zone is found by walking up its labels", function () {
    Http::fake([
        CLOUDFLARE_DNS_API . '/zones?name=beelink.home.coresmith.io' => Http::response(cloudflareDnsAnswer([])),
        CLOUDFLARE_DNS_API . '/zones?name=home.coresmith.io' => Http::response(cloudflareDnsAnswer([])),
        CLOUDFLARE_DNS_API . '/zones?name=coresmith.io' => Http::response(cloudflareDnsAnswer([['id' => 'z1', 'name' => 'coresmith.io']])),
    ]);

    expect(app(CloudflareDns::class)->zone('a-token', 'beelink.home.coresmith.io'))->toBe(['id' => 'z1', 'name' => 'coresmith.io']);
});

test('a host in none of the zones the token sees has no zone', function () {
    Http::fake([CLOUDFLARE_DNS_API . '/zones*' => Http::response(cloudflareDnsAnswer([]))]);

    expect(app(CloudflareDns::class)->zone('a-token', 'beelink.example.com'))->toBeNull();
});

test('pointing creates the record when there is none, unproxied and marked as managed by Coresmith', function () {
    Http::fake([
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records?type=AAAA&name=beelink.coresmith.io' => Http::response(cloudflareDnsAnswer([])),
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records' => Http::response(cloudflareDnsAnswer(['id' => 'r1'])),
    ]);

    app(CloudflareDns::class)->point('a-token', 'z1', 'beelink.coresmith.io', 'AAAA', '2a02:c7c:c0e:b500::248');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['type' => 'AAAA', 'name' => 'beelink.coresmith.io', 'content' => '2a02:c7c:c0e:b500::248', 'ttl' => 1, 'proxied' => false, 'comment' => 'Managed by Coresmith']);
});

test('pointing updates the first record that differs and deletes any further ones of the same type', function () {
    Http::fake([
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records?type=A&name=beelink.coresmith.io' => Http::response(cloudflareDnsAnswer([
            ['id' => 'r1', 'type' => 'A', 'content' => '151.226.169.196', 'proxied' => false],
            ['id' => 'r2', 'type' => 'A', 'content' => '198.51.100.9', 'proxied' => false],
        ])),
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records/*' => Http::response(cloudflareDnsAnswer(['id' => 'r1'])),
    ]);

    app(CloudflareDns::class)->point('a-token', 'z1', 'beelink.coresmith.io', 'A', '203.0.113.5');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/dns_records/r1') && $request->data()['content'] === '203.0.113.5');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/r2'));
});

test('a record that already says this server, written another way, is left alone', function () {
    Http::fake([
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records?type=AAAA&name=beelink.coresmith.io' => Http::response(cloudflareDnsAnswer([
            ['id' => 'r1', 'type' => 'AAAA', 'content' => '2a02:c7c:c0e:b500:0:0:0:248', 'proxied' => false],
        ])),
    ]);

    app(CloudflareDns::class)->point('a-token', 'z1', 'beelink.coresmith.io', 'AAAA', '2a02:c7c:c0e:b500::248');

    Http::assertSentCount(1);
});

test("a record someone put behind Cloudflare's proxy keeps it while it already says this server", function () {
    Http::fake([
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records?type=AAAA&name=beelink.coresmith.io' => Http::response(cloudflareDnsAnswer([
            ['id' => 'r1', 'type' => 'AAAA', 'content' => '2a02:c7c:c0e:b500::248', 'proxied' => true],
        ])),
    ]);

    app(CloudflareDns::class)->point('a-token', 'z1', 'beelink.coresmith.io', 'AAAA', '2a02:c7c:c0e:b500::248');

    Http::assertSentCount(1);
});

test("a proxied record that points elsewhere moves to this server and stays behind Cloudflare's proxy", function () {
    Http::fake([
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records?type=AAAA&name=beelink.coresmith.io' => Http::response(cloudflareDnsAnswer([
            ['id' => 'r1', 'type' => 'AAAA', 'content' => '2a02:c7c:c0e:b500::99', 'proxied' => true],
        ])),
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records/*' => Http::response(cloudflareDnsAnswer(['id' => 'r1'])),
    ]);

    app(CloudflareDns::class)->point('a-token', 'z1', 'beelink.coresmith.io', 'AAAA', '2a02:c7c:c0e:b500::248');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'PATCH'
        && $request->data()['content'] === '2a02:c7c:c0e:b500::248'
        && $request->data()['proxied'] === true);
});

test("Cloudflare's refusal reaches whoever asked, in its own words", function () {
    Http::fake([
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records?type=A&name=beelink.coresmith.io' => Http::response(cloudflareDnsAnswer([])),
        CLOUDFLARE_DNS_API . '/zones/z1/dns_records' => Http::response(cloudflareDnsRefusal('Authentication error', 10000), 403),
    ]);

    expect(fn() => app(CloudflareDns::class)->point('a-token', 'z1', 'beelink.coresmith.io', 'A', '203.0.113.5'))
        ->toThrow(RuntimeException::class, 'Cloudflare refused the change: Authentication error.');
});
