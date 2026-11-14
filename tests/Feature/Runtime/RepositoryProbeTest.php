<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProbeOutput;

function probeConnection(FakeSSHTransport $transport): SSHConnection
{
    return (new SSHConnection(Server::factory()->create(['host_key_fingerprint' => null]), $transport))->connect();
}

test('the probe reads version files and lockfiles in one command, from the app directory', function () {
    $transport = (new FakeSSHTransport)->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['.nvmrc' => "22\n"], ['bun.lock'], ['laravel/framework' => 'v13.2.0']));

    $facts = app(RepositoryProbe::class)->read(probeConnection($transport), '/var/coresmith/apps/shop/releases/1');

    expect($transport->commands())->toHaveCount(1)
        ->and($transport->commands()[0])->toContain("CORESMITH_PROBE_DIR='/var/coresmith/apps/shop/releases/1' bash -s")
        ->and($facts->file('.nvmrc'))->toBe("22\n")
        ->and($facts->lockfiles)->toBe(['bun.lock'])
        ->and($facts->locked('laravel/framework'))->toBe('13.2.0');
});

test('a directory name cannot break out of the probe command', function () {
    $transport = new FakeSSHTransport;

    app(RepositoryProbe::class)->read(probeConnection($transport), "/tmp/x'; rm -rf /; '");

    expect($transport->commands()[0])->toContain("CORESMITH_PROBE_DIR='/tmp/x'\\''; rm -rf /; '\\''' bash -s");
});

test('only files the probe asks for are kept, and damaged lines are skipped', function () {
    $facts = app(RepositoryProbe::class)->parse(implode("\n", [
        '@@file .env ' . base64_encode('APP_KEY=secret'),
        '@@file composer.json ' . base64_encode('{"require":{"php":"^8.4"}}'),
        '@@file package.json not-base64!!',
        '@@lock Gemfile.lock',
        '@@lock yarn.lock',
        'noise',
    ]));

    expect(array_keys($facts->files))->toBe(['composer.json'])
        ->and($facts->lockfiles)->toBe(['yarn.lock']);
});

test('the probe script reads a real directory', function () {
    $directory = sys_get_temp_dir() . '/coresmith-probe-' . Str::random(8);
    File::ensureDirectoryExists($directory);
    File::put($directory . '/.nvmrc', "22\n");
    File::put($directory . '/composer.json', '{"require":{"php":"^8.4"}}');
    File::put($directory . '/bun.lock', '');
    File::put($directory . '/composer.lock', "{\n    \"packages\": [\n        {\n            \"name\": \"laravel/framework\",\n            \"version\": \"v13.2.0\",\n            \"source\": {}\n        }\n    ]\n}\n");
    File::put($directory . '/Gemfile.lock', "GEM\n  specs:\n    rails (8.0.2)\n      actioncable (= 8.0.2)\n");

    $result = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => $directory])->input(app(RepositoryProbe::class)->script())->run('bash -s');
    $facts = app(RepositoryProbe::class)->parse($result->output());

    File::deleteDirectory($directory);

    expect($result->successful())->toBeTrue()
        ->and($facts->file('.nvmrc'))->toBe("22\n")
        ->and($facts->json('composer.json'))->toBe(['require' => ['php' => '^8.4']])
        ->and($facts->lockfiles)->toBe(['bun.lock'])
        ->and($facts->locked)->toBe(['laravel/framework' => '13.2.0', 'rails' => '8.0.2']);
});

test('in a Go module the probe lists each Go file two folders deep with only the lines that say how it builds and listens', function () {
    $directory = sys_get_temp_dir() . '/coresmith-probe-' . Str::random(8);
    File::ensureDirectoryExists($directory . '/cmd/worker');
    File::ensureDirectoryExists($directory . '/internal/store');
    File::ensureDirectoryExists($directory . '/examples');
    File::ensureDirectoryExists($directory . '/vendor');
    File::put($directory . '/go.mod', "module example.com/shop\n\ngo 1.26\n");
    File::put($directory . '/main.go', "package main\n\nimport (\n\t\"net/http\"\n\t\"os\"\n)\n\nfunc main() {\n\thttp.ListenAndServe(\":\"+os.Getenv(\"PORT\"), nil)\n}\n");
    File::put($directory . '/main_test.go', "package main\n");
    File::put($directory . '/cmd/worker/main.go', "package main\n\nfunc main() {}\n");
    File::put($directory . '/internal/store/store.go', "package store\n\nfunc Open() {}\n");
    File::put($directory . '/examples/demo.go', "package main\n");
    File::put($directory . '/vendor/lib.go', "package lib\n");

    $result = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => $directory])->input(app(RepositoryProbe::class)->script())->run('bash -s');
    $facts = app(RepositoryProbe::class)->parse($result->output());

    File::deleteDirectory($directory);

    expect($result->successful())->toBeTrue()
        ->and($facts->goLines)->toBe([
            'main.go' => [[1, 'package main'], [9, 'http.ListenAndServe(":"+os.Getenv("PORT"), nil)']],
            'cmd/worker/main.go' => [[1, 'package main']],
            'internal/store/store.go' => [[1, 'package store']],
        ]);
});

