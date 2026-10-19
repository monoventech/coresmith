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
