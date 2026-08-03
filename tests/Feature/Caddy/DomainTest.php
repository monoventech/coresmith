<?php

declare(strict_types=1);

use App\Exceptions\InvalidRouteConfigException;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\SafeName;

function domainApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'my-app',
        'domain' => 'app.example.com',
        'base_port' => 8000,
    ], $attributes));
}

test('an app can serve several domains from one route', function () {
    $app = domainApp(['additional_domains' => ['www.example.com', 'example.com']]);
    $route = (new CaddyRouteBuilder)->build($app);

    expect($route['match'][0]['host'])->toBe(['app.example.com', 'www.example.com', 'example.com']);
});

test('a duplicated domain is only listed once', function () {
    $app = domainApp(['additional_domains' => ['app.example.com']]);

    expect((new CaddyRouteBuilder)->domains($app))->toBe(['app.example.com']);
});

test('every domain on the app passes validation', function () {
    $app = domainApp(['additional_domains' => ['www.example.com']]);

    (new RouteConfigValidator)->validate((new CaddyRouteBuilder)->build($app), $app);
})->throwsNoExceptions();

test('a domain not belonging to the app is still refused', function () {
    $app = domainApp(['additional_domains' => ['www.example.com']]);
    $config = (new CaddyRouteBuilder)->build($app);
    $config['match'][0]['host'][] = 'victim.example.com';

    (new RouteConfigValidator)->validate($config, $app);
})->throws(InvalidRouteConfigException::class);

test('a malformed domain cannot be persisted', function (string $domain) {
    domainApp(['domain' => $domain]);
})->with([
    'evil.com, other.com',
    'app .com',
    '-bad.com',
    'app.com/../etc',
    'app.com; rm -rf /',
    'http://app.com',
])->throws(UnsafeNameException::class);

test('a malformed additional domain cannot be persisted', function () {
    domainApp(['additional_domains' => ['ok.example.com', 'not a domain']]);
})->throws(UnsafeNameException::class);

test('wildcard domains are accepted for dns challenge certificates', function () {
    expect(SafeName::isDomain('*.example.com'))->toBeTrue();
});

test('routes pin a modern tls floor', function () {
    expect((new CaddyRouteBuilder)->tlsPolicy())->toBe(['protocol' => ['minimum_version' => 'tls1.2']]);
});

test('responses carry the full hardening header set from the spec', function (string $header) {
    $route = (new CaddyRouteBuilder)->build(domainApp());
    $headers = collect($route['handle'][0]['routes'][1]['handle'])->firstWhere('handler', 'headers');

    expect($headers['response']['set'])->toHaveKey($header);
})->with([
    'X-Frame-Options',
    'X-Content-Type-Options',
    'X-XSS-Protection',
    'Referrer-Policy',
    'Permissions-Policy',
    'Strict-Transport-Security',
]);

test('the server and runtime fingerprint headers are removed', function () {
    $route = (new CaddyRouteBuilder)->build(domainApp());
    $headers = collect($route['handle'][0]['routes'][1]['handle'])->firstWhere('handler', 'headers');

    expect($headers['response']['deferred']['delete'])->toBe(['Server', 'X-Powered-By']);
});

test('hsts is set for a year with subdomains and preload', function () {
    $route = (new CaddyRouteBuilder)->build(domainApp());
    $headers = collect($route['handle'][0]['routes'][1]['handle'])->firstWhere('handler', 'headers');

    expect($headers['response']['set']['Strict-Transport-Security'])
        ->toBe(['max-age=31536000; includeSubDomains; preload']);
});
