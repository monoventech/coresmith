<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\Stack;

function repositoryStack(?JsTooling $tooling): Stack
{
    return new Stack([], null, $tooling);
}

test('the lockfile chooses the package manager and its commands', function (JsTooling $tooling, array $install, array $build) {
    $application = new Application(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'bun']);
    $commands = app(DeploymentCommands::class);

    expect($commands->dependencies($application, repositoryStack($tooling)))->toBe($install)
        ->and($commands->build($application, repositoryStack($tooling)))->toBe($build);
})->with([
    'bun, locked' => [new JsTooling('bun', true, true, false), ['bun install --frozen-lockfile'], ['bun run build']],
    'bun, no lockfile' => [new JsTooling('bun', false, true, false), ['bun install'], ['bun run build']],
    'npm' => [new JsTooling('npm', true, true, false), ['npm ci'], ['npm run build']],
    'npm, no lockfile and nothing to build' => [new JsTooling('npm', false, false, false), ['npm install'], []],
    'pnpm' => [new JsTooling('pnpm', true, true, false, '9.12.0'), ['COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack pnpm install --frozen-lockfile'], ['COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack pnpm run build']],
    'yarn classic' => [new JsTooling('yarn', true, true, false, '1.22.22'), ['COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack yarn install --frozen-lockfile'], ['COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack yarn run build']],
    'yarn berry' => [new JsTooling('yarn', true, false, false, '4.5.0'), ['COREPACK_ENABLE_DOWNLOAD_PROMPT=0 corepack yarn install --immutable'], []],
    'deno with a manifest' => [new JsTooling('deno', false, true, true), ['deno install'], ['deno task build']],
    'deno without one' => [new JsTooling('deno', false, false, true, null, false), [], []],
]);

test('a laravel app builds its assets before caching its config', function () {
    $application = new Application(['type' => ApplicationType::Php, 'framework' => 'laravel']);
    $stack = repositoryStack(new JsTooling('bun', true, true, false));

    expect(app(DeploymentCommands::class)->dependencies($application, $stack))->toBe(['composer install --no-dev --optimize-autoloader --no-interaction', 'bun install --frozen-lockfile'])
        ->and(app(DeploymentCommands::class)->build($application, $stack))->toBe(['bun run build', 'php artisan config:cache', 'php artisan route:cache', 'php artisan view:cache']);
});

test('a php app with nothing to build runs no javascript', function () {
    $application = new Application(['type' => ApplicationType::Php, 'framework' => 'laravel']);

    expect(app(DeploymentCommands::class)->dependencies($application, repositoryStack(null)))->toBe(['composer install --no-dev --optimize-autoloader --no-interaction']);
});

test('a rails app leaves its javascript to assets:precompile and configures bundler the current way', function () {
    $application = new Application(['type' => ApplicationType::Ruby, 'framework' => 'rails']);
    $stack = repositoryStack(new JsTooling('yarn', true, true, false));

    expect(app(DeploymentCommands::class)->dependencies($application, $stack))->toBe(['bundle config set --local deployment true', "bundle config set --local without 'development test'", 'bundle install'])
        ->and(app(DeploymentCommands::class)->build($application, $stack))->toBe(['RAILS_ENV=production bundle exec rails assets:precompile']);
});

test('a static site builds only when it has something to build', function () {
    $application = new Application(['type' => ApplicationType::Static]);

    expect(app(DeploymentCommands::class)->build($application, repositoryStack(new JsTooling('bun', true, true, false))))->toBe(['bun run build'])
        ->and(app(DeploymentCommands::class)->build($application, repositoryStack(null)))->toBe([]);
});

test('the web process starts the way the repository says to', function (string $runtime, ?string $framework, bool $start, string $command) {
    $manager = match ($runtime) {
        'node' => 'npm',
        'deno' => 'deno',
        default => 'bun',
    };

    $application = Application::factory()->create([
        'slug' => 'shop',
        'type' => ApplicationType::Js,
        'framework' => $framework,
        'js_runtime' => $runtime,
        'php_version' => null,
        'bun_version' => '1.4.2',
        'node_version' => '24.21.0',
        'deno_version' => '2.9.7',
        'stack' => ['runtimes' => [], 'framework' => null, 'tooling' => (new JsTooling($manager, true, true, $start))->toArray()],
    ]);

    expect(app(SystemdUnitBuilder::class)->webCommand($application))->toBe($command);
})->with([
    'a bun start script' => ['bun', 'sveltekit', true, '/opt/asdf-data/installs/bun/1.4.2/bin/bun run --bun start'],
    'a sveltekit build on bun' => ['bun', 'sveltekit', false, '/opt/asdf-data/installs/bun/1.4.2/bin/bun build/index.js'],
    'a node start script' => ['node', 'nextjs', true, '/opt/asdf-data/installs/nodejs/24.21.0/bin/npm run start'],
    'a nuxt build on node' => ['node', 'nuxt', false, '/opt/asdf-data/installs/nodejs/24.21.0/bin/node .output/server/index.mjs'],
    'an astro build on bun' => ['bun', 'astro', false, '/opt/asdf-data/installs/bun/1.4.2/bin/bun dist/server/entry.mjs'],
    'a deno start task' => ['deno', null, true, '/opt/asdf-data/installs/deno/2.9.7/bin/deno task start'],
    'deno without a start task' => ['deno', null, false, '/opt/asdf-data/installs/deno/2.9.7/bin/deno run --allow-net --allow-read --allow-env main.ts'],
]);

