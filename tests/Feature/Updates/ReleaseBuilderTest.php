<?php

declare(strict_types=1);

use App\Enums\ReleaseSignature;
use App\Services\Updates\ReleaseBuilder;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdateFailed;
use App\Services\Updates\UpdateState;
use App\Services\Updates\Version;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    $this->paths = updatesUseTemporaryPaths();
    updatesInstall($this->paths['root'], '0.1.0');
    config([
        'coresmith.updates.repository' => 'https://github.com/monoven/coresmith.git',
        'coresmith.updates.web_user' => posix_getpwuid(posix_geteuid())['name'],
    ]);
});

afterEach(function () {
    updatesCleanUp();
});

function updatesBuildFailure(Closure $attempt): UpdateFailed
{
    try {
        $attempt();
    } catch (UpdateFailed $e) {
        return $e;
    }

    throw new RuntimeException('Expected the build to fail.');
}

function updatesFetched(): array
{
    $layout = app(ReleaseLayout::class);
    $run = updatesRun();
    $fetched = app(ReleaseBuilder::class)->fetch(Version::parse('0.1.1'), $layout->incoming(), $run);
    $incoming = glob(config('coresmith.updates.root') . '/releases/.incoming-*')[0];

    return [$layout->promote($incoming, ReleaseLayout::name(Version::parse('0.1.1'), $fetched['commit'])), $run];
}

test('a release is fetched by its tag into a fresh directory and checked out', function () {
    updatesMachine();
    $directory = app(ReleaseLayout::class)->incoming();

    $fetched = app(ReleaseBuilder::class)->fetch(Version::parse('0.1.1'), $directory, $run = updatesRun());

    expect($fetched['commit'])->toBe('a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0')
        ->and($fetched['signature']->status)->toBe(ReleaseSignature::Unchecked)
        ->and(array_slice(updatesRan(), 0, 4))->toBe([
            "git init --quiet {$directory}",
            "git -C {$directory} fetch --quiet --depth 1 --no-tags https://github.com/monoven/coresmith.git refs/tags/v0.1.1:refs/tags/v0.1.1",
            "git -C {$directory} -c advice.detachedHead=false checkout --quiet v0.1.1",
            "git -C {$directory} rev-parse HEAD",
        ])
        ->and(app(UpdateState::class)->log($run->id()))->toContain("Warning: Release signatures aren't checked: this install has no Coresmith release keys yet.")
        ->and(app(UpdateState::class)->status()->signature)->toBe('unchecked')
        ->and(app(UpdateState::class)->status()->step)->toBe('verify');
});

test('a signed release says who signed it', function () {
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');
    updatesMachine();

    $fetched = app(ReleaseBuilder::class)->fetch(Version::parse('0.1.1'), app(ReleaseLayout::class)->incoming(), $run = updatesRun());

    expect($fetched['signature']->status)->toBe(ReleaseSignature::Verified)
        ->and(app(UpdateState::class)->log($run->id()))->toContain('Signed by 2222 2222 2222 2222 2222  2222 2222 2222 2222 2222.')
        ->and(app(UpdateState::class)->status()->signature)->toBe('verified');
});

test('a build installs, compiles and warms the new release in order, linked to the shared files', function () {
    updatesMachine();
    [$path, $run] = updatesFetched();
    $before = count(updatesRan());

    app(ReleaseBuilder::class)->build($path, $run);

    $build = array_slice(updatesProcesses(), $before);
    $user = config('coresmith.updates.web_user');
    $log = app(UpdateState::class)->log($run->id());

    expect(array_column($build, 'command'))->toBe([
        'composer check-platform-reqs --lock --no-dev --no-interaction',
        'composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-progress',
        PHP_BINARY . ' artisan package:discover --ansi',
        PHP_BINARY . ' artisan vendor:publish --tag=laravel-assets --ansi --force',
        'bun install --frozen-lockfile',
        'bun run build',
        PHP_BINARY . ' artisan config:cache',
        PHP_BINARY . ' artisan route:cache',
        PHP_BINARY . ' artisan view:cache',
        "setfacl -m u:{$user}:x {$path}",
        "setfacl -R -m u:{$user}:rX {$path}/public",
    ])
        ->and(array_unique(array_column($build, 'path')))->toBe([$path])
        ->and(min(array_column($build, 'timeout')))->toBeGreaterThanOrEqual(120)
        ->and(readlink("{$path}/storage"))->toBe("{$this->paths['root']}/shared/storage")
        ->and(readlink("{$path}/.env"))->toBe("{$this->paths['root']}/shared/.env")
        ->and(is_dir("{$path}/node_modules"))->toBeFalse()
        ->and(array_values(array_intersect($log, ['Installing PHP dependencies', 'Building the interface', 'Warming caches'])))->toBe(['Installing PHP dependencies', 'Building the interface', 'Warming caches']);
});

