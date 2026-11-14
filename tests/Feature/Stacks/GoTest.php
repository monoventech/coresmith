<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Moves\MoveColumns;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Stacks\Go;
use App\Stacks\GoTooling;
use App\Stacks\Route;
use App\Stacks\StorageSeed;

function goApp(array $attributes = [], array $tooling = []): Application
{
    $data = (new GoTooling(['.' => 'shop', 'cmd/worker' => 'worker'], '.', false, null, [], null, false, null))->toArray();

    return new Application(['type' => ApplicationType::Go, 'framework' => 'gin', 'go_version' => '1.27.4', 'base_port' => 4400, 'stack' => ['language_tooling' => [...$data, ...$tooling]], ...$attributes]);
}

test('a Go app runs its own binary behind Caddy, with no PHP-FPM, no web root and no shared storage', function () {
    $language = ApplicationType::Go->language();

    expect($language)->toBeInstanceOf(Go::class)
        ->and(ApplicationType::Go->runtime())->toBe('go')
        ->and($language->usesFpm())->toBeFalse()
        ->and($language->route(goApp()))->toBe(Route::ReverseProxy)
        ->and($language->route(goApp())->servesFiles())->toBeFalse()
        ->and($language->storageSeed())->toBe(StorageSeed::None);
});

test('a gRPC service is proxied over HTTP/2, since gRPC needs it end to end', function () {
    $route = ApplicationType::Go->language()->route(goApp(tooling: ['grpc' => true]));

    expect($route)->toBe(Route::ReverseProxyHttp2)
        ->and($route->servesFiles())->toBeFalse()
        ->and($route->proxies())->toBeTrue();
});

test('a Go app listens on PORT, and on any other address or port variable its code reads', function () {
    expect(ApplicationType::Go->language()->listenEnvironment(goApp()))->toBe(['PORT' => '4400', 'HOST' => '127.0.0.1'])
        ->and(ApplicationType::Go->language()->listenEnvironment(goApp(tooling: ['listen' => ['LISTEN_ADDR' => 'address', 'SERVER_PORT' => 'port']])))
        ->toBe(['PORT' => '4400', 'HOST' => '127.0.0.1', 'LISTEN_ADDR' => '127.0.0.1:4400', 'SERVER_PORT' => '4400']);
});

test("a framework's production mode is set for its processes, and the app's environment can change it", function (string $framework, array $environment) {
    expect(ApplicationType::Go->language()->processEnvironment(goApp(['framework' => $framework])))->toBe($environment);
})->with([
    'Gin' => ['gin', ['GIN_MODE' => 'release']],
    'Beego' => ['beego', ['BEEGO_RUNMODE' => 'prod']],
    'Buffalo' => ['buffalo', ['GO_ENV' => 'production']],
    'Echo' => ['echo', []],
    'net/http' => ['net-http', []],
]);

test('the web process runs the web package binary from the live release, with the listen flag the app defines', function () {
    $web = ApplicationType::Go->language()->webCommand(goApp(['slug' => 'shop']));
    $flagged = ApplicationType::Go->language()->webCommand(goApp(['slug' => 'shop'], ['flag' => '-addr=127.0.0.1:${PORT}']));

    expect($web)->toEndWith('/.coresmith/bin/shop')
        ->and($flagged)->toEndWith('/.coresmith/bin/shop -addr=127.0.0.1:${PORT}');
});

test('every binary the app builds is on PATH for its processes and cron jobs, and the cron example names one', function () {
    expect(ApplicationType::Go->language()->pathDirectories('/var/coresmith/apps/shop/current'))->toBe(['/var/coresmith/apps/shop/current/.coresmith/bin'])
        ->and(ApplicationType::Go->language()->cronExample(goApp()))->toBe('worker')
        ->and(ApplicationType::Go->language()->cronExample(goApp(tooling: ['mains' => ['.' => 'shop']])))->toBe('go run ./cmd/cleanup');
});

test('a Go app keeps the Go version it was deployed with', function () {
    $stack = new Stack(['go' => new ResolvedRuntime('go', '1.26.11', 'go.mod', 'go 1.24', false)]);

    expect(goApp()->runtimeVersions())->toBe(['go' => '1.27.4'])
        ->and($stack->applicationAttributes()['go_version'])->toBe('1.26.11');
});

test('the app page names Go, its line and its framework', function () {
    expect(ApplicationPresenter::runtime(goApp()))->toBe('Go 1.27')
        ->and(ApplicationPresenter::kind(goApp()))->toBe('Gin')
        ->and(ApplicationPresenter::kind(goApp(['framework' => 'net-http'])))->toBe('net/http')
        ->and(ApplicationPresenter::kind(goApp(['framework' => null])))->toBe('Go');
});

test('a Go line past its end of life says where to name a newer one', function (?string $source, string $fix) {
    $this->travelTo('2026-11-14');
    $application = goApp(['go_version' => '1.25.14', 'stack' => $source === null ? [] : ['runtimes' => ['go' => ['source' => $source]]]]);

    expect(ApplicationPresenter::runtimeEol($application))->toBe(['runtime' => 'Go 1.25', 'ended' => '19 August 2026', 'fix' => $fix]);
})->with([
    'nothing named it' => [null, 'Add a go line naming 1.27 to go.mod, then deploy.'],
    'go.mod' => ['go.mod', 'Raise the go line in go.mod to 1.27, then deploy.'],
    '.tool-versions' => ['.tool-versions', 'Set golang in .tool-versions to 1.27, then deploy.'],
]);

test('moving an app to another team carries its Go version and its main package', function () {
    expect(MoveColumns::APPLICATIONS)->toContain('go_version')
        ->and(MoveColumns::APPLICATIONS)->toContain('main_package');
});
