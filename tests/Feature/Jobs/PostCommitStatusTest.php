<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitProviderException;
use App\Exceptions\ProcessDidNotStart;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DeployApplication;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\DiscardRelease;
use App\Jobs\InstallRuntimes;
use App\Jobs\PostCommitStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['app.url' => 'https://panel.example.com']);
});

function commitStatusApp(array $connection = [], array $application = []): Application
{
    $team = Team::factory()->create();
    $provider = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create(array_merge([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
    ], $connection));

    return Application::factory()->for($team)->create(array_merge([
        'server_id' => Server::factory()->create(['team_id' => $team->id, 'name' => 'bench-01'])->id,
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

function commitStatusDeployment(Application $application, DeploymentStatus $status, array $attributes = []): Deployment
{
    return Deployment::factory()->for($application)->create(array_merge([
        'status' => $status,
        'commit_hash' => '4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b',
        'release' => '20261019120000',
        'started_at' => now()->subMinutes(3),
    ], $attributes));
}

function commitStatusFake(int $statuses = 201, int $mint = 201, ?string $refusal = null): void
{
    Http::fake([
        'api.github.com/app/installations/51842/access_tokens' => $mint === 201
            ? Http::response(['token' => 'ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.Y2xvbmU', 'expires_at' => '2026-10-19T13:00:00Z', 'permissions' => ['contents' => 'read'], 'repository_selection' => 'selected'], 201)
            : Http::response(['message' => $refusal ?? 'GitHub turned this request down'], $mint),
        'api.github.com/installation/token' => Http::response(null, 204),
        'api.github.com/repos/monoventech/atlas/statuses/*' => $statuses === 201
            ? Http::response(['id' => 1, 'state' => 'pending'], 201)
            : Http::response(['message' => $refusal ?? 'Server Error'], $statuses),
    ]);
}

function commitStatusTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n")
        ->respondTo('log -1 --format', "4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b\nAda Lovelace\nShip it\n");
}

function commitStatusRun(Deployment $deployment, FakeSSHTransport $transport): void
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

function commitStatusPosts(): array
{
    return Http::recorded(fn(Request $request): bool => str_contains($request->url(), '/statuses/'))
        ->map(fn(array $pair): Request => $pair[0])
        ->values()
        ->all();
}

function commitStatusStates(): array
{
    return array_map(fn(Request $post): string => $post['state'], commitStatusPosts());
}

function commitStatusPage(Application $application, Deployment $deployment): string
{
    return "https://panel.example.com/servers/{$application->server_id}/applications/{$application->id}/deployments/{$deployment->id}";
}

test('a linked deploy posts pending then success with its own context and a link to its page', function () {
    commitStatusFake();
    $application = commitStatusApp();
    $deployment = Deployment::factory()->for($application)->create(['commit_hash' => null]);

    commitStatusRun($deployment, commitStatusTransport());

    $posts = commitStatusPosts();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(commitStatusStates())->toBe(['pending', 'success'])
        ->and($posts[0]->url())->toBe('https://api.github.com/repos/monoventech/atlas/statuses/4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b')
        ->and($posts[0]->data())->toBe([
            'state' => 'pending',
            'target_url' => commitStatusPage($application, $deployment),
            'description' => 'Deploying to bench-01',
            'context' => 'coresmith/my-app',
        ])
        ->and($posts[1]->url())->toBe($posts[0]->url())
        ->and($posts[1]->data())->toBe([
            'state' => 'success',
            'target_url' => commitStatusPage($application, $deployment),
            'description' => 'Live on bench-01 as 20261019-120000',
            'context' => 'coresmith/my-app',
        ]);
});

test('statuses go out with the installation token, never the clone token', function () {
    commitStatusFake();
    $deployment = Deployment::factory()->for(commitStatusApp([
        'access_token' => 'ghs_51842_installation_token_kept_by_the_panel',
        'token_expires_at' => now()->addHour(),
    ]))->create(['commit_hash' => null]);

    commitStatusRun($deployment, commitStatusTransport());

    $tokens = array_map(fn(Request $post): array => $post->header('Authorization'), commitStatusPosts());

    expect($tokens)->toBe([
        ['Bearer ghs_51842_installation_token_kept_by_the_panel'],
        ['Bearer ghs_51842_installation_token_kept_by_the_panel'],
    ]);
});

test('a failed deploy posts failure', function (?string $live, string $description) {
    commitStatusFake();
    $deployment = Deployment::factory()->for(commitStatusApp(application: ['current_release' => $live]))->create(['commit_hash' => null]);
    $transport = commitStatusTransport()->respondTo('composer install', "Your requirements could not be resolved to an installable set of packages.\n", 2);

    expect(fn() => commitStatusRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(commitStatusStates())->toBe(['pending', 'failure'])
        ->and(commitStatusPosts()[1]['description'])->toBe($description);
})->with([
    'an app with a live release' => ['20261018090000', 'Deploy failed, the previous release is still live'],
    'a first deploy' => [null, 'Deploy failed, the app has no live release yet'],
]);

test('a deploy that failed after going live says so', function () {
    commitStatusFake();
    $deployment = Deployment::factory()->for(commitStatusApp())->create(['commit_hash' => null]);
    $transport = commitStatusTransport()
        ->respondTo("systemctl is-active 'coresmith-app-my-app-fpm'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-my-app-fpm'", 'ERROR: unable to bind listening socket: Address already in use');

    expect(fn() => commitStatusRun($deployment, $transport))->toThrow(ProcessDidNotStart::class);

    expect(commitStatusStates())->toBe(['pending', 'failure'])
        ->and(commitStatusPosts()[1]['description'])->toBe('Deploy failed after going live, read the log');
});

test('apps without a github link post nothing', function () {
    commitStatusFake();
    $deployment = Deployment::factory()->for(commitStatusApp(application: ['source_provider_id' => null, 'repository_id' => null]))->create(['commit_hash' => null]);

    commitStatusRun($deployment, commitStatusTransport());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);

    Http::assertNothingSent();
});

test('a status github refuses never fails the deploy', function () {
    Exceptions::fake();
    commitStatusFake(500);
    $deployment = Deployment::factory()->for(commitStatusApp())->create(['commit_hash' => null]);

    commitStatusRun($deployment, commitStatusTransport());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and(commitStatusStates())->toBe(['pending', 'success']);

    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn(GitProviderException $refusal): bool => $refusal->getMessage() === 'GitHub rejected the request: Server Error');
});

test('a linked deploy queues its statuses on the default queue', function () {
    Queue::fake();
    commitStatusFake();
    $deployment = Deployment::factory()->for(commitStatusApp())->create(['commit_hash' => null]);

    commitStatusRun($deployment, commitStatusTransport());

    Queue::assertPushed(PostCommitStatus::class, 2);
    Queue::assertPushedOn('default', PostCommitStatus::class);
});

test('no status is queued for an app without a usable github link', function (array $connection, array $application) {
    Queue::fake();
    commitStatusFake();
    $deployment = Deployment::factory()->for(commitStatusApp($connection, $application))->create(['commit_hash' => null]);

    commitStatusRun($deployment, commitStatusTransport());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);

    Queue::assertNotPushed(PostCommitStatus::class);
})->with([
    'not linked' => [[], ['source_provider_id' => null, 'repository_id' => null]],
    'suspended' => [['suspended_at' => '2026-10-18 09:00:00'], []],
    'removed' => [['deleted_at' => '2026-10-18 09:00:00'], []],
]);

test('a status job posts the state its deployment is in when it runs, so a late pending never replaces how the deploy ended', function (DeploymentStatus $now, array $states) {
    commitStatusFake();
    $deployment = commitStatusDeployment(commitStatusApp(), DeploymentStatus::Running);
    $job = new PostCommitStatus($deployment);

    Deployment::query()->whereKey($deployment->getKey())->update(['status' => $now->value]);

    app()->call([$job, 'handle']);

    expect(commitStatusStates())->toBe($states);
})->with([
    'still running' => [DeploymentStatus::Running, ['pending']],
    'succeeded since' => [DeploymentStatus::Succeeded, ['success']],
    'failed since' => [DeploymentStatus::Failed, ['failure']],
    'cancelled' => [DeploymentStatus::Cancelled, []],
    'not started' => [DeploymentStatus::Pending, []],
]);

test('a deploy the stale sweep stops posts failure', function () {
    commitStatusFake();
    Queue::fake([DiscardRelease::class]);
    $deployment = commitStatusDeployment(commitStatusApp(application: ['current_release' => '20261018090000']), DeploymentStatus::Running, [
        'started_at' => now()->subMinutes(30),
        'stale_after' => now()->subMinute(),
    ]);

    (new DetectStaleDeployments)->handle();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(commitStatusStates())->toBe(['failure'])
        ->and(commitStatusPosts()[0]['description'])->toBe('Deploy failed, the previous release is still live');
});

test('a deploy that never learned its commit posts nothing', function () {
    commitStatusFake();
    $deployment = Deployment::factory()->for(commitStatusApp())->create(['commit_hash' => null]);

    commitStatusRun($deployment, (new FakeSSHTransport)->respondTo('df /', "40\n"));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(commitStatusPosts())->toBe([]);
});

test('a deployment without a full commit hash posts nothing', function (?string $hash) {
    commitStatusFake();
    $deployment = commitStatusDeployment(commitStatusApp(), DeploymentStatus::Succeeded, ['commit_hash' => $hash]);

    app()->call([new PostCommitStatus($deployment), 'handle']);

    Http::assertNothingSent();
})->with([
    'none' => [null],
    'abbreviated' => ['4f3c2b1'],
    'upper case' => ['4F3C2B1A0E9D8C7B6A5F4E3D2C1B0A9F8E7D6C5B'],
    'not hex' => ['zz3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b'],
]);

test('a status job whose github link is no longer usable posts nothing', function (array $connection, array $application) {
    commitStatusFake();
    $deployment = commitStatusDeployment(commitStatusApp($connection, $application), DeploymentStatus::Succeeded);

    app()->call([new PostCommitStatus($deployment), 'handle']);

    Http::assertNothingSent();
})->with([
    'unlinked since' => [[], ['source_provider_id' => null]],
    'suspended since' => [['suspended_at' => '2026-10-18 09:00:00'], []],
    'removed since' => [['deleted_at' => '2026-10-18 09:00:00'], []],
    'app gone from the panel' => [['github_app_id' => '01jabcdefghjkmnpqrstvwxyz0'], []],
]);

test('a server name too long for github is shortened to fit, so the rest of the description stays whole', function (DeploymentStatus $status, string $description) {
    commitStatusFake();
    $application = commitStatusApp(application: ['current_release' => '20261018090000']);
    $application->server->forceFill(['name' => str_repeat('a', 200)])->save();

    app()->call([new PostCommitStatus(commitStatusDeployment($application, $status)), 'handle']);

    expect(commitStatusPosts()[0]['description'])->toBe($description)
        ->and(mb_strlen($description))->toBeLessThanOrEqual(140);
})->with([
    'pending' => [DeploymentStatus::Running, 'Deploying to ' . str_repeat('a', 127)],
    'success' => [DeploymentStatus::Succeeded, 'Live on ' . str_repeat('a', 113) . ' as 20261019-120000'],
    'failure' => [DeploymentStatus::Failed, 'Deploy failed, the previous release is still live'],
]);

test('an older deployment retried late never overwrites a newer deploy of the same commit', function () {
    commitStatusFake();
    $application = commitStatusApp();
    $older = commitStatusDeployment($application, DeploymentStatus::Failed);
    commitStatusDeployment($application, DeploymentStatus::Succeeded);

    app()->call([new PostCommitStatus($older), 'handle']);

    expect(commitStatusPosts())->toBe([]);
});

test('a redeploy of a commit an older deployment already shipped still posts its own state', function () {
    commitStatusFake();
    $application = commitStatusApp();
    commitStatusDeployment($application, DeploymentStatus::Succeeded);
    $newer = commitStatusDeployment($application, DeploymentStatus::Running);

    app()->call([new PostCommitStatus($newer), 'handle']);

    expect(commitStatusStates())->toBe(['pending']);
});

test('a newer deploy that will not post on the same status line leaves the older one to post', function (array $newer, bool $sameApp) {
    commitStatusFake();
    $application = commitStatusApp();
    $older = commitStatusDeployment($application, DeploymentStatus::Failed);
    commitStatusDeployment($sameApp ? $application : commitStatusApp(application: ['slug' => 'my-app-staging', 'base_port' => 8010]), DeploymentStatus::Succeeded, $newer);

    app()->call([new PostCommitStatus($older), 'handle']);

    expect(commitStatusStates())->toBe(['failure']);
})->with([
    'another commit' => [['commit_hash' => '9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d'], true],
    'cancelled before it learned a commit' => [['status' => DeploymentStatus::Cancelled, 'commit_hash' => null], true],
    'another app on the same repository' => [[], false],
]);

test('a deploy whose runtime install fails posts failure', function () {
    commitStatusFake();
    $deployment = commitStatusDeployment(commitStatusApp(application: ['current_release' => '20261018090000']), DeploymentStatus::Running);
    $transport = (new FakeSSHTransport)->respondTo('test -d', "no\n")
        ->respondTo('asdf install', "configure: error: Package requirements (libxml-2.0 >= 2.9.0) were not met\n", 1);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        expect(fn() => app()->call([new InstallRuntimes($deployment, '20261019120000', [['runtime' => 'php', 'version' => '8.5.10']], 'owner-1'), 'handle']))
            ->toThrow(SSHCommandFailedException::class);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(commitStatusStates())->toBe(['failure'])
        ->and(commitStatusPosts()[0]['description'])->toBe('Deploy failed, the previous release is still live');
});

test('a success that lands while a pending post is in flight waits for it, so success is the last post', function () {
    Sleep::fake(syncWithCarbon: true);
    $deployment = commitStatusDeployment(commitStatusApp(), DeploymentStatus::Running);
    $pending = new PostCommitStatus($deployment);
    $success = new PostCommitStatus($deployment);
    $blocked = null;
    $posts = 0;

    Http::fake([
        'api.github.com/repos/monoventech/atlas/statuses/*' => function () use ($deployment, $success, &$blocked, &$posts) {
            if ($posts++ === 0) {
                Deployment::query()->whereKey($deployment->getKey())->update(['status' => DeploymentStatus::Succeeded->value]);

                try {
                    app()->call([$success, 'handle']);
                } catch (LockTimeoutException $timeout) {
                    $blocked = $timeout;
                }
            }

            return Http::response(['id' => $posts], 201);
        },
    ]);

    app()->call([$pending, 'handle']);
    app()->call([$success, 'handle']);

    expect($blocked)->toBeInstanceOf(LockTimeoutException::class)
        ->and(commitStatusStates())->toBe(['pending', 'success']);
});

test('a status job kept waiting for the lock posts what the deployment says once it has it', function () {
    Sleep::fake(syncWithCarbon: true);
    commitStatusFake();
    $deployment = commitStatusDeployment(commitStatusApp(), DeploymentStatus::Running);
    $held = Cache::lock('commit-status:' . $deployment->getKey(), 60);
    $held->get();

    Sleep::whenFakingSleep(function () use ($deployment, $held): void {
        Deployment::query()->whereKey($deployment->getKey())->update(['status' => DeploymentStatus::Succeeded->value]);
        $held->release();
    });

    app()->call([new PostCommitStatus($deployment), 'handle']);

    expect(commitStatusStates())->toBe(['success']);
});

test('a token request github refuses marks the connection and gives up on the status', function (int $mint, string $marked) {
    commitStatusFake(mint: $mint);
    $application = commitStatusApp(['access_token' => null, 'token_expires_at' => null]);
    $job = (new PostCommitStatus(commitStatusDeployment($application, DeploymentStatus::Succeeded)))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $connection = SourceProvider::withTrashed()->find($application->source_provider_id);

    $job->assertNotFailed();

    expect($connection->{$marked})->not->toBeNull()
        ->and(commitStatusPosts())->toBe([]);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.github.com/app/installations/51842/access_tokens');
})->with([
    'uninstalled' => [404, 'deleted_at'],
    'suspended' => [403, 'suspended_at'],
]);

test('a token request github turns down for good fails the job once instead of using its retries', function (int $mint) {
    Exceptions::fake();
    commitStatusFake(mint: $mint);
    $application = commitStatusApp(['access_token' => null, 'token_expires_at' => null]);
    $job = (new PostCommitStatus(commitStatusDeployment($application, DeploymentStatus::Succeeded)))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $connection = SourceProvider::withTrashed()->find($application->source_provider_id);

    $job->assertFailedWith(GitHubInstallationException::class);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(GitHubInstallationException $refusal): bool => $refusal->getCode() === $mint);
    Http::assertSentCount(1);

    expect(commitStatusPosts())->toBe([])
        ->and($connection->trashed())->toBeFalse()
        ->and($connection->suspended_at)->toBeNull();
})->with([
    'bad credentials' => [401],
    'unprocessable' => [422],
]);