test('outside a Go module the probe reads no Go files, and Go lines from anywhere else are dropped', function () {
    $output = "@@gofile ../etc/passwd.go\n@@goline ../etc/passwd.go " . base64_encode('1:package main') . "\n@@gofile main.go\n@@goline main.go " . base64_encode('1:package main') . "\n@@goline other.go " . base64_encode('1:package main') . "\n";

    expect(app(RepositoryProbe::class)->parse($output)->goLines)->toBe(['main.go' => [[1, 'package main']]])
        ->and(app(RepositoryProbe::class)->script())->toContain('if [ -f go.mod ] && [ ! -L go.mod ]; then');
});

test('the probe reads composer.lock whole, past the cap the other files have', function () {
    $directory = sys_get_temp_dir() . '/coresmith-probe-' . Str::random(8);
    File::ensureDirectoryExists($directory);
    $lock = json_encode(['packages' => array_map(fn(int $index): array => [
        'name' => "vendor/package-{$index}",
        'version' => '1.0.0',
        'require' => ['php' => '8.1 - 8.4'],
        'description' => str_repeat('x', 400),
    ], range(1, 1000))], JSON_PRETTY_PRINT);
    File::put($directory . '/composer.lock', $lock);

    $result = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => $directory])->input(app(RepositoryProbe::class)->script())->run('bash -s');
    $facts = app(RepositoryProbe::class)->parse($result->output());

    File::deleteDirectory($directory);

    expect(strlen((string) $lock))->toBeGreaterThan(RepositoryProbe::MAX_BYTES)
        ->and($facts->json('composer.lock')['packages'] ?? [])->toHaveCount(1000)
        ->and($facts->json('composer.lock')['packages'][999]['require']['php'] ?? null)->toBe('8.1 - 8.4');
});

function probeLockfile(string $lockfile): array
{
    $directory = sys_get_temp_dir() . '/coresmith-probe-' . Str::random(8);
    File::ensureDirectoryExists($directory);
    File::put($directory . '/Gemfile.lock', $lockfile);

    $result = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => $directory])->input(app(RepositoryProbe::class)->script())->run('bash -s');

    File::deleteDirectory($directory);

    return app(RepositoryProbe::class)->parse($result->output())->locked;
}

test('the probe reads whether Gemfile.lock locks sidekiq and solid queue, beside rails', function () {
    $locked = probeLockfile(implode("\n", [
        'GEM',
        '  remote: https://rubygems.org/',
        '  specs:',
        '    rails (8.0.2)',
        '      actioncable (= 8.0.2)',
        '    sidekiq (7.3.9)',
        '      redis-client (>= 0.22.2)',
        '    solid_queue (1.1.5)',
        '      activejob (>= 7.1)',
        '',
        'DEPENDENCIES',
        '  rails (~> 8.0.2)',
        '  sidekiq',
        '  solid_queue',
        '',
    ]));

    expect($locked)->toBe(['rails' => '8.0.2', 'sidekiq' => '7.3.9', 'solid_queue' => '1.1.5']);
});

test('a gem named only as another gem\'s dependency is not locked', function () {
    $locked = probeLockfile(implode("\n", [
        'GEM',
        '  specs:',
        '    mission_control-jobs (1.0.2)',
        '      solid_queue (>= 1.0)',
        '    rails (8.0.2)',
        '',
    ]));

    expect($locked)->toBe(['rails' => '8.0.2']);
});

test('the probe script asks for exactly the files the parser keeps', function () {
    $script = app(RepositoryProbe::class)->script();

    expect($script)->toContain('for f in ' . implode(' ', RepositoryProbe::FILES) . '; do')
        ->and($script)->toContain('for f in ' . implode(' ', RepositoryProbe::LOCKFILES) . '; do')
        ->and($script)->toContain('"$' . RepositoryProbe::DIRECTORY_VARIABLE . '"');
});

test('a deno.jsonc with comments and trailing commas is still read', function () {
    $facts = new RepositoryFacts(['deno.jsonc' => "{\n  // tasks\n  \"tasks\": {\n    \"start\": \"deno run main.ts\",\n  },\n}\n"]);

    expect($facts->denoTask('start'))->toBeTrue()
        ->and($facts->denoTask('build'))->toBeFalse();
});

