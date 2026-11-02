<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Http::preventStrayRequests();
});

function tokenCloneApp(array $connection = [], array $application = []): Application
{
    $team = Team::factory()->create();
    $provider = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create(array_merge([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
    ], $connection));

    return Application::factory()->for($team)->create(array_merge([
        'source_provider_id' => $provider->id,
        'slug' => 'my-app',
        'domain' => null,
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_id' => '70000001',
        'repository_branch' => 'main',
        'base_port' => 8000,
    ], $application));
}

function tokenCloneRun(Deployment $deployment, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261019120000'))->handle(
            app(SSHConnectionManager::class),
            new DeploymentCommands(new AsdfManager),
        );
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function tokenCloneFake(int $mintStatus = 201, ?Closure $revoke = null, ?string $suspendedAt = null): void
{
    Http::fake([
        'api.github.com/app/installations/51842/access_tokens' => $mintStatus === 201
            ? Http::response(['token' => 'ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl', 'expires_at' => '2026-10-19T13:00:00Z', 'permissions' => ['contents' => 'read'], 'repository_selection' => 'selected'], 201)
            : Http::response(['message' => 'GitHub turned this request down'], $mintStatus),
        'api.github.com/app/installations/51842' => Http::response(['id' => 51842, 'account' => ['login' => 'monoventech', 'type' => 'Organization'], 'suspended_at' => $suspendedAt]),
        'api.github.com/installation/token' => $revoke ?? Http::response(null, 204),
        'api.github.com/repos/*/statuses/*' => Http::response(['id' => 1], 201),
    ]);
}

function tokenCloneRefusedTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n")
        ->respondTo('clone --bare', "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n", 128);
}

test('an app picked from github clones over https with a one-hour token for that repository alone', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");

    tokenCloneRun($deployment, $transport);

    $commands = $transport->commands();
    $clone = collect($commands)->first(fn(string $command): bool => str_contains($command, 'clone --bare'));
    $reserve = array_search("install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app.git-token'", $commands, true);

    expect($clone)->toContain('GIT_TERMINAL_PROMPT=0 git -c credential.helper= -c ')
        ->and($clone)->toContain("clone --bare -- 'https://github.com/monoventech/atlas.git'")
        ->and($reserve)->not->toBeFalse()
        ->and($transport->uploads()['/etc/coresmith/deploy-keys/my-app.git-token'])->toBe("username=x-access-token\npassword=ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl\n")
        ->and(implode("\n", $commands))->not->toContain('ghs_')
        ->and($deployment->fresh()->log)->toContain('clone: fetching with a one-hour GitHub token (read-only, monoventech/atlas)');

    Http::assertSent(fn($request) => str_ends_with($request->url(), '/app/installations/51842/access_tokens')
        && $request['repository_ids'] === [70000001]
        && $request['permissions'] === ['contents' => 'read']);
});

test('the fetch goes through the same token', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");

    tokenCloneRun($deployment, $transport);

    $fetch = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'fetch --prune --tags origin'));

    expect($fetch)->toStartWith('GIT_TERMINAL_PROMPT=0 git -c credential.helper= -c ')
        ->and($fetch)->toContain("cat '\\''/etc/coresmith/deploy-keys/my-app.git-token'\\''");
});

test('a token deploy announces its clone once, naming the token it fetches with', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();

    tokenCloneRun($deployment, (new FakeSSHTransport)->respondTo('df /', "40\n"));

    preg_match_all('/^\[\d{2}:\d{2}:\d{2}\] clone: .*$/m', (string) $deployment->fresh()->log, $markers);

    expect($markers[0])->toHaveCount(1)
        ->and($markers[0][0])->toEndWith('] clone: fetching with a one-hour GitHub token (read-only, monoventech/atlas)');
});

