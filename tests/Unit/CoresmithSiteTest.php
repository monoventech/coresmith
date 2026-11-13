<?php

declare(strict_types=1);

use App\Services\Caddy\CaddyInstaller;
use App\Services\Caddy\CoresmithSite;

function coresmithSiteJson(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__) . "/Fixtures/Caddy/adapted-{$name}.json");
}

function coresmithSiteObjects(string $name): stdClass
{
    return json_decode(coresmithSiteJson($name), false, 512, JSON_THROW_ON_ERROR);
}

function coresmithSiteFrom(string $name): CoresmithSite
{
    return CoresmithSite::fromAdapted(coresmithSiteJson($name));
}

function coresmithSiteServer(stdClass $adapted, string $listen): ?stdClass
{
    foreach ((array) $adapted->apps->http->servers as $server) {
        if ($server->listen === [$listen]) {
            return $server;
        }
    }

    return null;
}

function coresmithSiteEncode(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
}

test("Coresmith's address and its redirects become one route that names every host Coresmith answers on", function () {
    $routes = coresmithSiteServer(coresmithSiteObjects('public'), ':443')->routes;
    $site = coresmithSiteEncode(coresmithSiteFrom('public')->siteRoute());

    expect($site)->toBe(coresmithSiteEncode([
        '@id' => 'coresmith-site',
        'match' => [['host' => ['coresmith.example.com', 'old.example.net']]],
        'handle' => [
            ['handler' => 'vars', 'access_logger_names' => ['coresmith']],
            ['handler' => 'subroute', 'routes' => [$routes[0], $routes[1]]],
        ],
        'terminal' => true,
    ]))
        ->and($site)->toContain('"gzip":{}')
        ->and($site)->toContain('"zstd":{}');
});

test('the catch-all keeps serving team domains, logged with Coresmith', function () {
    $routes = coresmithSiteServer(coresmithSiteObjects('internal'), ':443')->routes;

    expect(coresmithSiteEncode(coresmithSiteFrom('internal')->anyRoute()))->toBe(coresmithSiteEncode([
        '@id' => 'coresmith-any',
        'handle' => [
            ['handler' => 'vars', 'access_logger_names' => ['coresmith']],
            ...$routes[1]->handle,
        ],
        'terminal' => true,
    ]));
});

test('the listener for the ask and release endpoints stays a server of its own on loopback', function () {
    expect(coresmithSiteEncode(coresmithSiteFrom('internal')->internalServer()))
        ->toBe(coresmithSiteEncode(coresmithSiteServer(coresmithSiteObjects('internal'), '127.0.0.1:8484')));
});

test("Coresmith's certificates keep their issuers, and the on-demand catch-all is a policy of its own", function () {
    $public = coresmithSiteFrom('public');
    $issuers = [
        ['email' => 'ops@example.com', 'module' => 'acme'],
        ['ca' => 'https://acme.zerossl.com/v2/DV90', 'email' => 'ops@example.com', 'module' => 'acme'],
    ];

    expect(coresmithSiteEncode($public->sitePolicies()))->toBe(coresmithSiteEncode([['@id' => 'coresmith-site-tls', 'subjects' => ['coresmith.example.com', 'old.example.net'], 'issuers' => $issuers]]))
        ->and(coresmithSiteEncode($public->anyPolicy()))->toBe(coresmithSiteEncode(['@id' => 'coresmith-any-tls', 'issuers' => $issuers, 'on_demand' => true]))
        ->and(coresmithSiteEncode($public->onDemand()))->toBe('{"permission":{"endpoint":"http://127.0.0.1:8484/api/caddy/ask","module":"http"}}')
        ->and(coresmithSiteEncode(coresmithSiteFrom('internal')->sitePolicies()))->toBe('[{"@id":"coresmith-site-tls","subjects":["coresmith.internal"]}]');
});