/**
 * @param  array<string, string>  $files
 * @param  array<string, string>  $links
 * @return array{RepositoryFacts, string}
 */
function probeTree(array $files, array $links = []): array
{
    $directory = sys_get_temp_dir() . '/coresmith-probe-' . Str::random(8);
    File::ensureDirectoryExists($directory);

    foreach ($files as $path => $content) {
        File::ensureDirectoryExists(dirname($directory . '/' . $path));
        File::put($directory . '/' . $path, $content);
    }

    foreach ($links as $path => $target) {
        File::ensureDirectoryExists(dirname($directory . '/' . $path));
        symlink($target, $directory . '/' . $path);
    }

    $result = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => $directory])->input(app(RepositoryProbe::class)->script())->run('bash -s');

    File::deleteDirectory($directory);

    return [app(RepositoryProbe::class)->parse($result->output()), $result->output()];
}

test('the probe reports which marker files exist and which secrets the committed .env sets, never their values', function () {
    [$facts, $output] = probeTree([
        'artisan' => '',
        'public/index.php' => '<?php',
        'composer.json' => '{}',
        '.env' => "APP_KEY=base64:c2VjcmV0LXZhbHVl\nAPP_SECRET=\nSECRET_KEY_BASE=0f1e2d3c4b5a\n",
    ]);

    expect($facts->markers)->toBe(['artisan', 'public/index.php'])
        ->and($facts->exists('artisan'))->toBeTrue()
        ->and($facts->exists('bin/rails'))->toBeFalse()
        ->and($facts->envKeys)->toBe(['APP_KEY', 'SECRET_KEY_BASE'])
        ->and($output)->not->toContain('c2VjcmV0LXZhbHVl')
        ->and($output)->not->toContain('0f1e2d3c4b5a')
        ->and($output)->not->toContain(base64_encode('base64:c2VjcmV0LXZhbHVl'));
});

test('a symlinked file, marker or .env is never read or reported', function () {
    $outside = sys_get_temp_dir() . '/coresmith-outside-' . Str::random(8);
    File::put($outside, "APP_KEY=outside-secret\n");

    [$facts, $output] = probeTree(['package.json' => '{}'], [
        'composer.json' => $outside,
        'artisan' => $outside,
        '.env' => $outside,
    ]);

    File::delete($outside);

    expect($facts->has('composer.json'))->toBeFalse()
        ->and($facts->has('package.json'))->toBeTrue()
        ->and($facts->exists('artisan'))->toBeFalse()
        ->and($facts->envKeys)->toBe([])
        ->and($output)->not->toContain(base64_encode("APP_KEY=outside-secret\n"));
});

test('the probe reads Gemfile.lock and each framework config file whole', function () {
    [$facts] = probeTree([
        'Gemfile.lock' => "GEM\n  specs:\n    puma (6.6.0)\n",
        'svelte.config.js' => "import adapter from '@sveltejs/adapter-node';\n",
        'next.config.mjs' => "export default { output: 'export' };\n",
        'astro.config.mjs' => "import node from '@astrojs/node';\n",
        'nuxt.config.ts' => "export default defineNuxtConfig({});\n",
        'vite.config.ts' => "export default { build: { outDir: 'web' } };\n",
    ]);

    expect($facts->gem('puma'))->toBe('6.6.0')
        ->and($facts->file('svelte.config.js'))->toContain('@sveltejs/adapter-node')
        ->and($facts->file('next.config.mjs'))->toContain("output: 'export'")
        ->and($facts->file('astro.config.mjs'))->toContain('@astrojs/node')
        ->and($facts->has('nuxt.config.ts'))->toBeTrue()
        ->and($facts->file('vite.config.ts'))->toContain('outDir');
});

test('only markers and secret names the probe asks for are kept', function () {
    $facts = app(RepositoryProbe::class)->parse(implode("\n", [
        '@@has artisan',
        '@@has etc/passwd',
        '@@envkey APP_KEY',
        '@@envkey DB_PASSWORD',
    ]));

    expect($facts->markers)->toBe(['artisan'])
        ->and($facts->envKeys)->toBe(['APP_KEY']);
});

test('only a probe that ran to the end can say a file is missing', function () {
    [$facts] = probeTree(['composer.json' => '{}']);

    expect($facts->complete)->toBeTrue()
        ->and(app(RepositoryProbe::class)->parse('@@file composer.json ' . base64_encode('{}'))->complete)->toBeFalse()
        ->and(app(RepositoryProbe::class)->parse('')->complete)->toBeFalse();
});