test('a token github could not issue is left to the retries and the connection is left alone', function (int $mint, string $refusal) {
    Exceptions::fake();
    commitStatusFake(mint: $mint, refusal: $refusal);
    $application = commitStatusApp(['access_token' => null, 'token_expires_at' => null]);
    $job = (new PostCommitStatus(commitStatusDeployment($application, DeploymentStatus::Succeeded)))->withFakeQueueInteractions();

    expect(fn() => app()->call([$job, 'handle']))->toThrow(GitHubInstallationException::class, "GitHub refused to issue an installation token: {$refusal}");

    $connection = SourceProvider::withTrashed()->find($application->source_provider_id);

    $job->assertNotFailed();
    Exceptions::assertNothingReported();

    expect($connection->trashed())->toBeFalse()
        ->and($connection->suspended_at)->toBeNull()
        ->and(commitStatusPosts())->toBe([]);
})->with([
    'server error' => [500, 'GitHub turned this request down'],
    'too many requests' => [429, 'Too Many Requests'],
    'a secondary rate limit' => [403, 'You have exceeded a secondary rate limit. Please wait a few minutes before you try again.'],
    'a rate limit sent as 403' => [403, 'API rate limit exceeded for installation ID 51842.'],
]);

test('a status github turns down for good fails the job once instead of using its retries', function (int $status, string $refusal) {
    Exceptions::fake();
    commitStatusFake(statuses: $status, refusal: $refusal);
    $job = (new PostCommitStatus(commitStatusDeployment(commitStatusApp(), DeploymentStatus::Succeeded)))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertFailedWith(GitProviderException::class);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(GitProviderException $failure): bool => $failure->getCode() === $status && $failure->getMessage() === "GitHub rejected the request: {$refusal}");

    expect(commitStatusPosts())->toHaveCount(1);
})->with([
    'not found' => [404, 'Not Found'],
    'not accessible by the app' => [403, 'Resource not accessible by integration'],
    'unprocessable' => [422, 'No commit found for SHA: 4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b'],
    'bad credentials' => [401, 'Bad credentials'],
]);

