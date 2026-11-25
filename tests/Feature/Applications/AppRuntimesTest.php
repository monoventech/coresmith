<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\UpdateServerRuntime;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\AppRuntimes;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00'));
    config([
        'coresmith.runtimes.php.versions' => [
            ['line' => '8.4', 'latest' => '8.4.9', 'active_until' => '2028-12-31', 'eol_at' => '2030-12-31'],
        ],
        'coresmith.runtimes.nodejs.versions' => [
            ['line' => '22', 'latest' => '22.11.0', 'active_until' => '2027-04-30', 'eol_at' => '2027-04-30'],
        ],
    ]);
});

/**
 * @return array{Team, Server}
 */
function appRuntimesFleet(): array
{
    $team = Team::factory()->owned()->create();

    return [$team, Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active])];
}

function appRuntimesApp(Server $server, string $name, array $runtimes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create([
        'name' => $name,
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.4.1',
        'node_version' => '22.11.0',
        'stack' => ['runtimes' => [
            'php' => ['runtime' => 'php', 'version' => '8.4.1', 'source' => 'composer.json', 'detail' => '^8.2', ...($runtimes['php'] ?? [])],
            'nodejs' => ['runtime' => 'nodejs', 'version' => '22.11.0', 'source' => '.nvmrc', 'detail' => '22', ...($runtimes['nodejs'] ?? [])],
        ]],
    ]);
}

test('an app lists each runtime it runs, its own first, with its support, where the version comes from and the newest patch', function () {
    [$team, $server] = appRuntimesFleet();
    $shop = appRuntimesApp($server, 'shop');

    expect(app(AppRuntimes::class)->rows($shop, $team->owner))->toBe([
        [
            'runtime' => 'php',
            'name' => 'PHP',
            'version' => '8.4.1',
            'status' => ['label' => 'Active until Dec 2028', 'tone' => 'success'],
            'source' => 'From composer.json, which asks for ^8.2',
            'newest' => '8.4.9',
            'pinned' => null,
            'update' => null,
            'update_href' => route('servers.applications.runtime.patch', [$server, $shop]),
        ],
        [
            'runtime' => 'nodejs',
            'name' => 'Node.js',
            'version' => '22.11.0',
            'status' => ['label' => 'Active until Apr 2027', 'tone' => 'success'],
            'source' => 'From .nvmrc, which asks for 22',
            'newest' => null,
            'pinned' => null,
            'update' => null,
            'update_href' => null,
        ],
    ]);
});

test('a runtime the repository pins exactly, or one chosen on the page, says so and a pinned one offers no update', function () {
    [$team, $server] = appRuntimesFleet();
    $pinned = appRuntimesApp($server, 'pinned', ['php' => ['source' => '.tool-versions', 'detail' => '8.4.1']]);
    $chosen = appRuntimesApp($server, 'chosen', ['php' => ['source' => 'pin', 'detail' => null]]);

    expect(app(AppRuntimes::class)->rows($pinned, $team->owner)[0])->toMatchArray([
        'source' => 'From .tool-versions, which asks for 8.4.1',
        'pinned' => '.tool-versions',
        'update_href' => null,
    ])->and(app(AppRuntimes::class)->rows($chosen, $team->owner)[0])->toMatchArray([
        'source' => 'Chosen on this page',
        'pinned' => null,
        'newest' => '8.4.9',
    ]);
});

test('an update shows on the app it deploys and not on another app on the same version', function () {
    [$team, $server] = appRuntimesFleet();
    $shop = appRuntimesApp($server, 'shop');
    $blog = appRuntimesApp($server, 'blog');
    (new UpdateServerRuntime($server, 'php', '8.4.1', '8.4.9', [$shop->id], $team->owner))->remember('installing');

    expect(app(AppRuntimes::class)->rows($shop, $team->owner)[0]['update'])->toBe(['label' => 'PHP 8.4.9', 'state' => 'installing', 'reason' => null])
        ->and(app(AppRuntimes::class)->rows($blog, $team->owner)[0]['update'])->toBeNull();
});

test('people who cannot change and deploy the app are offered no update', function () {
    [$team, $server] = appRuntimesFleet();
    $shop = appRuntimesApp($server, 'shop');
    $deployer = User::factory()->create();
    $team->members()->attach($deployer, ['role' => TeamRole::Member->value]);

    expect(app(AppRuntimes::class)->rows($shop, $deployer->fresh())[0]['update_href'])->toBeNull();
});
