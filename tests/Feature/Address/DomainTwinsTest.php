<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Services\Address\DomainTwins;
use App\Services\Address\PublicSuffix;

function twinsApp(array $attributes = [], ?Server $server = null): Application
{
    $server ??= Server::factory()->create();

    return Application::factory()->for($server->team)->for($server)->create(['additional_domains' => [], 'current_release' => '20261117-120000', ...$attributes]);
}

test('an apex domain and its www name are each other\'s twin, and other names have none', function (string $domain, ?string $twin) {
    expect(app(DomainTwins::class)->twin($domain))->toBe($twin);
})->with([
    'apex' => ['example.com', 'www.example.com'],
    'www' => ['www.example.com', 'example.com'],
    'upper case and a trailing dot' => ['Example.COM.', 'www.example.com'],
    'subdomain' => ['app.example.com', null],
    'www under a subdomain' => ['www.app.example.com', null],
    'two-part suffix apex' => ['example.co.uk', 'www.example.co.uk'],
    'two-part suffix www' => ['www.example.co.uk', 'example.co.uk'],
    'two-part suffix subdomain' => ['shop.example.co.uk', null],
    'a public suffix on its own' => ['co.uk', null],
    'a top-level domain' => ['com', null],
    'wildcard' => ['*.example.com', null],
    'not a domain' => ['not a domain', null],
    'a name under a wildcard suffix rule' => ['example.ck', null],
    'an exception to a wildcard suffix rule' => ['www.ck', 'www.www.ck'],
    'an internationalised name' => ['xn--bcher-kva.de', 'www.xn--bcher-kva.de'],
    'a name under github.io, a suffix only the private section lists' => ['someone.github.io', null],
]);

test('the public suffix list reads its unicode rules as punycode', function () {
    expect(app(PublicSuffix::class)->registrable('shop.xn--e1afmkfd.xn--p1ai'))->toBe('xn--e1afmkfd.xn--p1ai')
        ->and(app(PublicSuffix::class)->registrable('a.b.example.co.uk'))->toBe('example.co.uk')
        ->and(app(PublicSuffix::class)->registrable('co.uk'))->toBeNull();
});

test('an app gets the twin of each apex or www domain it has', function () {
    $application = twinsApp(['domain' => 'example.com', 'additional_domains' => ['www.other.org', 'app.example.com']]);

    expect(app(DomainTwins::class)->for($application))->toBe(['www.example.com' => 'example.com', 'other.org' => 'www.other.org']);
});

test('an app that already has both names, in any case, or a wildcard over the www name, gets no twin', function (array $domains) {
    $application = twinsApp(['domain' => $domains[0], 'additional_domains' => array_slice($domains, 1)]);

    expect(app(DomainTwins::class)->for($application))->toBe([]);
})->with([
    'both names' => [['example.com', 'www.example.com']],
    'both names in another case' => [['Example.com', 'WWW.example.com']],
    'a wildcard over www' => [['example.com', '*.example.com']],
]);

test('a twin another app on the same server already uses, by name, as an extra domain or under a wildcard, stays with that app', function (array $other) {
    $application = twinsApp(['domain' => 'example.com']);
    twinsApp($other, $application->server);

    expect(app(DomainTwins::class)->for($application))->toBe([]);
})->with([
    'its main domain' => [['domain' => 'www.example.com']],
    'an extra domain' => [['domain' => 'blog.example.org', 'additional_domains' => ['www.example.com']]],
    'a wildcard' => [['domain' => '*.example.com']],
]);

test("a twin another of the team's apps uses on another server stays with that app", function () {
    $application = twinsApp(['domain' => 'example.com']);
    twinsApp(['domain' => 'www.example.com'], Server::factory()->for($application->team)->create());

    expect(app(DomainTwins::class)->for($application))->toBe([]);
});

test("another team's app on another server can't take an app's twin away", function () {
    $application = twinsApp(['domain' => 'example.com']);
    twinsApp(['domain' => 'www.example.com']);

    expect(app(DomainTwins::class)->for($application))->toBe(['www.example.com' => 'example.com']);
});

test("a removed app's domains no longer hold a twin back", function () {
    $application = twinsApp(['domain' => 'example.com']);
    twinsApp(['domain' => 'www.example.com'], $application->server)->delete();

    expect(app(DomainTwins::class)->for($application))->toBe(['www.example.com' => 'example.com']);
});

test("Coresmith's own address and a team's custom domain are never taken as a twin", function () {
    config(['app.url' => 'https://www.example.com']);
    $application = twinsApp(['domain' => 'example.com', 'additional_domains' => ['example.org']]);
    TeamDomain::query()->create(['team_id' => Team::factory()->create()->getKey(), 'hostname' => 'www.example.org', 'verification_token' => 'token']);

    expect(app(DomainTwins::class)->for($application))->toBe([]);
});

test('the apps whose twin a name takes or frees are the deployed ones nearby that own its other half', function () {
    $owner = twinsApp(['domain' => 'example.com']);
    twinsApp(['domain' => 'www.example.org', 'current_release' => null], $owner->server);
    twinsApp(['domain' => 'example.net']);
    $changing = twinsApp(['domain' => 'shop.example.io'], $owner->server);
    $twins = app(DomainTwins::class);

    expect($twins->owners(['www.example.com'], $changing)->modelKeys())->toBe([$owner->getKey()])
        ->and($twins->owners(['*.example.com'], $changing)->modelKeys())->toBe([$owner->getKey()])
        ->and($twins->owners(['app.example.com'], $changing)->modelKeys())->toBe([])
        ->and($twins->owners(['example.org'], $changing)->modelKeys())->toBe([])
        ->and($twins->owners(['www.example.net'], $changing)->modelKeys())->toBe([])
        ->and($twins->owners(['www.example.com'], $owner)->modelKeys())->toBe([]);
});
