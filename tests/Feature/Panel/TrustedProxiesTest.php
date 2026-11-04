<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\TemporaryEnvironment;

function trustedProxiesConfig(?string $value): array
{
    return TemporaryEnvironment::with(['TRUSTED_PROXIES' => $value], fn() => require base_path('config/trustedproxy.php'));
}

function trustedProxiesClientIp(?string $proxies, string $caller): string
{
    config(['trustedproxy' => trustedProxiesConfig($proxies)]);

    Route::get('/trusted-proxies-client', fn(Request $request) => $request->ip());

    return (string) test()->withServerVariables(['REMOTE_ADDR' => $caller])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/trusted-proxies-client')
        ->assertOk()
        ->getContent();
}

test('nothing is trusted when the setting is unset or empty', function (?string $proxies) {
    expect(trustedProxiesConfig($proxies)['proxies'])->toBeNull()
        ->and(trustedProxiesClientIp($proxies, '10.0.0.5'))->toBe('10.0.0.5');
})->with(['unset' => [null], 'empty' => ['']]);

test('a listed proxy passes on the address behind it', function (string $proxies) {
    expect(trustedProxiesClientIp($proxies, '10.0.0.5'))->toBe('203.0.113.9');
})->with([
    'one address' => ['10.0.0.5'],
    'a list with spaces' => ['10.0.0.9, 10.0.0.5'],
    'a range' => ['10.0.0.0/8'],
    'any address' => ['*'],
]);

test('a caller that is not on the list is not believed', function () {
    expect(trustedProxiesClientIp('10.0.0.5', '198.51.100.7'))->toBe('198.51.100.7');
});

test('the environment file names the setting, empty', function () {
    expect(file(base_path('.env.example'), FILE_IGNORE_NEW_LINES))->toContain('TRUSTED_PROXIES=');
});
