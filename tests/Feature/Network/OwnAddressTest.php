<?php

declare(strict_types=1);

use App\Services\Network\LocalAddresses;
use App\Services\Network\OwnAddress;

function ownAddress(array $local): OwnAddress
{
    return new OwnAddress(new class($local) implements LocalAddresses
    {
        public function __construct(private readonly array $addresses) {}

        public function all(): array
        {
            return $this->addresses;
        }
    });
}

test("an address on one of this machine's interfaces, any loopback address, or the any address is this machine", function (string $address) {
    expect(ownAddress(['127.0.0.1', '::1', '192.168.0.248', '2a02:c7c:c0e:b500::248'])->is($address))->toBeTrue();
})->with(['192.168.0.248', '2a02:c7c:c0e:b500:0:0:0:248', '127.0.0.1', '127.0.1.1', '::1', '0.0.0.0', '::']);

test('every other address is somewhere else', function (string $address) {
    expect(ownAddress(['127.0.0.1', '::1', '192.168.0.248'])->is($address))->toBeFalse();
})->with(['192.168.0.249', '203.0.113.10', '0.0.0.1', '2001:db8::1', 'not an address', '']);
