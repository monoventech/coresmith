<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Detection\AppFolders;
use App\Services\Detection\RepositoryAccess;
use App\Services\Detection\RepositoryRead;
use App\Services\Detection\RepositoryReader;
use App\Services\Git\DeployKeyManager;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Http::preventStrayRequests();
});

function readerRun(FakeSSHTransport $transport, RepositoryAccess $access, string $branch = 'main', string $directory = '/', array $server = []): RepositoryRead
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        return app(RepositoryReader::class)->read(Server::factory()->create(['name' => 'web-01', ...$server]), $access, $branch, $directory);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function readerScript(FakeSSHTransport $transport): string
{
    $command = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, RepositoryReader::MARKER));

    expect($command)->not->toBeNull();
    preg_match("~echo '([A-Za-z0-9+/=]+)' \\| base64 -d~", (string) $command, $matches);

    return (string) base64_decode($matches[1] ?? '', true);
}

function readerAnswer(string $probe): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo(RepositoryReader::MARKER, "@@commit 9f3c2e1a4b5c6d7e8f9012345678901234567890\n" . $probe);
}

test("the sparse checkout takes Go files two folders deep, so a Go module's packages can be read before it's cloned", function () {
    $transport = readerAnswer("@@done\n");

    readerRun($transport, RepositoryAccess::public('https://github.com/acme/shop.git'), 'main');

    expect(readerScript($transport))->toContain("'/*.go' '/*/*.go' '/*/*/*.go'");
});

test('a public repository is read with a sparse checkout of just the probe\'s files, and the folder is removed', function () {
    $transport = readerAnswer('@@file composer.json ' . base64_encode('{"require":{"laravel/framework":"^13.0"}}') . "\n@@has artisan\n@@has public/index.php\n@@done\n");

    $read = readerRun($transport, RepositoryAccess::public('https://github.com/laravel/laravel.git'), '13.x');
    $script = readerScript($transport);

    expect($read->failure)->toBeNull()
        ->and($read->commit)->toBe('9f3c2e1a4b5c6d7e8f9012345678901234567890')
        ->and($read->facts?->complete)->toBeTrue()
        ->and($read->facts?->exists('artisan'))->toBeTrue()
        ->and($script)->toContain("clone --quiet --depth 1 --filter=blob:none --no-checkout --single-branch --branch '13.x' -- 'https://github.com/laravel/laravel.git'")
        ->and($script)->toContain("sparse-checkout set --no-cone '/.tool-versions'")
        ->and($script)->toContain("'/composer.json'")
        ->and($script)->toContain("'/artisan'")
        ->and($script)->toContain('git -c core.symlinks=false checkout')
        ->and($script)->toContain('export GIT_TERMINAL_PROMPT=0')
        ->and($script)->toContain("limit='timeout 120'")
        ->and($script)->toContain('$limit git clone')
        ->and($script)->toContain("trap 'rm -rf \"\$dir\"' EXIT")
        ->and($script)->not->toContain('credential.helper')
        ->and($script)->not->toContain('GIT_SSH_COMMAND');
});

test('a read the server turns away says why and what to do', function (Closure $transport, array $server, string $failure) {
    $read = readerRun($transport(), RepositoryAccess::public('https://github.com/laravel/laravel.git'), server: $server);

    expect($read->failure)->toBe($failure)
        ->and($read->definitive)->toBeFalse();
})->with([
    'a changed host key' => [fn() => (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('impostor')), ['host_key_fingerprint' => FakeSSHTransport::fingerprintOf('original-key')], "web-01 presented a different host key, so Coresmith didn't read the repository. Review the key on the server page, then try again."],
    'a refused sign-in' => [fn() => (new FakeSSHTransport)->failLogin(), [], "Coresmith couldn't sign in to web-01 over SSH, so it couldn't read the repository. Check the server's SSH key, then try again."],
    'a read that took too long' => [fn() => (new FakeSSHTransport)->timeOutOn(RepositoryReader::MARKER), [], 'web-01 took too long to read the repository. Try again in a minute.'],
    'a dropped connection' => [fn() => (new FakeSSHTransport)->dropConnectionOn(RepositoryReader::MARKER), [], 'Coresmith lost its connection to web-01 while reading the repository. Try again.'],
]);

