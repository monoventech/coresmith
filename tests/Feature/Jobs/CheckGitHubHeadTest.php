<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Jobs\CheckGitHubHead;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function githubHeadApp(array $attributes = [], array $connection = []): Application
{
    $team = Team::factory()->create();
    $connection = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create(array_merge([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'access_token' => 'ghs_live',
        'token_expires_at' => now()->addHour(),
    ], $connection));

    return Application::factory()->for($team)->for(Server::factory()->for($team)->active()->create(['name' => 'forge-01']))->create(array_merge([
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_provider' => 'github',
        'repository_branch' => 'main',
        'source_provider_id' => $connection->id,
        'repository_id' => '70000001',
        'quick_deploy' => true,
    ], $attributes));
}

function githubHeadSha(string $character): string
{
    return str_repeat($character, 40);
}

function githubHeadRun(Application $application): void
{
    app()->call([unserialize(serialize(new CheckGitHubHead($application->fresh(['server', 'sourceProvider'])))), 'handle']);
}

function githubHeadFake(mixed $answer): void
{
    Http::fake(['api.github.com/repos/monoventech/atlas/commits/heads/main' => $answer]);
}

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:05:00'));
    config([
        'app.url' => 'https://panel.example.com',
        'services.github' => ['app_id' => null, 'app_slug' => 'coresmith', 'client_id' => null, 'client_secret' => null, 'private_key' => null, 'webhook_secret' => null],
    ]);
});

test('an unchanged branch only reconciles', function () {
    $application = githubHeadApp(['repository_head' => githubHeadSha('a'), 'repository_head_source' => 'push', 'repository_head_at' => now()->subHour()]);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => githubHeadSha('a')]);
    githubHeadFake(Http::response('', 304));

    githubHeadRun($application);

    $fresh = $application->fresh();

    expect($fresh->repository_head)->toBe(githubHeadSha('a'))
        ->and($fresh->repository_head_source)->toBe('push')
        ->and($fresh->repository_head_at?->toDateTimeString())->toBe('2026-10-19 13:05:00')
        ->and($fresh->poll_error)->toBeNull()
        ->and(Deployment::count())->toBe(1);

    Queue::assertNotPushed(DeployApplication::class);
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->hasHeader('If-None-Match', '"' . githubHeadSha('a') . '"')
        && $request->hasHeader('Authorization', 'Bearer ghs_live'));
});

test('an unchanged head that never shipped is deployed by the reconcile', function () {
    $application = githubHeadApp(['repository_head' => githubHeadSha('b'), 'repository_head_source' => 'push']);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => githubHeadSha('a'), 'created_at' => now()->subHour()]);
    githubHeadFake(Http::response('', 304));

    githubHeadRun($application);

    $deployment = Deployment::query()->where('requested_commit', githubHeadSha('b'))->sole();

    expect($deployment->trigger)->toBe('push')
        ->and($application->fresh()->repository_head_source)->toBe('push');

    Queue::assertPushedOn('deployments', DeployApplication::class);
});

test('a moved branch is observed and deployed', function () {
    $application = githubHeadApp(['repository_head' => githubHeadSha('a'), 'repository_head_source' => 'push']);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => githubHeadSha('a'), 'created_at' => now()->subHour()]);
    githubHeadFake(Http::response(githubHeadSha('b')));

    githubHeadRun($application);

    $fresh = $application->fresh();
    $deployment = Deployment::query()->where('requested_commit', githubHeadSha('b'))->sole();

    expect($fresh->repository_head)->toBe(githubHeadSha('b'))
        ->and($fresh->repository_head_source)->toBe('poll')
        ->and($fresh->repository_head_at?->toDateTimeString())->toBe('2026-10-19 14:05:00')
        ->and($fresh->poll_error)->toBeNull()
        ->and($deployment->trigger)->toBe('poll')
        ->and($deployment->branch)->toBe('main')
        ->and($deployment->status)->toBe(DeploymentStatus::Pending);

    Queue::assertPushedOn('deployments', DeployApplication::class);
});

test('a failed check is kept for the page and clears on the next success', function () {
    $application = githubHeadApp(['repository_head' => githubHeadSha('a')]);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => githubHeadSha('a')]);
    githubHeadFake(Http::sequence()->push(['message' => 'Not Found'], 404)->push('', 304));

    githubHeadRun($application);

    expect($application->fresh()->poll_error)->toBe('GitHub has no branch main in monoventech/atlas that this app can see.')
        ->and($application->fresh()->repository_head)->toBe(githubHeadSha('a'));

    githubHeadRun($application);

    expect($application->fresh()->poll_error)->toBeNull()
        ->and(Deployment::count())->toBe(1);
});