test('the token file is removed and the token revoked even when the clone fails', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();
    $transport = tokenCloneRefusedTransport();

    expect(fn() => tokenCloneRun($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect($transport->commands())->toContain("rm -f '/etc/coresmith/deploy-keys/my-app.git-token'")
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe('GitHub did not let Coresmith read monoventech/atlas. Check the repository is still selected for the Coresmith app on GitHub, then deploy again.');

    Http::assertSent(fn($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/installation/token'
        && $request->hasHeader('Authorization', 'Bearer ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl'));
});

test('the token is revoked as soon as the fetch is done, before any build step runs', function () {
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");
    $revokedAt = null;
    tokenCloneFake(revoke: function () use ($transport, &$revokedAt) {
        $revokedAt = count($transport->commands());

        return Http::response(null, 204);
    });
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();

    tokenCloneRun($deployment, $transport);

    $commands = collect($transport->commands());
    $fetch = $commands->search(fn(string $command): bool => str_contains($command, 'fetch --prune --tags origin'));
    $composer = $commands->search(fn(string $command): bool => str_contains($command, 'composer install'));

    expect($fetch)->not->toBeFalse()
        ->and($composer)->not->toBeFalse()
        ->and($revokedAt)->toBeGreaterThan($fetch)
        ->and($revokedAt)->toBeLessThanOrEqual($composer);
});

test('a token whose file cannot be made is still revoked and its file cleared', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n")
        ->respondTo("install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app.git-token'", "install: cannot create regular file: Read-only file system\n", 1);

    expect(fn() => tokenCloneRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($transport->uploads())->not->toHaveKey('/etc/coresmith/deploy-keys/my-app.git-token')
        ->and($transport->commands())->toContain("rm -f '/etc/coresmith/deploy-keys/my-app.git-token'");

    Http::assertSent(fn($request) => $request->method() === 'DELETE' && $request->url() === 'https://api.github.com/installation/token');
});

test('a token upload that fails keeps the token out of the exception trace', function () {
    tokenCloneFake();
    $server = Server::factory()->create(['ssh_user' => 'deploy']);
    $deployment = Deployment::factory()->for(tokenCloneApp(application: ['server_id' => $server->id]))->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n")
        ->respondTo("> '\\''/etc/coresmith/deploy-keys/my-app.git-token'\\''", "bash: line 1: /etc/coresmith/deploy-keys/my-app.git-token: Read-only file system\n", 1);
    $previous = ini_set('zend.exception_ignore_args', '0');
    $thrown = null;

    try {
        tokenCloneRun($deployment, $transport);
    } catch (SSHCommandFailedException $exception) {
        $thrown = $exception;
    } finally {
        ini_set('zend.exception_ignore_args', is_string($previous) ? $previous : '1');
    }

    $arguments = collect($thrown?->getTrace() ?? [])->flatMap(fn(array $frame): array => $frame['args'] ?? []);

    expect($thrown)->toBeInstanceOf(SSHCommandFailedException::class)
        ->and($arguments->contains('/etc/coresmith/deploy-keys/my-app.git-token'))->toBeTrue()
        ->and($arguments->contains(fn(mixed $argument): bool => is_string($argument) && str_contains($argument, 'ghs_1041207')))->toBeFalse();
});

test('a revoke github does not answer leaves a good deploy good', function () {
    tokenCloneFake(revoke: fn() => throw new ConnectionException('cURL error 28: Operation timed out'));
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");

    tokenCloneRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($transport->commands())->toContain("rm -f '/etc/coresmith/deploy-keys/my-app.git-token'");
});

test('a token never reaches the log, the failure reason or a command', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n")
        ->respondTo('composer install', "Failed to clone https://x-access-token:ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl@github.com/monoventech/atlas.git\nBad credentials for ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl\n", 1);

    expect(fn() => tokenCloneRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    $fresh = $deployment->fresh();

    expect((string) $fresh->log)->toContain('Bad credentials for')
        ->and((string) $fresh->log)->not->toContain('ghs_1041207')
        ->and((string) $fresh->failure_reason)->not->toContain('ghs_1041207')
        ->and((string) json_encode($fresh->getAttributes()))->not->toContain('ghs_1041207')
        ->and(implode("\n", $transport->commands()))->not->toContain('ghs_1041207')
        ->and(Activity::query()->get()->toJson())->not->toContain('ghs_1041207');
});

test('when github refuses a token a public repository still deploys without credentials', function () {
    tokenCloneFake(422);
    $deployment = Deployment::factory()->for(tokenCloneApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");

    tokenCloneRun($deployment, $transport);

    $clone = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'clone --bare'));
    $log = (string) $deployment->fresh()->log;

    expect($clone)->not->toContain('credential.helper')
        ->and($log)->toContain('clone: GitHub gave no token, fetching without credentials')
        ->and(preg_match_all('/^\[\d{2}:\d{2}:\d{2}\] clone: /m', $log))->toBe(1)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(array_keys($transport->uploads()))->not->toContain('/etc/coresmith/deploy-keys/my-app.git-token');
});

test('an uninstalled app marks its connection and says how to fix it', function () {
    tokenCloneFake(404);
    $application = tokenCloneApp();
    $deployment = Deployment::factory()->for($application)->create();

    expect(fn() => tokenCloneRun($deployment, tokenCloneRefusedTransport()))->toThrow(DeploymentAborted::class);

    $connection = SourceProvider::withTrashed()->find($application->source_provider_id);

    expect($connection->trashed())->toBeTrue()
        ->and($connection->access_token)->toBeNull()
        ->and($deployment->fresh()->failure_reason)->toBe("Coresmith's GitHub app is no longer installed on monoventech. Connect GitHub again from Settings, Git, then deploy.");
});

test('a suspended installation is marked and named', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00'));
    tokenCloneFake(403, suspendedAt: '2026-10-19T11:00:00Z');
    $application = tokenCloneApp();
    $deployment = Deployment::factory()->for($application)->create();

    expect(fn() => tokenCloneRun($deployment, tokenCloneRefusedTransport()))->toThrow(DeploymentAborted::class);

    $connection = SourceProvider::query()->find($application->source_provider_id);

    expect($connection->suspended_at?->toDateTimeString())->toBe('2026-10-19 12:00:00')
        ->and($deployment->fresh()->failure_reason)->toBe("Coresmith's GitHub app is suspended on monoventech. Unsuspend it on GitHub, then deploy.");
});

