<?php

declare(strict_types=1);

use App\Services\Network\DirectReach;
use App\Services\Network\HostReach;
use App\Services\Network\HttpReachProbe;
use App\Services\Network\ProcStableAddresses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['coresmith.address.reach_url' => 'https://get.coresmith.io/v1/reach']);
});

test('the direct check reads which of ports 80 and 443 answered at the address the request came from', function () {
    Http::fake(['get.coresmith.io/*' => Http::response(['address' => '151.226.169.196', 'family' => 4, 'ports' => ['80' => true, '443' => false]])]);

    expect(app(HttpReachProbe::class)->direct())->toEqual(new DirectReach('151.226.169.196', true, false));

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://get.coresmith.io/v1/reach');
});

test('the host check asks about the domain and reads what http and https answered from outside', function () {
    Http::fake(['get.coresmith.io/*' => Http::response(['address' => '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', 'http' => ['reachable' => true, 'status' => 308], 'https' => ['reachable' => false, 'status' => null]])]);

    expect(app(HttpReachProbe::class)->host('beelink.coresmith.io', '2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8'))
        ->toEqual(new HostReach('2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', true, true, false));

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://get.coresmith.io/v1/reach?host=beelink.coresmith.io');
});

test('a domain that points somewhere else comes back as not pointing here', function () {
    Http::fake(['get.coresmith.io/*' => Http::response(['error' => "beelink.coresmith.io doesn't point at the address this check came from.", 'address' => '2a06::1'], 409)]);

    expect(app(HttpReachProbe::class)->host('beelink.coresmith.io', '2a06::1'))->toEqual(new HostReach('2a06::1', false, false, false));
});

test('when get.coresmith.io cannot answer, the check knows nothing rather than guessing', function (Closure $answer) {
    Http::fake(['get.coresmith.io/*' => $answer]);

    expect(app(HttpReachProbe::class)->direct())->toBeNull()
        ->and(app(HttpReachProbe::class)->host('beelink.coresmith.io', '2a06::1'))->toBeNull();
})->with([
    'rate limited' => fn() => fn() => Http::response(['error' => 'Too many checks from this address. Try again in a minute.'], 429),
    'broken' => fn() => fn() => Http::response('', 500),
    'unreachable' => fn() => fn() => throw new ConnectionException('Could not resolve host'),
]);

test('with no reach address configured, nothing is sent', function () {
    config(['coresmith.address.reach_url' => '']);
    Http::fake();

    expect(app(HttpReachProbe::class)->direct())->toBeNull()
        ->and(app(HttpReachProbe::class)->host('beelink.coresmith.io', '2a06::1'))->toBeNull();

    Http::assertNothingSent();
});

test('only stable global ipv6 addresses are offered, never temporary, private, link-local or deprecated ones', function () {
    $file = tempnam(sys_get_temp_dir(), 'coresmith-inet6-');
    file_put_contents($file, implode("\n", [
        '2a065902360e27009ec7d3fffeb832b8 02 40 00 00   wlp3s0',
        '2a065902360e2700fc7dd29bd6433577 02 40 00 01   wlp3s0',
        'fd3116239c0169459ec7d3fffeb832b8 02 40 00 00   wlp3s0',
        'fe800000000000009ec7d3fffeb832b8 02 40 20 80   wlp3s0',
        '2a065902360e2700000000000000abcd 02 40 00 20   wlp3s0',
        '2a065902360e27000000000000000080 02 80 00 80   wlp3s0',
        '00000000000000000000000000000001 01 80 10 80       lo',
    ]) . "\n");

    try {
        expect((new ProcStableAddresses($file))->ipv6())->toBe(['2a06:5902:360e:2700:9ec7:d3ff:feb8:32b8', '2a06:5902:360e:2700::80'])
            ->and((new ProcStableAddresses($file . '-missing'))->ipv6())->toBe([]);
    } finally {
        unlink($file);
    }
});
