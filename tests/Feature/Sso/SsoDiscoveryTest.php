<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Services\Sso\SignIn;
use App\Services\Sso\SsoException;
use App\Services\Sso\UrlGuard;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/SsoHelpers.php';

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    ssoFakeNetwork();
});

test('start refuses a provider whose configuration names another issuer', function () {
    ssoConnection(ssoTeam());
    ssoFakeProvider(['issuer' => 'https://evil.example.test']);
    Log::spy();

    $this->get('/sso/acme/start')
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', SignIn::UNREACHABLE);

    Log::shouldHaveReceived('notice')->withArgs(fn(string $text, array $context = []): bool => ($context['reason'] ?? null) === 'discovery.issuer')->once();
});

test('start refuses a configuration whose endpoints are not safe to use', function (array $discovery) {
    ssoConnection(ssoTeam());
    ssoFakeProvider($discovery);

    $this->get('/sso/acme/start')
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', SignIn::UNREACHABLE);
})->with([
    'plain http authorization' => [['authorization_endpoint' => 'http://idp.example.test/authorize']],
    'token on loopback' => [['token_endpoint' => 'https://127.0.0.1/token']],
    'keys on cloud metadata' => [['jwks_uri' => 'https://169.254.169.254/keys']],
    'userinfo with a fragment' => [['userinfo_endpoint' => 'https://idp.example.test/userinfo#x']],
]);

test('one trailing slash on the issuer is tolerated but the token must name the provider issuer exactly', function () {
    $team = ssoTeam();
    ssoConnection($team, ['issuer' => 'https://idp.example.test/']);
    $member = ssoLinkedMember($team);
    ssoFakeProvider();

    ssoSignIn(['iss' => 'https://idp.example.test/'])->assertRedirect('http://localhost/acme/login');

    $this->assertGuest();

    ssoSignIn()->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);

    Http::assertNotSent(fn(HttpRequest $request): bool => str_contains($request->url(), '//.well-known'));
});

test('discovery and signing keys are cached between sign-ins', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team);
    $calls = ['discovery' => 0, 'jwks' => 0];

    Http::fake([
        'https://idp.example.test/.well-known/openid-configuration' => function () use (&$calls) {
            $calls['discovery']++;

            return Http::response(ssoDiscovery());
        },
        'https://idp.example.test/jwks' => function () use (&$calls) {
            $calls['jwks']++;

            return Http::response(ssoJwks());
        },
    ]);

    ssoSignIn()->assertRedirect('http://localhost/acme/dashboard');

    auth()->logout();

    ssoSignIn(['exp' => now()->addMinutes(3)->getTimestamp()])->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);

    expect($calls)->toBe(['discovery' => 1, 'jwks' => 1])
        ->and(Http::recorded(fn(HttpRequest $request): bool => $request->url() === 'https://idp.example.test/token'))->toHaveCount(1);
});

test('an unknown key id fetches the signing keys once more before giving up', function () {
    $team = ssoTeam();
    ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);
    $jwks = 0;

    Http::fake([
        'https://idp.example.test/.well-known/openid-configuration' => Http::response(ssoDiscovery()),
        'https://idp.example.test/jwks' => function () use (&$jwks) {
            $jwks++;

            return Http::response(ssoJwks());
        },
    ]);

    ssoSignIn([], ['kid' => 'rotated-in'])->assertRedirect('http://localhost/acme/login');

    $this->assertGuest();

    expect($jwks)->toBe(2);
});

test('a provider that stops resolving or now points inside is refused when it is contacted', function (array $addresses, string $reason) {
    ssoConnection(ssoTeam());
    ssoFakeProvider();
    ssoFakeNetwork(['idp.example.test' => $addresses]);
    Log::spy();

    $this->get('/sso/acme/start')
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', SignIn::UNREACHABLE);

    Http::assertNothingSent();

    Log::shouldHaveReceived('notice')->withArgs(fn(string $text, array $context = []): bool => ($context['reason'] ?? null) === $reason)->once();
})->with([
    'no address' => [[], 'url.unresolved'],
    'rebound to loopback' => [['203.0.113.10', '127.0.0.1'], 'url.blocked'],
    'rebound to a mapped address' => [['::ffff:169.254.169.254'], 'url.blocked'],
]);

test('requests to the provider are pinned to the addresses that were checked', function () {
    ssoFakeNetwork(['idp.example.test' => ['203.0.113.10', '2001:db8::10']]);
    $guard = app(UrlGuard::class);

    expect($guard->options('https://idp.example.test/token'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['idp.example.test:443:203.0.113.10,[2001:db8::10]']]])
        ->and($guard->options('https://idp.example.test:8443/realms/acme'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['idp.example.test:8443:203.0.113.10,[2001:db8::10]']]])
        ->and($guard->options('https://203.0.113.20/token'))->toBe([])
        ->and(fn() => $guard->options('https://127.0.0.1/token'))->toThrow(SsoException::class)
        ->and(fn() => $guard->options('http://idp.example.test/token'))->toThrow(SsoException::class)
        ->and($guard->refusal('https://idp.example.test/authorize?p=b2c_1_signin'))->toBeNull()
        ->and($guard->issuerRefusal('https://idp.example.test/realms/acme'))->toBeNull()
        ->and($guard->issuerRefusal('https://*.example.test'))->toBe('"*.example.test" is not a valid host name.')
        ->and($guard->issuerRefusal('https://idp.example.test\\@127.0.0.1'))->toBe('Use an https:// address, like https://login.example.com.');
});