test('a forbidden token github does not call a suspension leaves the connection alone and says what github said', function () {
    tokenCloneFake(403);
    $application = tokenCloneApp();
    $deployment = Deployment::factory()->for($application)->create();

    expect(fn() => tokenCloneRun($deployment, tokenCloneRefusedTransport()))->toThrow(DeploymentAborted::class);

    $connection = SourceProvider::withTrashed()->find($application->source_provider_id);

    expect($connection->trashed())->toBeFalse()
        ->and($connection->suspended_at)->toBeNull()
        ->and($connection->connected())->toBeTrue()
        ->and($deployment->fresh()->failure_reason)->toBe('GitHub would not give Coresmith a clone token for monoventech/atlas. It said: GitHub turned this request down. Fix that on GitHub, then deploy again.');

    Http::assertSent(fn($request) => $request->method() === 'GET' && $request->url() === 'https://api.github.com/app/installations/51842');
});

test('a repository the installation lost is named', function () {
    tokenCloneFake(422);
    $application = tokenCloneApp();
    $deployment = Deployment::factory()->for($application)->create();

    expect(fn() => tokenCloneRun($deployment, tokenCloneRefusedTransport()))->toThrow(DeploymentAborted::class);

    $connection = SourceProvider::withTrashed()->find($application->source_provider_id);

    expect($deployment->fresh()->failure_reason)->toBe("GitHub no longer gives Coresmith access to monoventech/atlas. Add it under Manage repositories on GitHub, or if it moved to another account, connect that account's installation from Settings, Git. Then deploy again.")
        ->and($connection->trashed())->toBeFalse()
        ->and($connection->suspended_at)->toBeNull();
});

test('a token github could not issue is put down to github, not to the repository', function () {
    tokenCloneFake(500);
    $application = tokenCloneApp();
    $deployment = Deployment::factory()->for($application)->create();

    expect(fn() => tokenCloneRun($deployment, tokenCloneRefusedTransport()))->toThrow(DeploymentAborted::class);

    $connection = SourceProvider::withTrashed()->find($application->source_provider_id);

    expect($deployment->fresh()->failure_reason)->toBe('Coresmith could not get a clone token from GitHub. Try again in a minute.')
        ->and($connection->trashed())->toBeFalse()
        ->and($connection->suspended_at)->toBeNull();
});

test('a github app whose key cannot be read is named when the clone needs a token', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp(['github_app_id' => GitHubApp::factory()->create(['private_key' => 'not a private key'])->id]))->create(['commit_hash' => null]);

    expect(fn() => tokenCloneRun($deployment, tokenCloneRefusedTransport()))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->failure_reason)->toBe("This GitHub connection's App private key could not be read. Check the App credentials.")
        ->and($deployment->fresh()->log)->toContain('clone: GitHub gave no token, fetching without credentials');

    Http::assertNothingSent();
});

test('a linked app whose connection was removed clones anonymously and names the connection when that fails', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp(['deleted_at' => now()]))->create();
    $transport = tokenCloneRefusedTransport();

    expect(fn() => tokenCloneRun($deployment, $transport))->toThrow(DeploymentAborted::class);

    $clone = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'clone --bare'));

    expect($clone)->not->toContain('credential.helper')
        ->and($deployment->fresh()->failure_reason)->toBe("This app's GitHub connection was removed, so the private repository monoventech/atlas could not be read. Connect GitHub again from Settings, Git, then deploy.");

    Http::assertNothingSent();
});