test('the web process listens where caddy sends traffic, and only on loopback', function () {
    $application = Application::factory()->create(['slug' => 'shop', 'type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'bun', 'php_version' => null, 'bun_version' => '1.4.2', 'base_port' => 8120]);
    $builder = app(SystemdUnitBuilder::class);

    expect($builder->build($application, 'web', 'bun build/index.js'))->toContain("Environment=\"PORT=8120\"\nEnvironment=\"HOST=127.0.0.1\"\nEnvironment=\"HOSTNAME=127.0.0.1\"\nEnvironment=\"NODE_ENV=production\"\nEnvironment=\"PROTOCOL_HEADER=x-forwarded-proto\"\nEnvironment=\"HOST_HEADER=x-forwarded-host\"\nEnvironment=\"ADDRESS_HEADER=x-forwarded-for\"\nEnvironmentFile=")
        ->and($builder->build($application, 'queue', 'bun worker.js'))->not->toContain('PORT=');
});

test('a PHP app without composer.json skips composer install', function () {
    $application = new Application(['type' => ApplicationType::Php, 'framework' => null]);
    $commands = app(DeploymentCommands::class);

    expect($commands->dependencies($application, new Stack(files: ['index.php'])))->toBe([])
        ->and($commands->dependencies($application, new Stack(files: ['composer.json', 'index.php'])))->toBe(['composer install --no-dev --optimize-autoloader --no-interaction'])
        ->and($commands->dependencies($application, new Stack))->toBe(['composer install --no-dev --optimize-autoloader --no-interaction']);
});

test('a JavaScript app with no start script starts the main file in package.json', function (string $runtime, ?string $framework, ?string $main, string $command) {
    $application = Application::factory()->create([
        'slug' => 'api',
        'type' => ApplicationType::Js,
        'framework' => $framework,
        'js_runtime' => $runtime,
        'php_version' => null,
        'bun_version' => '1.4.2',
        'node_version' => '24.21.0',
        'stack' => ['runtimes' => [], 'framework' => null, 'tooling' => (new JsTooling($runtime === 'node' ? 'npm' : 'bun', true, false, false, main: $main))->toArray()],
    ]);

    expect(app(SystemdUnitBuilder::class)->webCommand($application))->toBe($command);
})->with([
    'bun' => ['bun', null, 'src/server.js', '/opt/asdf-data/installs/bun/1.4.2/bin/bun src/server.js'],
    'node' => ['node', null, 'dist/index.js', '/opt/asdf-data/installs/nodejs/24.21.0/bin/node dist/index.js'],
    'a framework entry wins' => ['bun', 'sveltekit', 'src/server.js', '/opt/asdf-data/installs/bun/1.4.2/bin/bun build/index.js'],
    'no main keeps server.js' => ['bun', null, null, '/opt/asdf-data/installs/bun/1.4.2/bin/bun server.js'],
]);

test('a main that could break out of the unit file is never used', function (string $main) {
    $facts = new RepositoryFacts(['package.json' => json_encode(['main' => $main])], ['bun.lock']);
    $tooling = JsTooling::for(new Application(['type' => ApplicationType::Js, 'js_runtime' => 'bun']), $facts);

    expect($tooling?->main)->toBeNull()
        ->and(JsTooling::fromArray(['manager' => 'bun', 'main' => $main])?->main)->toBeNull();
})->with([
    'a newline' => ["server.js\nUser=root"],
    'a parent folder' => ['../outside.js'],
    'an absolute path' => ['/etc/passwd'],
    'an option' => ['--inspect=0.0.0.0'],
    'a space' => ['server.js --inspect'],
]);

test('a safe main in package.json is kept', function () {
    $facts = new RepositoryFacts(['package.json' => json_encode(['main' => './src/index.mjs'])], ['bun.lock']);

    expect(JsTooling::for(new Application(['type' => ApplicationType::Js, 'js_runtime' => 'bun']), $facts)?->main)->toBe('src/index.mjs');
});

test('a package.json without a lockfile installs with npm for PHP and static apps, so composer scripts that call npm find it', function (ApplicationType $type) {
    $tooling = JsTooling::for(new Application(['type' => $type]), new RepositoryFacts(['package.json' => '{"scripts":{"build":"vite build"}}']));

    expect($tooling?->manager)->toBe('npm')
        ->and($tooling?->runtime())->toBe('nodejs');
})->with([ApplicationType::Php, ApplicationType::Static]);

test('a JavaScript app without a lockfile installs with the runtime it was given', function () {
    $tooling = fn(string $runtime): ?JsTooling => JsTooling::for(new Application(['type' => ApplicationType::Js, 'js_runtime' => $runtime]), new RepositoryFacts(['package.json' => '{"scripts":{"start":"node server.js"}}']));

    expect($tooling('bun')?->manager)->toBe('bun')
        ->and($tooling('node')?->manager)->toBe('npm');
});