test('a refusal longer than the page can hold is cut to fit', function () {
    $application = githubHeadApp(['repository_head' => githubHeadSha('a')]);
    githubHeadFake(Http::response(['message' => 'Resource not accessible by integration. ' . str_repeat('a', 300)], 403));

    githubHeadRun($application);

    $error = (string) $application->fresh()->poll_error;

    expect(mb_strlen($error))->toBe(255)
        ->and($error)->toStartWith('GitHub refused the check. Resource not accessible by integration.');
});

test('a token github refuses marks the connection as a deploy would', function (int $status, array $body, string $state, string $error) {
    $application = githubHeadApp(['repository_head' => githubHeadSha('a')], ['access_token' => null, 'token_expires_at' => null]);
    $connection = $application->githubConnection();
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => githubHeadSha('a')]);
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response($body, $status)]);

    githubHeadRun($application);

    $connection = SourceProvider::withTrashed()->find($connection->id);

    expect($application->fresh()->poll_error)->toBe($error)
        ->and(match ($state) {
            'removed' => $connection->trashed() && $connection->access_token === null,
            'suspended' => !$connection->trashed() && $connection->suspended_at?->toDateTimeString() === '2026-10-19 14:05:00',
            'unchanged' => !$connection->trashed() && $connection->suspended_at === null,
        })->toBeTrue();

    Queue::assertNotPushed(DeployApplication::class);
    Http::assertSentCount(1);
    Http::assertNotSent(fn($request) => str_contains($request->url(), '/commits/heads/'));
})->with([
    'the app was uninstalled' => [404, ['message' => 'Not Found'], 'removed', "Coresmith's GitHub app is no longer installed on monoventech. Connect GitHub again from Settings, Git, then deploy."],
    'the installation is suspended' => [403, ['message' => 'This installation has been suspended'], 'suspended', "Coresmith's GitHub app is suspended on monoventech. Unsuspend it on GitHub, then deploy."],
    'github failed in some other way' => [500, ['message' => 'Server Error'], 'unchanged', 'GitHub refused to issue an installation token: Server Error'],
]);

test('a stored branch coresmith cannot check is not sent to github', function () {
    $application = githubHeadApp(['repository_head' => githubHeadSha('a')]);
    $application->forceFill(['repository_branch' => '--upload-pack=x'])->saveQuietly();

    githubHeadRun($application);

    expect($application->fresh()->poll_error)->toBe("This app's branch name is not one Coresmith can check. Change it in the app's settings.");

    Http::assertNothingSent();
});

test('a push recorded while github was asked is not undone', function (string $answer, string $pushed) {
    $application = githubHeadApp(['repository_head' => githubHeadSha('a'), 'repository_head_source' => 'push', 'repository_head_at' => now()->subHour()]);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => githubHeadSha('a'), 'created_at' => now()->subHour()]);
    githubHeadFake(function () use ($application, $answer, $pushed) {
        Application::query()->whereKey($application->id)->update(['repository_head' => githubHeadSha($pushed), 'repository_head_source' => 'push', 'repository_head_at' => now()->subMinute()]);

        return Http::response(githubHeadSha($answer));
    });

    githubHeadRun($application);

    $fresh = $application->fresh();

    expect([$fresh->repository_head, $fresh->repository_head_source, $fresh->repository_head_at?->toDateTimeString()])
        ->toBe([githubHeadSha($pushed), 'push', '2026-10-19 14:04:00']);
})->with([
    'github answers the head the push recorded' => ['b', 'b'],
    'github answers a head older than the push' => ['b', 'c'],
]);

test('an app that lost its github link before the check is left to its server', function () {
    $application = githubHeadApp(['repository_head' => githubHeadSha('a')]);
    $application->githubConnection()->delete();

    githubHeadRun($application);

    expect($application->fresh()->poll_error)->toBeNull()
        ->and(Deployment::count())->toBe(0);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('the check runs once on the default queue', function () {
    $job = new CheckGitHubHead(githubHeadApp());

    expect($job->queue)->toBe('default')
        ->and($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(1);
});