test('a connection suspended before the deploy began is named as suspended', function () {
    tokenCloneFake(suspendedAt: '2026-10-19T11:00:00Z');
    $deployment = Deployment::factory()->for(tokenCloneApp(['suspended_at' => now()->subDay()]))->create();
    $transport = tokenCloneRefusedTransport();

    expect(fn() => tokenCloneRun($deployment, $transport))->toThrow(DeploymentAborted::class);

    $clone = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'clone --bare'));

    expect($clone)->not->toContain('credential.helper')
        ->and($deployment->fresh()->failure_reason)->toBe("Coresmith's GitHub app is suspended on monoventech. Unsuspend it on GitHub, then deploy.");

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'GET' && $request->url() === 'https://api.github.com/app/installations/51842');
});

test('a deploy right after unsuspending on github clears the suspension and clones through the connection', function () {
    tokenCloneFake();
    $application = tokenCloneApp(['suspended_at' => now()->subDay()]);
    $deployment = Deployment::factory()->for($application)->create();

    tokenCloneRun($deployment, (new FakeSSHTransport)->respondTo('df /', "40\n"));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->toContain('clone: fetching with a one-hour GitHub token (read-only, monoventech/atlas)')
        ->and(SourceProvider::query()->find($application->source_provider_id)->suspended_at)->toBeNull();

    Http::assertSent(fn($request) => $request->method() === 'GET' && $request->url() === 'https://api.github.com/app/installations/51842');
});

test('a deploy whose suspended connection github no longer has forgets it', function () {
    Http::fake(['api.github.com/app/installations/51842' => Http::response(['message' => 'Not Found'], 404)]);
    tokenCloneFake();
    $application = tokenCloneApp(['suspended_at' => now()->subDay()]);
    $deployment = Deployment::factory()->for($application)->create();

    expect(fn() => tokenCloneRun($deployment, tokenCloneRefusedTransport()))->toThrow(DeploymentAborted::class);

    expect(SourceProvider::withTrashed()->find($application->source_provider_id)->trashed())->toBeTrue()
        ->and($deployment->fresh()->failure_reason)->toBe("This app's GitHub connection was removed, so the private repository monoventech/atlas could not be read. Connect GitHub again from Settings, Git, then deploy.");
});

test('apps cloned over ssh keep their deploy key', function () {
    tokenCloneFake();
    $deployment = Deployment::factory()->for(tokenCloneApp(application: [
        'source_provider_id' => null,
        'repository_id' => null,
        'repository_url' => 'git@github.com:monoventech/atlas.git',
    ]))->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");

    tokenCloneRun($deployment, $transport);

    $clone = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'clone --bare'));

    expect($clone)->toContain('GIT_SSH_COMMAND=')
        ->and($clone)->not->toContain('credential.helper')
        ->and(array_keys($transport->uploads()))->toContain('/etc/coresmith/deploy-keys/my-app')
        ->and(array_keys($transport->uploads()))->not->toContain('/etc/coresmith/deploy-keys/my-app.git-token');

    Http::assertNothingSent();
});

test('a sudo user writes, uses and removes the token file as root', function () {
    tokenCloneFake();
    $server = Server::factory()->create(['ssh_user' => 'deploy']);
    $deployment = Deployment::factory()->for(tokenCloneApp(application: ['server_id' => $server->id]))->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");

    tokenCloneRun($deployment, $transport);

    $commands = collect($transport->commands());
    $keyDirectory = $commands->filter(fn(string $command): bool => str_contains($command, '/etc/coresmith/deploy-keys'))->values();
    $reserve = $commands->search("sudo install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app.git-token'", true);
    $write = $commands->search(fn(string $command): bool => str_contains($command, 'then cat ') && str_contains($command, 'my-app.git-token'));

    expect($keyDirectory->reject(fn(string $command): bool => str_starts_with($command, 'sudo '))->all())->toBe([])
        ->and($keyDirectory->contains(fn(string $command): bool => str_contains($command, 'clone --bare')))->toBeTrue()
        ->and($keyDirectory->contains(fn(string $command): bool => str_contains($command, 'fetch --prune --tags origin')))->toBeTrue()
        ->and($commands)->toContain("sudo rm -f '/etc/coresmith/deploy-keys/my-app.git-token'")
        ->and($reserve)->not->toBeFalse()
        ->and($write)->not->toBeFalse()
        ->and($reserve)->toBeLessThan($write)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);
});
