<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ApplicationPresenter;
use Carbon\CarbonImmutable;

function bannerApp(string $runtime, string $version, string $source, array $attributes = []): Application
{
    $column = ['nodejs' => 'node_version', 'php' => 'php_version', 'ruby' => 'ruby_version', 'bun' => 'bun_version', 'deno' => 'deno_version'][$runtime];
    $type = ['nodejs' => ApplicationType::Js, 'bun' => ApplicationType::Js, 'deno' => ApplicationType::Js, 'php' => ApplicationType::Php, 'ruby' => ApplicationType::Ruby][$runtime];

    return new Application([
        'type' => $type,
        'js_runtime' => ['nodejs' => 'node', 'bun' => 'bun', 'deno' => 'deno'][$runtime] ?? null,
        'php_version' => null,
        $column => $version,
        'stack' => ['runtimes' => [$runtime => ['runtime' => $runtime, 'version' => $version, 'source' => $source, 'detail' => null]], 'framework' => null, 'tooling' => null],
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25'));
});

test('an app on an end-of-life runtime says so and names the file to change', function (string $runtime, string $version, string $source, string $label, string $ended, string $fix) {
    expect(ApplicationPresenter::runtimeEol(bannerApp($runtime, $version, $source)))->toBe(['runtime' => $label, 'ended' => $ended, 'fix' => $fix]);
})->with([
    'node from .nvmrc' => ['nodejs', '20.20.2', '.nvmrc', 'Node.js 20', '30 April 2026', 'Set .nvmrc to 24, then deploy.'],
    'node from .tool-versions' => ['nodejs', '20.20.2', '.tool-versions', 'Node.js 20', '30 April 2026', 'Set nodejs in .tool-versions to 24, then deploy.'],
    'php from composer.json' => ['php', '8.1.34', 'composer.json', 'PHP 8.1', '31 December 2025', 'Set require.php in composer.json to ^8.5, then deploy.'],
    'ruby chosen when added' => ['ruby', '3.2.11', 'pin', 'Ruby 3.2', '31 March 2026', 'Add a .ruby-version containing 3.4 to the repository, then deploy.'],
]);

test('a supported runtime shows no banner', function () {
    expect(ApplicationPresenter::runtimeEol(bannerApp('php', '8.5.10', 'composer.json')))->toBeNull();
});

test('bun and deno never show one, because neither publishes a support schedule', function (string $runtime, string $version) {
    expect(ApplicationPresenter::runtimeEol(bannerApp($runtime, $version, 'default')))->toBeNull();
})->with([['bun', '1.0.0'], ['deno', '2.0.0']]);

test('the app page carries the banner', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create([
        'type' => ApplicationType::Js,
        'framework' => null,
        'js_runtime' => 'node',
        'php_version' => null,
        'node_version' => '20.20.2',
        'stack' => ['runtimes' => ['nodejs' => ['runtime' => 'nodejs', 'version' => '20.20.2', 'source' => '.nvmrc', 'detail' => '20']], 'framework' => null, 'tooling' => null],
    ]);
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('servers.applications.show', [$server, $application]))
        ->assertInertia(fn($page) => $page->where('application.runtime_eol.runtime', 'Node.js 20')->where('application.runtime_eol.fix', 'Set .nvmrc to 24, then deploy.'));
});