test('caddy permissions are skipped when there is no web user on this machine', function () {
    config(['coresmith.updates.web_user' => 'no-such-user-zz']);
    updatesMachine();
    [$path, $run] = updatesFetched();

    app(ReleaseBuilder::class)->build($path, $run);

    expect(collect(updatesRan())->filter(fn(string $command): bool => str_starts_with($command, 'setfacl')))->toBeEmpty()
        ->and(app(UpdateState::class)->log($run->id()))->toContain('Skipping web server permissions: there is no no-such-user-zz user on this machine.');
});

test('a step that fails stops the build and says what to do', function (string $key, array $failure, string $step, string $message) {
    updatesMachine([$key => $failure]);

    $failed = updatesBuildFailure(function () {
        [$path, $run] = updatesFetched();
        app(ReleaseBuilder::class)->build($path, $run);
    });

    expect($failed->step)->toBe($step)
        ->and($failed->getMessage())->toBe($message);
})->with([
    'a tag that is not there' => ['fetch', [128, "fatal: couldn't find remote ref refs/tags/v0.1.1\n"], 'fetch', "v0.1.1 isn't in https://github.com/monoven/coresmith.git. Check CORESMITH_REPOSITORY points at the Coresmith repository. Nothing changed."],
    'an unreachable repository' => ['fetch', [128, "fatal: unable to access 'https://github.com/monoven/coresmith.git/': Could not resolve host: github.com\n"], 'fetch', "Coresmith couldn't fetch v0.1.1 from https://github.com/monoven/coresmith.git: fatal: unable to access 'https://github.com/monoven/coresmith.git/': Could not resolve host: github.com. Check this server can reach it, then try again. Nothing changed."],
    'a checkout that fails' => ['checkout', [128, "error: pathspec 'v0.1.1' did not match\n"], 'fetch', "Coresmith couldn't check out v0.1.1: error: pathspec 'v0.1.1' did not match. Nothing changed."],
    'a missing platform requirement' => ['platform', [2, "ext-gmp * -> it is missing from your system.\nphp 8.6.* -> your php version (8.5.2) does not satisfy that requirement.\n"], 'dependencies', "v0.1.1 needs something this server doesn't have:\next-gmp * -> it is missing from your system.\nphp 8.6.* -> your php version (8.5.2) does not satisfy that requirement.\nInstall it, then update again. Nothing changed."],
    'composer' => ['composer', [1, "one\ntwo\nthree\nfour\n"], 'dependencies', "Installing PHP dependencies failed: two\nthree\nfour. Nothing changed."],
    'package discovery' => ['artisan:package:discover', [1, "Class not found\n"], 'dependencies', 'Installing PHP dependencies failed: Class not found. Nothing changed.'],
    'bun install' => ['bun-install', [1, "error: lockfile had changes, but lockfile is frozen\n"], 'assets', 'Building the interface failed: error: lockfile had changes, but lockfile is frozen. Nothing changed.'],
    'bun build' => ['bun-build', [1, '', "vite v7.1.0 building for production...\nerror during build:\nRollupError: Could not resolve './missing'\n"], 'assets', "Building the interface failed: vite v7.1.0 building for production...\nerror during build:\nRollupError: Could not resolve './missing'. Nothing changed."],
    'config cache' => ['artisan:config:cache', [1, "Your configuration files are not serializable.\n"], 'cache', 'Warming caches failed: Your configuration files are not serializable. Nothing changed.'],
    'setfacl' => ['setfacl', [1, "setfacl: Operation not supported\n"], 'cache', "Coresmith couldn't let the web server read the new release: setfacl: Operation not supported. Nothing changed."],
]);

