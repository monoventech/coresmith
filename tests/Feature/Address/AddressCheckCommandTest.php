<?php

declare(strict_types=1);

use App\Services\Network\AddressResolver;
use App\Services\Network\DirectReach;
use App\Services\Network\HostReach;
use App\Services\Network\LocalAddresses;
use App\Services\Network\ReachProbe;
use App\Services\Network\StableAddresses;
use Illuminate\Support\Facades\Artisan;

function addressCheckNetwork(array $records, ?DirectReach $direct): void
{
    app()->instance(AddressResolver::class, new class($records) implements AddressResolver
    {
        public function __construct(private readonly array $records) {}

        public function addresses(string $host): array
        {
            return $this->records;
        }
    });

    app()->instance(LocalAddresses::class, new class implements LocalAddresses
    {
        public function all(): array
        {
            return ['127.0.0.1', '192.168.0.248'];
        }
    });

    app()->instance(StableAddresses::class, new class implements StableAddresses
    {
        public function ipv6(): array
        {
            return [];
        }
    });

    app()->instance(ReachProbe::class, new class($direct) implements ReachProbe
    {
        public function __construct(private readonly ?DirectReach $reach) {}

        public function direct(): ?DirectReach
        {
            return $this->reach;
        }

        public function host(string $host, string $from): ?HostReach
        {
            return null;
        }
    });
}

test('a domain the internet reaches passes the check, with any note printed after', function () {
    addressCheckNetwork(['151.226.169.196'], new DirectReach('151.226.169.196', true, true));

    expect(Artisan::call('coresmith:address-check', ['host' => 'https://Coresmith.Example.com/']))->toBe(0)
        ->and(Artisan::output())->toBe("coresmith.example.com reaches this server from the internet.\nIf coresmith.example.com doesn't load inside your own network, add 192.168.0.248 coresmith.example.com to your local DNS or /etc/hosts.\n");
});

test('a domain the internet cannot reach fails the check and says what to fix', function () {
    addressCheckNetwork(['151.226.169.196'], new DirectReach('151.226.169.196', false, false));

    expect(Artisan::call('coresmith:address-check', ['host' => 'coresmith.example.com']))->toBe(1)
        ->and(Artisan::output())->toBe("coresmith.example.com points at 151.226.169.196, which doesn't answer on ports 80 and 443 from the internet. Add this machine to Coresmith as a server and turn on IPv4 through Cloudflare on its page, then check again. If 151.226.169.196 is yours alone, you can instead forward those ports to 192.168.0.248.\n");
});

test('when it cannot ask, the check says so without failing', function () {
    addressCheckNetwork(['151.226.169.196'], null);

    expect(Artisan::call('coresmith:address-check', ['host' => 'coresmith.example.com']))->toBe(0)
        ->and(Artisan::output())->toBe("Coresmith couldn't ask get.coresmith.io whether coresmith.example.com can be reached from the internet, so it didn't check.\n");
});