test("Coresmith's access log keeps its file and rolling, so fail2ban still reads its sign-ins", function () {
    expect(coresmithSiteEncode(coresmithSiteFrom('public')->logger()))
        ->toBe('{"writer":{"filename":"/var/log/caddy/coresmith-panel.log","output":"file","roll_keep":5,"roll_size_mb":10},"encoder":{"format":"json"},"include":["http.log.access.coresmith"]}');
});

test("the admin socket keeps the mode that lets Coresmith's group in", function () {
    expect(coresmithSiteEncode(coresmithSiteFrom('internal')->admin()))->toBe('{"listen":"unix//run/caddy-admin/admin.sock|0222"}');
});

test("a reading that doesn't look like Coresmith's site is refused rather than half used", function (Closure $break, string $missing) {
    $adapted = coresmithSiteObjects('internal');
    $break($adapted);

    expect(fn() => CoresmithSite::fromAdapted(coresmithSiteEncode($adapted)))->toThrow(RuntimeException::class, "The web server's settings for Coresmith have no {$missing}. Run the installer again to put them back.");
})->with([
    'no https listener' => [function (stdClass $adapted) {
        foreach ((array) $adapted->apps->http->servers as $name => $server) {
            if ($server->listen === [':443']) {
                unset($adapted->apps->http->servers->{$name});
            }
        }
    }, 'server on :443'],
    'no catch-all' => [function (stdClass $adapted) {
        coresmithSiteServer($adapted, ':443')->routes = [coresmithSiteServer($adapted, ':443')->routes[0]];
    }, 'catch-all route'],
    'no internal listener' => [function (stdClass $adapted) {
        foreach ((array) $adapted->apps->http->servers as $name => $server) {
            if ($server->listen === ['127.0.0.1:8484']) {
                unset($adapted->apps->http->servers->{$name});
            }
        }
    }, 'listener on 127.0.0.1:8484'],
    'no on-demand policy' => [function (stdClass $adapted) {
        $adapted->apps->tls->automation->policies = [$adapted->apps->tls->automation->policies[0]];
    }, 'on-demand certificate policy'],
    'no access log' => [function (stdClass $adapted) {
        $adapted->logging->logs = (object) ['default' => $adapted->logging->logs->default];
    }, 'access log'],
]);

test("something that isn't JSON is refused", function () {
    expect(fn() => CoresmithSite::fromAdapted('caddy: not found'))->toThrow(RuntimeException::class, CoresmithSite::UNREADABLE);
});

function coresmithSiteComposed(string $current = '', ?string $email = 'ops@example.com', string $site = 'public'): string
{
    return (new CaddyInstaller)->thisMachineConfig(coresmithSiteFrom($site), $email, $current);
}

