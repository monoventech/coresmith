<?php

declare(strict_types=1);

use App\Services\Network\CloudflareRanges;

test("Cloudflare's published addresses are recognised in both families", function (string $address) {
    expect(CloudflareRanges::contains($address))->toBeTrue();
})->with(['104.21.3.4', '172.67.181.49', '173.245.48.1', '131.0.72.9', '2606:4700:3033::ac43:b831', '2a06:98c1:3120::3', '2400:cb00:1053:1000:2bbf:aab4:8f:78d2']);

test('every other address, and anything that is not an address, is not Cloudflare', function (string $address) {
    expect(CloudflareRanges::contains($address))->toBeFalse();
})->with(['203.0.113.7', '192.168.0.248', '172.72.0.1', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', '2a06:98c8::1', 'not-an-address', '']);

test('records count as proxied only when every one of them is a Cloudflare address', function () {
    expect(CloudflareRanges::containsAll(['104.21.3.4', '172.67.181.49']))->toBeTrue()
        ->and(CloudflareRanges::containsAll(['104.21.3.4', '203.0.113.7']))->toBeFalse()
        ->and(CloudflareRanges::containsAll([]))->toBeFalse();
});