test('a read that fails for a reason other than the connection is reported', function () {
    Exceptions::fake();
    SSHConnectionManager::createTransportUsing(fn() => throw new RuntimeException('The transport broke.'));

    try {
        $read = app(RepositoryReader::class)->read(Server::factory()->create(['name' => 'web-01']), RepositoryAccess::public('https://github.com/laravel/laravel.git'), 'main', '/');
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($read->failure)->not->toBeNull();
    Exceptions::assertReported(fn(RuntimeException $reported): bool => $reported->getMessage() === 'The transport broke.');
});

test('the read lists the PHP extensions of each PHP on the server', function () {
    $transport = readerAnswer("@@installed php 8.4.25\n@@phpext 8.4.25 bcmath ctype pdo_mysql zend-opcache\n@@done\n");

    $read = readerRun($transport, RepositoryAccess::public('https://github.com/laravel/laravel.git'));

    expect($read->extensions('8.4.25'))->toBe(['bcmath', 'ctype', 'pdo_mysql', 'zend-opcache'])
        ->and($read->extensions('8.3.0'))->toBeNull()
        ->and(readerScript($transport))->toContain('/bin/php -m');
});

test('the read lists the Python versions installed on the server', function () {
    $transport = readerAnswer("@@installed python 3.13.18\n@@installed python 3.14.10\n@@done\n");

    $read = readerRun($transport, RepositoryAccess::public('https://github.com/pallets/flask.git'));

    expect($read->installed('python'))->toBe(['3.13.18', '3.14.10'])
        ->and(readerScript($transport))->toContain('for r in php nodejs bun deno ruby python go; do');
});

test('the read says what kind of machine the server is', function () {
    $transport = readerAnswer("@@arch aarch64\n@@done\n");

    $read = readerRun($transport, RepositoryAccess::public('https://github.com/laravel/laravel.git'));

    expect($read->arch)->toBe('aarch64')
        ->and(readerScript($transport))->toContain('uname -m');
});

test('an app in a subfolder is read from that folder', function () {
    $transport = readerAnswer("@@done\n");

    readerRun($transport, RepositoryAccess::public('https://github.com/acme/mono.git'), 'main', '/apps/web');

    expect(readerScript($transport))->toContain("'/apps/web/package.json'")
        ->and(readerScript($transport))->toContain('app="$dir/repo"/\'apps/web\'');
});

test('a connected GitHub repository is read with a one-hour token that is removed and revoked afterwards', function () {
    Http::fake([
        'api.github.com/app/installations/51842/access_tokens' => Http::response(['token' => 'ghs_readtoken0000000000000000000000000', 'expires_at' => '2026-11-13T13:00:00Z'], 201),
        'api.github.com/installation/token' => Http::response(null, 204),
    ]);
    $team = Team::factory()->create();
    $provider = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create(['name' => 'monoventech', 'installation_id' => '51842']);
    $transport = readerAnswer("@@done\n");

    readerRun($transport, RepositoryAccess::github($provider, '70000001', 'monoventech/atlas'));

    $tokenFile = collect(array_keys($transport->uploads()))->first(fn(string $path): bool => str_starts_with($path, DeployKeyManager::KEY_DIR . '/read-'));
    $script = readerScript($transport);

    expect($tokenFile)->toEndWith('.git-token')
        ->and($transport->uploads()[$tokenFile])->toBe("username=x-access-token\npassword=ghs_readtoken0000000000000000000000000\n")
        ->and($script)->toContain("-- 'https://github.com/monoventech/atlas.git'")
        ->and($script)->toContain('GIT_CONFIG_KEY_1=credential.helper')
        ->and($script)->toContain($tokenFile)
        ->and(implode("\n", $transport->commands()))->not->toContain('ghs_')
        ->and($transport->commands())->toContain('rm -f ' . escapeshellarg($tokenFile));

    Http::assertSent(fn($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/installation/token'));
});

test('a git@ repository is read with the app\'s deploy key, which is removed afterwards', function () {
    $application = Application::factory()->create(['repository_url' => 'git@gitlab.com:acme/shop.git', 'repository_branch' => 'main']);
    $transport = readerAnswer("@@done\n");

    readerRun($transport, RepositoryAccess::deployKey($application));

    $keyFile = collect(array_keys($transport->uploads()))->first(fn(string $path): bool => str_starts_with($path, DeployKeyManager::KEY_DIR . '/read-'));

    expect($transport->uploads()[$keyFile] ?? null)->toBe($application->fresh()->deploy_key_private)
        ->and(readerScript($transport))->toContain('export GIT_SSH_COMMAND=')
        ->and(readerScript($transport))->toContain($keyFile)
        ->and($transport->commands())->toContain('rm -f ' . escapeshellarg($keyFile));
});

test('git failures say what to do and whether they are settled', function (string $output, bool $definitive, string $says) {
    $transport = (new FakeSSHTransport)->respondTo(RepositoryReader::MARKER, $output, 71);

    $read = readerRun($transport, RepositoryAccess::public('https://github.com/acme/private-shop.git'), 'release');

    expect($read->facts)->toBeNull()
        ->and($read->definitive)->toBe($definitive)
        ->and($read->failure)->toContain($says);
})->with([
    'a branch that is not there' => ["warning: Could not find remote branch release to clone.\nfatal: Remote branch release not found in upstream origin\n", true, 'release'],
    'a private repository over https' => ["fatal: could not read Username for 'https://github.com': terminal prompts disabled\n", true, 'git@'],
    'a repository that does not exist' => ["remote: Repository not found.\nfatal: repository 'https://github.com/acme/private-shop.git/' not found\n", true, 'https://github.com/acme/private-shop.git'],
    'dns' => ["fatal: unable to access 'https://github.com/acme/private-shop.git/': Could not resolve host: github.com\n", false, 'web-01'],
    'something else' => ["fatal: the remote end hung up unexpectedly\n", false, 'web-01'],
]);

test('a folder that is not in the repository is settled', function () {
    $read = readerRun(readerAnswer("@@nodir\n"), RepositoryAccess::public('https://github.com/acme/mono.git'), 'main', '/apps/api');

    expect($read->definitive)->toBeTrue()
        ->and($read->failure)->toContain('/apps/api');
});

test('a server Coresmith cannot reach is not settled', function () {
    $read = readerRun((new FakeSSHTransport)->refuseConnections(), RepositoryAccess::public('https://github.com/laravel/laravel.git'));

    expect($read->facts)->toBeNull()
        ->and($read->definitive)->toBeFalse()
        ->and($read->failure)->toContain('web-01');
});

test('the read script checks out only the probe\'s files from a real repository, never follows a link, and cleans up', function () {
    $source = sys_get_temp_dir() . '/coresmith-source-' . Str::random(8);
    File::ensureDirectoryExists($source . '/public');
    File::ensureDirectoryExists($source . '/apps/web');
    File::ensureDirectoryExists($source . '/app');
    File::put($source . '/composer.json', '{"require":{"laravel/framework":"^13.0"}}');
    File::put($source . '/artisan', '#!/usr/bin/env php');
    File::put($source . '/public/index.php', '<?php');
    File::put($source . '/package-lock.json', str_repeat('{"lockfileVersion":3}', 2000));
    File::put($source . '/app/Secret.php', '<?php return "not part of the probe";');
    File::put($source . '/apps/web/package.json', '{"scripts":{"start":"node server.js"}}');
    symlink('/etc/hosts', $source . '/composer.lock');
    $git = fn(string $command) => Process::path($source)->env(['GIT_AUTHOR_NAME' => 'a', 'GIT_AUTHOR_EMAIL' => 'a@example.com', 'GIT_COMMITTER_NAME' => 'a', 'GIT_COMMITTER_EMAIL' => 'a@example.com'])->run($command)->throw();
    $git('git init --quiet --initial-branch=main');
    $git('git config uploadpack.allowFilter true');
    $git('git config uploadpack.allowAnySHA1InWant true');
    $git('git add -A');
    $git('git commit --quiet -m start');
    $leftover = fn(): int => count(glob('/tmp/coresmith-read.*') ?: []);
    $before = $leftover();
    $run = fn(string $directory) => Process::input(app(RepositoryReader::class)->script('file://' . $source, 'main', trim($directory, '/')))->run('bash -s');

    $root = $run('/');
    $facts = app(RepositoryProbe::class)->parse($root->output());
    $web = app(RepositoryProbe::class)->parse($run('/apps/web')->output());
    $missing = $run('/apps/api')->output();

    File::deleteDirectory($source);

    expect($root->exitCode())->toBe(0)
        ->and($root->output())->toMatch('/^@@commit [0-9a-f]{40}$/m')
        ->and($facts->complete)->toBeTrue()
        ->and($facts->json('composer.json')['require'])->toBe(['laravel/framework' => '^13.0'])
        ->and($facts->exists('artisan'))->toBeTrue()
        ->and($facts->exists('public/index.php'))->toBeTrue()
        ->and($facts->lockfiles)->toBe(['package-lock.json'])
        ->and((string) $facts->file('composer.lock'))->not->toContain('localhost')
        ->and($root->output())->not->toContain(base64_encode('<?php return "not part of the probe";'))
        ->and($web->script('start'))->toBeTrue()
        ->and($missing)->toContain('@@nodir')
        ->and($leftover())->toBe($before);
});

test('the read lists the folders that hold an app, three folders deep at most, leaving out dependencies, hidden folders and build output', function () {
    $source = sys_get_temp_dir() . '/coresmith-folders-' . Str::random(8);

    foreach ([
        'composer.json' => '{}',
        'apps/web/package.json' => '{}',
        'apps/web/public/index.html' => '<html>',
        'apps/web/dist/index.html' => '<html>',
        'apps/api/Gemfile' => "gem 'rack'",
        'services/edge/deno.json' => '{}',
        'site/index.html' => '<html>',
        'src/index.html' => '<html>',
        'a/b/c/package.json' => '{}',
        'a/b/c/d/package.json' => '{}',
        'node_modules/left-pad/package.json' => '{}',
        'vendor/acme/lib/composer.json' => '{}',
        '.github/actions/check/package.json' => '{}',
    ] as $path => $content) {
        File::ensureDirectoryExists(dirname($source . '/' . $path));
        File::put($source . '/' . $path, $content);
    }

    $git = fn(string $command) => Process::path($source)->env(['GIT_AUTHOR_NAME' => 'a', 'GIT_AUTHOR_EMAIL' => 'a@example.com', 'GIT_COMMITTER_NAME' => 'a', 'GIT_COMMITTER_EMAIL' => 'a@example.com'])->run($command)->throw();
    $git('git init --quiet --initial-branch=main');
    $git('git config uploadpack.allowFilter true');
    $git('git config uploadpack.allowAnySHA1InWant true');
    $git('git add -A');
    $git('git commit --quiet -m start');

    $output = Process::input(app(RepositoryReader::class)->script('file://' . $source, 'main', ''))->run('bash -s')->output();

    File::deleteDirectory($source);

    expect(AppFolders::in($output))->toBe(['a/b/c', 'apps/api', 'apps/web', 'services/edge', 'site']);
});

test('Python apps in folders are offered by their dependency list, and a virtualenv is not', function () {
    $source = sys_get_temp_dir() . '/coresmith-py-folders-' . Str::random(8);

    foreach ([
        'backend/pyproject.toml' => "[project]\nname = 'api'\n",
        'examples/javascript/pyproject.toml' => "[project]\nname = 'js_example'\n",
        'services/worker/requirements.txt' => "celery\n",
        'billing/Pipfile' => "[packages]\n",
        'venv/requirements.txt' => "pip\n",
        'site-packages/requirements.txt' => "pip\n",
    ] as $path => $content) {
        File::ensureDirectoryExists(dirname($source . '/' . $path));
        File::put($source . '/' . $path, $content);
    }

    $git = fn(string $command) => Process::path($source)->env(['GIT_AUTHOR_NAME' => 'a', 'GIT_AUTHOR_EMAIL' => 'a@example.com', 'GIT_COMMITTER_NAME' => 'a', 'GIT_COMMITTER_EMAIL' => 'a@example.com'])->run($command)->throw();
    $git('git init --quiet --initial-branch=main');
    $git('git config uploadpack.allowFilter true');
    $git('git config uploadpack.allowAnySHA1InWant true');
    $git('git add -A');
    $git('git commit --quiet -m start');

    $output = Process::input(app(RepositoryReader::class)->script('file://' . $source, 'main', ''))->run('bash -s')->output();

    File::deleteDirectory($source);

    expect(AppFolders::in($output))->toBe(['backend', 'billing', 'examples/javascript', 'services/worker']);
});

test('the folders a read finds come back with it', function () {
    $read = readerRun(readerAnswer("@@manifest apps/web/package.json\n@@manifest apps/web/public/index.html\n@@manifest packages/ui/package.json\n@@manifest node_modules/x/package.json\n@@done\n"), RepositoryAccess::public('https://github.com/acme/mono.git'));

    expect($read->folders)->toBe(['apps/web', 'packages/ui']);
});