function coresmithSiteCurrent(): string
{
    $previous = json_decode(coresmithSiteComposed('', 'ops@example.com', 'internal'), false, 512, JSON_THROW_ON_ERROR);
    array_splice($previous->apps->http->servers->coresmith->routes, 1, 0, [
        json_decode('{"@id":"app-shop","match":[{"host":["shop.example.com","www.shop.example.com"]}],"handle":[{"handler":"subroute","routes":[{"handle":[{"handler":"encode","encodings":{"gzip":{}}}]}]}],"terminal":true}'),
        json_decode('{"@id":"app-blog","match":[{"host":["blog.example.com"]}],"handle":[{"handler":"static_response","body":"blog"}],"terminal":true}'),
    ]);
    array_unshift($previous->apps->tls->automation->policies, json_decode('{"@id":"app-shop-tls","subjects":["old-shop.example.com"]}'));

    return (string) json_encode($previous, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

test("on Coresmith's machine its site comes first, the apps after it and its catch-all last, with the internal listener beside them", function () {
    $config = json_decode(coresmithSiteComposed(coresmithSiteCurrent()), true);
    $servers = $config['apps']['http']['servers'];

    expect(array_keys($servers))->toBe(['coresmith', 'coresmith-internal'])
        ->and(array_column($servers['coresmith']['routes'], '@id'))->toBe(['coresmith-site', 'app-shop', 'app-blog', 'coresmith-any'])
        ->and($servers['coresmith']['routes'][0]['match'])->toBe([['host' => ['coresmith.example.com', 'old.example.net']]])
        ->and($servers['coresmith']['listen'])->toBe([':443'])
        ->and($servers['coresmith']['tls_connection_policies'])->toBe([['protocol_min' => 'tls1.2']])
        ->and($servers['coresmith-internal']['listen'])->toBe(['127.0.0.1:8484']);
});

test("the composed config keeps every empty object Caddy needs, in Coresmith's site and in the apps", function () {
    $json = coresmithSiteComposed(coresmithSiteCurrent());

    expect(substr_count($json, '"gzip": {}'))->toBe(3)
        ->and(substr_count($json, '"zstd": {}'))->toBe(2)
        ->and($json)->toContain('"logs": {}');
});

test("apps on Coresmith's machine get certificates without asking Coresmith, ahead of its on-demand catch-all", function () {
    $policies = json_decode(coresmithSiteComposed(coresmithSiteCurrent()), true)['apps']['tls']['automation']['policies'];
    $issuers = [
        ['module' => 'acme', 'email' => 'ops@example.com'],
        ['module' => 'acme', 'ca' => 'https://acme.zerossl.com/v2/DV90', 'email' => 'ops@example.com'],
    ];

    expect(array_column($policies, '@id'))->toBe(['app-shop-tls', 'app-blog-tls', 'coresmith-site-tls', 'coresmith-any-tls'])
        ->and($policies[0])->toBe(['@id' => 'app-shop-tls', 'subjects' => ['shop.example.com', 'www.shop.example.com'], 'issuers' => $issuers])
        ->and(collect($policies)->reject(fn(array $policy) => isset($policy['subjects']))->keys()->all())->toBe([3])
        ->and(json_decode(coresmithSiteComposed(), true)['apps']['tls']['automation']['on_demand'])->toBe(['permission' => ['endpoint' => 'http://127.0.0.1:8484/api/caddy/ask', 'module' => 'http']]);
});

test("without an email for certificates, apps' policies name only their hosts and use Caddy's issuers", function () {
    $policies = json_decode(coresmithSiteComposed(coresmithSiteCurrent(), null), true)['apps']['tls']['automation']['policies'];

    expect($policies[1])->toBe(['@id' => 'app-blog-tls', 'subjects' => ['blog.example.com']]);
});

test('apps keep logging where metrics read them, and Coresmith keeps its own log', function () {
    $logs = json_decode(coresmithSiteComposed(), true)['logging']['logs'];

    expect(array_keys($logs))->toBe(['default', 'access', 'coresmith'])
        ->and($logs['default'])->toBe(['exclude' => ['http.log.access']])
        ->and($logs['access'])->toBe([
            'writer' => ['output' => 'file', 'filename' => '/var/log/caddy/access.log'],
            'encoder' => ['format' => 'json'],
            'include' => ['http.log.access'],
            'exclude' => ['http.log.access.coresmith'],
        ])
        ->and($logs['coresmith']['include'])->toBe(['http.log.access.coresmith']);
});

test("Coresmith's machine keeps its admin socket open to Coresmith's group", function () {
    expect(json_decode(coresmithSiteComposed(), true)['admin'])->toBe(['listen' => 'unix//run/caddy-admin/admin.sock|0222']);
});

test("a first config on Coresmith's machine has only Coresmith's routes and policies", function () {
    $config = json_decode(coresmithSiteComposed(''), true);

    expect(array_column($config['apps']['http']['servers']['coresmith']['routes'], '@id'))->toBe(['coresmith-site', 'coresmith-any'])
        ->and(array_column($config['apps']['tls']['automation']['policies'], '@id'))->toBe(['coresmith-site-tls', 'coresmith-any-tls']);
});