test('a directory git cannot prepare stops the update', function () {
    updatesMachine(['init' => [1, "fatal: cannot mkdir: Permission denied\n"]]);

    $failed = updatesBuildFailure(fn() => app(ReleaseBuilder::class)->fetch(Version::parse('0.1.1'), app(ReleaseLayout::class)->incoming(), updatesRun()));

    expect($failed->step)->toBe('fetch')
        ->and($failed->getMessage())->toStartWith("Coresmith couldn't prepare {$this->paths['root']}/releases/.incoming-")
        ->and($failed->getMessage())->toEndWith(': fatal: cannot mkdir: Permission denied. Nothing changed.')
        ->and(collect(updatesRan())->filter(fn(string $command): bool => str_contains($command, ' fetch ')))->toBeEmpty();
});

test('what each command prints reaches the run log', function () {
    updatesMachine(['composer' => [1, "Your lock file does not contain a compatible set of packages.\n", "Installing dependencies from lock file\n"]]);
    $run = updatesRun();

    updatesBuildFailure(function () use (&$run) {
        [$path, $run] = updatesFetched();
        app(ReleaseBuilder::class)->build($path, $run);
    });

    expect(app(UpdateState::class)->log($run->id()))
        ->toContain('Installing dependencies from lock file')
        ->toContain('Your lock file does not contain a compatible set of packages.');
});

test('a tag whose VERSION disagrees with its name is refused', function () {
    updatesMachine(['version' => '0.1.0']);

    $failed = updatesBuildFailure(fn() => app(ReleaseBuilder::class)->fetch(Version::parse('0.1.1'), app(ReleaseLayout::class)->incoming(), updatesRun()));

    expect($failed->step)->toBe('fetch')
        ->and($failed->getMessage())->toBe('The v0.1.1 tag says it is version 0.1.0. That release is broken; report it at https://github.com/monoven/coresmith/issues. Nothing changed.');
});

test('an invalid signature stops the update before checkout', function () {
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');
    updatesMachine(['verify' => [1, "error: no signature found\n"]]);

    $failed = updatesBuildFailure(fn() => app(ReleaseBuilder::class)->fetch(Version::parse('0.1.1'), app(ReleaseLayout::class)->incoming(), updatesRun()));

    expect($failed->step)->toBe('verify')
        ->and($failed->getMessage())->toBe('v0.1.1 is not signed. This install only accepts releases signed with a Coresmith release key. Nothing changed.')
        ->and(collect(updatesRan())->filter(fn(string $command): bool => str_contains($command, ' checkout ')))->toBeEmpty();
});

test('a repository url with credentials never reaches the log, a message or the command line', function () {
    config(['coresmith.updates.repository' => 'https://x-access-token:ghp_abcdefghijklmnopqrstuvwxyz0123@github.com/monoventech/coresmith.git']);
    updatesMachine(['fetch' => [128, "fatal: unable to access 'https://x-access-token:ghp_abcdefghijklmnopqrstuvwxyz0123@github.com/monoventech/coresmith.git/': The requested URL returned error: 403\n"]]);
    $run = updatesRun();

    $failed = updatesBuildFailure(fn() => app(ReleaseBuilder::class)->fetch(Version::parse('0.1.1'), app(ReleaseLayout::class)->incoming(), $run));

    expect($failed->getMessage())->toContain('https://[REDACTED]@github.com/monoventech/coresmith.git')
        ->and($failed->getMessage())->not->toContain('ghp_')
        ->and(implode("\n", app(UpdateState::class)->log($run->id())))->not->toContain('ghp_')
        ->and(implode("\n", updatesRan()))->not->toContain('ghp_');

    $fetch = collect(updatesProcesses())->first(fn(array $process): bool => str_contains($process['command'], ' fetch '));

    expect($fetch['environment']['GIT_CONFIG_COUNT'] ?? null)->toBe('1')
        ->and($fetch['environment']['GIT_CONFIG_VALUE_0'] ?? null)->toBe('coresmith-release:');
});
