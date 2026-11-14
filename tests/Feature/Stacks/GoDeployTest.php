<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Deployment\ReleasePaths;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\NativeGoTools;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Stacks\GoTooling;

const GO_BIN = 'GOTOOLCHAIN=local /opt/asdf-data/installs/go/1.27.4/bin/go';

function goDeployTooling(array $changes = []): GoTooling
{
    return GoTooling::fromArray([...(new GoTooling(['cmd/api' => 'api', 'cmd/worker' => 'worker'], 'cmd/api', false, null, [], null, false, null))->toArray(), ...$changes]);
}

function goDeployStack(?GoTooling $tooling = null): Stack
{
    return new Stack(['go' => new ResolvedRuntime('go', '1.27.4', 'go.mod', 'go 1.26', true)], languageTooling: ($tooling ?? goDeployTooling())->toArray());
}

function goDeployApp(array $attributes = [], ?GoTooling $tooling = null): Application
{
    return new Application(['type' => ApplicationType::Go, 'framework' => 'chi', 'slug' => 'shop', 'system_user' => 'coresmith-app-shop', 'go_version' => '1.27.4', 'base_port' => 4400, 'app_size' => 'small', 'stack' => ['language_tooling' => ($tooling ?? goDeployTooling())->toArray()], ...$attributes]);
}

test('modules download with the Go Coresmith installed, never one Go fetches itself, and a vendored module or a workspace downloads nothing up front', function () {
    expect(ApplicationType::Go->language()->install(goDeployApp(), goDeployStack()))->toBe(['case "$(' . GO_BIN . " env GOWORK)\" in ''|off) " . GO_BIN . ' mod download ;; esac'])
        ->and(ApplicationType::Go->language()->install(goDeployApp(), goDeployStack(goDeployTooling(['vendored' => true]))))->toBe([]);
});

test('every package main builds into .coresmith/bin, after templ generates its templates', function (?string $templ, array $generate) {
    expect(ApplicationType::Go->language()->build(goDeployApp(), goDeployStack(goDeployTooling(['templ' => $templ]))))->toBe([
        ...$generate,
        GO_BIN . " build -trimpath -o .coresmith/bin/api './cmd/api'",
        GO_BIN . " build -trimpath -o .coresmith/bin/worker './cmd/worker'",
    ]);
})->with([
    'no templ' => [null, []],
    'templ as a tool' => ['tool', [GO_BIN . ' tool templ generate']],
    'templ as a module' => ['v0.3.857', [GO_BIN . ' run github.com/a-h/templ/cmd/templ@v0.3.857 generate']],
]);

test('a package at the module root builds from the root', function () {
    expect(ApplicationType::Go->language()->build(goDeployApp(), goDeployStack(goDeployTooling(['mains' => ['.' => 'shop'], 'web' => '.']))))->toBe([GO_BIN . " build -trimpath -o .coresmith/bin/shop '.'"]);
});

test("goose runs its migrations only when the app's environment names goose's database", function (string $environment, array $commands) {
    expect(ApplicationType::Go->language()->migrations(goDeployApp(['env_variables' => $environment]), goDeployStack(goDeployTooling(['goose' => 'v3.24.1']))))->toBe($commands);
})->with([
    'both variables' => ["GOOSE_DRIVER=postgres\nGOOSE_DBSTRING=postgres://shop@localhost/shop\n", [GO_BIN . ' run github.com/pressly/goose/v3/cmd/goose@v3.24.1 -dir "${GOOSE_MIGRATION_DIR:-migrations}" up']],
    'no database string' => ["GOOSE_DRIVER=postgres\n", []],
    'nothing' => ['', []],
]);

test('the release is checked for the web binary before it goes live', function () {
    $paths = new ReleasePaths(goDeployApp());

    expect(ApplicationType::Go->language()->releaseChecks(goDeployApp(), $paths, '20261114120000'))->toBe([
        '.coresmith/bin/api' => 'test -x ' . escapeshellarg($paths->appDirectory('20261114120000') . '/.coresmith/bin/api'),
    ]);
});

test('the web unit runs the binary with its listen variables, and a worker runs another binary by name', function () {
    $application = goDeployApp([], goDeployTooling(['listen' => ['LISTEN_ADDR' => 'address']]));
    $builder = app(SystemdUnitBuilder::class);
    $web = $builder->build($application, 'web', (string) $builder->webCommand($application));
    $worker = $builder->build($application, 'worker', 'worker');

    expect($web)->toContain('ExecStart=' . (new ReleasePaths($application))->appDirectory((new ReleasePaths($application))->current()) . '/.coresmith/bin/api')
        ->and($web)->toContain('Environment="PORT=4400"')
        ->and($web)->toContain('Environment="LISTEN_ADDR=127.0.0.1:4400"')
        ->and($worker)->toContain('ExecStart=/usr/bin/env worker')
        ->and($worker)->toContain('/.coresmith/bin:');
});

test('a failed build names the file and line, a missing C header names itself, and a private module says to vendor', function (string $phase, string $output, string $explained) {
    expect(ApplicationType::Go->language()->explain($phase, $output, goDeployStack()))->toBe($explained);
})->with([
    'compiler error' => [DeploymentPhases::BUILD, "# github.com/acme/shop/cmd/api\ncmd/api/main.go:14:2: undefined: routes\n", 'The Go build stopped at cmd/api/main.go line 14: undefined: routes. Fix it, then deploy again.'],
    'missing header' => [DeploymentPhases::BUILD, "# github.com/davidbyttow/govips/v2/vips\nfatal error: vips/vips.h: No such file or directory\n", "The build needs the C header vips/vips.h, which isn't on the server. Install the library's development package on the server, then deploy again."],
    'private module' => [DeploymentPhases::DEPENDENCIES, "go: github.com/acme/billing@v1.2.0: reading github.com/acme/billing/go.mod at revision v1.2.0: git ls-remote -q origin: exit status 128:\n\tfatal: could not read Username for 'https://github.com': terminal prompts disabled\n", "Go couldn't download github.com/acme/billing, which looks private. Commit the app's dependencies with go mod vendor, so the deploy needs no access to them, then deploy again."],
]);

test('cgo and the C libraries Go packages build against get their system packages, and pure Go needs none', function () {
    $server = Server::factory()->make(['os_name' => 'ubuntu']);
    $tools = app(NativeGoTools::class);

    expect($tools->needed($server, ''))->toBe([])
        ->and($tools->needed($server, "github.com/davidbyttow/govips\ncgo\n"))->toBe([
            NativeGoTools::COMPILER => ['build-essential'],
            'github.com/davidbyttow/govips' => ['pkg-config', 'libvips-dev'],
            'cgo' => ['gcc'],
        ])
        ->and($tools->find('/var/coresmith/apps/shop/releases/1'))->toContain("'/var/coresmith/apps/shop/releases/1/go.mod'");
});