test('a status github may take later is left to the retries', function (int $status, string $refusal) {
    Exceptions::fake();
    commitStatusFake(statuses: $status, refusal: $refusal);
    $job = (new PostCommitStatus(commitStatusDeployment(commitStatusApp(), DeploymentStatus::Succeeded)))->withFakeQueueInteractions();

    expect(fn() => app()->call([$job, 'handle']))->toThrow(GitProviderException::class, "GitHub rejected the request: {$refusal}");

    $job->assertNotFailed();
    Exceptions::assertNothingReported();
})->with([
    'server error' => [500, 'Server Error'],
    'unavailable' => [503, 'Service Unavailable'],
    'too many requests' => [429, 'Too Many Requests'],
    'a secondary rate limit' => [403, 'You have exceeded a secondary rate limit. Please wait a few minutes before you try again.'],
    'a rate limit sent as 403' => [403, 'API rate limit exceeded for installation ID 51842.'],
]);

test('a status github never answered is left to the retries', function () {
    Http::fake([
        'api.github.com/repos/monoventech/atlas/statuses/*' => fn() => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);
    $job = (new PostCommitStatus(commitStatusDeployment(commitStatusApp(), DeploymentStatus::Succeeded)))->withFakeQueueInteractions();

    expect(fn() => app()->call([$job, 'handle']))->toThrow(GitProviderException::class, 'GitHub did not answer.');

    $job->assertNotFailed();
});

test('the job waits on the default queue and retries what github turns down', function () {
    $job = new PostCommitStatus(commitStatusDeployment(commitStatusApp(), DeploymentStatus::Running));

    expect($job->queue)->toBe('default')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(60)
        ->and($job->backoff())->toBe([10, 60]);
});
