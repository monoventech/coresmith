<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\Stack;
use App\Services\Runtime\StackResolver;
use Tests\Support\ProbeOutput;

function packagesFacts(array $composer, array $package = [], array $scripts = [], array $lockfiles = []): RepositoryFacts
{
    $files = ['composer.json' => (string) json_encode($composer)];

    if ($package !== [] || $scripts !== []) {
        $files['package.json'] = (string) json_encode([...$package, ...($scripts === [] ? [] : ['scripts' => $scripts])]);
    }

    return app(RepositoryProbe::class)->parse(ProbeOutput::for($files, $lockfiles));
}

function packagesApp(array $attributes = []): Application
{
    return Application::factory()->make(['type' => ApplicationType::Php, 'framework' => 'laravel', 'php_version' => null, ...$attributes]);
}

function packagesStack(Application $application, RepositoryFacts $facts): Stack
{
    return app(StackResolver::class)->resolve($application, $facts, fn(string $runtime): array => []);
}

test("a laravel app's packages come from what composer installs", function () {
    $facts = packagesFacts(
        ['require' => ['php' => '^8.4', 'laravel/framework' => '^13.0', 'laravel/horizon' => '^5.30', 'laravel/reverb' => '^1.5', 'inertiajs/inertia-laravel' => '^2.0']],
        ['devDependencies' => ['@inertiajs/svelte' => '^3.0']],
    );

    expect(packagesStack(packagesApp(), $facts)->packages)->toBe(['horizon' => true, 'reverb' => true, 'inertia' => true]);
});

test('require-dev does not count', function () {
    $facts = packagesFacts(['require' => ['laravel/framework' => '^13.0'], 'require-dev' => ['laravel/horizon' => '^5.30', 'laravel/reverb' => '^1.5']]);

    expect(packagesStack(packagesApp(), $facts)->packages)->toBe(['horizon' => false, 'reverb' => false, 'inertia' => false]);
});

test('inertia needs the frontend adapter too', function (array $package, bool $inertia) {
    $facts = packagesFacts(['require' => ['laravel/framework' => '^13.0', 'inertiajs/inertia-laravel' => '^2.0']], $package);

    expect(packagesStack(packagesApp(), $facts)->packages['inertia'])->toBe($inertia);
})->with([
    'no package.json' => [[], false],
    'no adapter' => [['dependencies' => ['vue' => '^3.5']], false],
    'svelte in dependencies' => [['dependencies' => ['@inertiajs/svelte' => '^3.0']], true],
    'vue in devDependencies' => [['devDependencies' => ['@inertiajs/vue3' => '^3.0']], true],
    'react in dependencies' => [['dependencies' => ['@inertiajs/react' => '^3.0']], true],
]);

test('an adapter without the laravel side is not inertia', function () {
    $facts = packagesFacts(['require' => ['laravel/framework' => '^13.0']], ['dependencies' => ['@inertiajs/svelte' => '^3.0']]);

    expect(packagesStack(packagesApp(), $facts)->packages['inertia'])->toBeFalse();
});

test('other apps record no packages', function (array $attributes) {
    $facts = packagesFacts(['require' => ['laravel/horizon' => '^5.30', 'laravel/reverb' => '^1.5']]);

    expect(packagesStack(packagesApp($attributes), $facts)->packages)->toBe([]);
})->with([
    'rails' => [['type' => ApplicationType::Ruby, 'framework' => 'rails', 'ruby_version' => null]],
    'a js app' => [['type' => ApplicationType::Js, 'framework' => null, 'js_runtime' => 'bun']],
    'php without laravel' => [['framework' => 'symfony']],
]);

test('an ssr build script is noticed and used only when asked', function (string $lockfile, string $build, string $ssr) {
    $facts = packagesFacts(['require' => ['laravel/framework' => '^13.0']], [], ['build' => 'vite build', 'build:ssr' => 'vite build && vite build --ssr'], [$lockfile]);
    $tooling = JsTooling::for(packagesApp(), $facts);

    expect($tooling?->ssr)->toBeTrue()
        ->and($tooling?->buildCommands(true))->toBe([$ssr])
        ->and($tooling?->buildCommands(false))->toBe([$build])
        ->and($tooling?->buildCommands())->toBe([$build]);
})->with([
    'bun' => ['bun.lock', 'bun run build', 'bun run build:ssr'],
    'npm' => ['package-lock.json', 'npm run build', 'npm run build:ssr'],
    'pnpm' => ['pnpm-lock.yaml', 'COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack pnpm run build', 'COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack pnpm run build:ssr'],
    'yarn' => ['yarn.lock', 'COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack yarn run build', 'COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack yarn run build:ssr'],
]);

test('without an ssr script the build stays the build, even when asked', function () {
    $tooling = JsTooling::for(packagesApp(), packagesFacts(['require' => ['laravel/framework' => '^13.0']], [], ['build' => 'vite build'], ['bun.lock']));

    expect($tooling?->ssr)->toBeFalse()
        ->and($tooling?->buildCommands(true))->toBe(['bun run build']);
});

test('stacks survive a round trip, and old ones read as empty', function () {
    $facts = packagesFacts(
        ['require' => ['laravel/framework' => '^13.0', 'laravel/horizon' => '^5.30']],
        [],
        ['build' => 'vite build', 'build:ssr' => 'vite build --ssr'],
        ['bun.lock'],
    );
    $stack = packagesStack(packagesApp(), $facts);
    $again = Stack::fromArray($stack->toArray());

    expect($stack->toArray()['packages'])->toBe(['horizon' => true, 'reverb' => false, 'inertia' => false])
        ->and($again->packages)->toBe($stack->packages)
        ->and($again->tooling?->ssr)->toBeTrue()
        ->and(Stack::fromArray(['runtimes' => [], 'tooling' => ['manager' => 'bun', 'locked' => true, 'build' => true, 'start' => false]])->packages)->toBe([])
        ->and(Stack::fromArray(['runtimes' => [], 'tooling' => ['manager' => 'bun', 'locked' => true, 'build' => true, 'start' => false]])->tooling?->ssr)->toBeFalse()
        ->and(Stack::fromArray(['packages' => ['horizon' => 'yes', 'unknown' => true, 'reverb' => true]])->packages)->toBe(['reverb' => true]);
});
