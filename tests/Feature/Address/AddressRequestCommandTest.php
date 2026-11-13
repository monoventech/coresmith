<?php

declare(strict_types=1);

use App\Services\Address\AddressChange;
use App\Services\Address\AddressRequest;
use App\Services\Network\AddressResolver;
use App\Services\Network\DirectReach;
use App\Services\Network\HostReach;
use App\Services\Network\LocalAddresses;
use App\Services\Network\ReachProbe;
use App\Services\Network\StableAddresses;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;

function addressRequestNetwork(?DirectReach $direct): void
{
    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['151.226.169.196'];
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

beforeEach(function () {
    $this->addressState = sys_get_temp_dir() . '/coresmith-address-request-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->addressState);
    config([
        'app.url' => 'https://192.168.0.248',
        'coresmith.address.site' => '/etc/caddy/coresmith/panel.caddy',
        'coresmith.address.state_path' => $this->addressState,
    ]);
});

afterEach(fn() => File::deleteDirectory($this->addressState));

test('a domain the internet cannot reach is refused with what to fix, and no switch is asked for', function () {
    addressRequestNetwork(new DirectReach('151.226.169.196', false, false));

    expect(Artisan::call('coresmith:address-request', ['host' => 'coresmith.example.com']))->toBe(1)
        ->and(Artisan::output())->toBe("coresmith.example.com points at 151.226.169.196, but nothing reaches this server on ports 80 and 443 from the internet. Forward TCP ports 80 and 443 on your router to 192.168.0.248, then check again. If your internet provider shares that address with other customers, this server needs an IPv6 address before visitors can reach it.\n")
        ->and(AddressChange::current())->toBeNull()
        ->and(file_exists(AddressRequest::file()))->toBeFalse();
});

test('a domain that passes is handed to the address service, with the notes after', function () {
    addressRequestNetwork(new DirectReach('151.226.169.196', true, true));

    expect(Artisan::call('coresmith:address-request', ['host' => 'coresmith.example.com']))->toBe(0)
        ->and(Artisan::output())->toBe("Switching to coresmith.example.com.\nInside your own network, your router may not loop back to 151.226.169.196. If coresmith.example.com doesn't load there, add 192.168.0.248 coresmith.example.com to your local DNS or /etc/hosts.\n")
        ->and(AddressChange::current())->toMatchArray(['state' => 'running', 'from' => '192.168.0.248', 'to' => 'coresmith.example.com'])
        ->and(AddressRequest::take())->toBe('coresmith.example.com');
});

test('waiting, it reports the switch the address service finished', function (Closure $service, int $code, string $line) {
    addressRequestNetwork(new DirectReach('151.226.169.196', true, true));
    Sleep::fake();
    Sleep::whenFakingSleep($service);

    expect(Artisan::call('coresmith:address-request', ['host' => 'coresmith.example.com', '--wait' => true]))->toBe($code)
        ->and(Artisan::output())->toContain($line);
})->with([
    'moved' => [fn() => AddressChange::finish(), 0, 'Coresmith now answers on coresmith.example.com.'],
    'failed' => [fn() => AddressChange::fail('No certificate for coresmith.example.com after 3 minutes.'), 1, 'No certificate for coresmith.example.com after 3 minutes.'],
]);

test('waiting, a request the address service never picks up is withdrawn after half a minute, so it cannot move Coresmith later', function () {
    addressRequestNetwork(new DirectReach('151.226.169.196', true, true));
    Sleep::fake();
    $reason = "Coresmith's address service didn't pick up the move to coresmith.example.com. Check it with systemctl status coresmith-address.path, then try again.";

    expect(Artisan::call('coresmith:address-request', ['host' => 'coresmith.example.com', '--wait' => true]))->toBe(1)
        ->and(Artisan::output())->toContain($reason)
        ->and(file_exists(AddressRequest::file()))->toBeFalse()
        ->and(AddressChange::current())->toMatchArray(['state' => 'failed', 'error' => $reason]);
    Sleep::assertSleptTimes(15);
});

test('waiting, a move the address service took but never finished is given up on after ten minutes, so Settings can try again', function () {
    addressRequestNetwork(new DirectReach('151.226.169.196', true, true));
    Sleep::fake();
    Sleep::whenFakingSleep(fn() => is_file(AddressRequest::file()) && rename(AddressRequest::file(), AddressRequest::takenFile()));
    $reason = "Coresmith's move to coresmith.example.com didn't finish in 10 minutes. Check what happened with journalctl -u coresmith-address, then try again.";

    expect(Artisan::call('coresmith:address-request', ['host' => 'coresmith.example.com', '--wait' => true]))->toBe(1)
        ->and(Artisan::output())->toContain($reason)
        ->and(AddressRequest::take())->toBe('coresmith.example.com')
        ->and(AddressChange::current())->toMatchArray(['state' => 'failed', 'error' => $reason]);
    Sleep::assertSleptTimes(300);
});
