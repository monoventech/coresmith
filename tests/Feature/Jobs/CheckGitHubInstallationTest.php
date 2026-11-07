<?php

declare(strict_types=1);

use App\Jobs\CheckGitHubInstallation;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function installationCheckConnection(array $attributes = []): SourceProvider
{
    $team = Team::factory()->create();

    return SourceProvider::factory()->for($team)->create([
        'type' => 'github_app',
        'github_app_id' => GitHubApp::factory()->for($team)->create()->id,
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'suspended_at' => now()->subDay(),
        'access_token' => null,
        'token_expires_at' => null,
        ...$attributes,
    ]);
}

function installationCheckAnswer(?string $suspendedAt): array
{
    return ['id' => 51842, 'account' => ['login' => 'monoventech', 'type' => 'Organization'], 'repository_selection' => 'all', 'suspended_at' => $suspendedAt];
}

function installationCheckRun(SourceProvider $connection): void
{
    app()->call([new CheckGitHubInstallation($connection), 'handle']);
}

beforeEach(fn() => Http::preventStrayRequests());

test('an installation unsuspended on github is connected again', function () {
    $connection = installationCheckConnection();
    Http::fake(['api.github.com/app/installations/51842' => Http::response(installationCheckAnswer(null))]);

    installationCheckRun($connection);

    expect($connection->fresh()->suspended_at)->toBeNull()
        ->and($connection->fresh()->connected())->toBeTrue();
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.github.com/app/installations/51842');
});

test('an installation github still suspends stays suspended', function () {
    $connection = installationCheckConnection(['suspended_at' => '2026-10-30 09:00:00']);
    Http::fake(['api.github.com/app/installations/51842' => Http::response(installationCheckAnswer('2026-10-31T09:00:00Z'))]);

    installationCheckRun($connection);

    expect($connection->fresh()->suspended_at?->toDateTimeString())->toBe('2026-10-30 09:00:00')
        ->and($connection->fresh()->connected())->toBeFalse()
        ->and($connection->fresh()->trashed())->toBeFalse();
});

test('a suspended installation github no longer has is forgotten', function () {
    $connection = installationCheckConnection();
    Http::fake(['api.github.com/app/installations/51842' => Http::response(['message' => 'Not Found'], 404)]);

    installationCheckRun($connection);

    expect(SourceProvider::withTrashed()->find($connection->id)->trashed())->toBeTrue()
        ->and(Activity::query()->where('description', 'github_installation_removed')->count())->toBe(1);
});

test('a check github will not answer leaves the connection as it was', function (Closure $answer) {
    $connection = installationCheckConnection();
    Http::fake(['api.github.com/app/installations/51842' => $answer()]);

    installationCheckRun($connection);

    expect($connection->fresh()->suspended_at)->not->toBeNull()
        ->and($connection->fresh()->trashed())->toBeFalse();
})->with([
    'a server error' => [fn() => Http::response(['message' => 'Server Error'], 500)],
    'no answer' => [fn() => Http::failedConnection()],
]);

test('a connection that is no longer suspended, was removed or has lost its app is not asked about', function (string $state) {
    $connection = installationCheckConnection();
    Http::fake();

    match ($state) {
        'unsuspended' => $connection->forceFill(['suspended_at' => null])->save(),
        'removed' => $connection->delete(),
        'app removed' => GitHubApp::query()->whereKey($connection->github_app_id)->delete(),
    };

    installationCheckRun($connection);

    Http::assertNothingSent();
})->with(['unsuspended', 'removed', 'app removed']);

test('a connection is checked at most once a minute however often it is asked for', function () {
    Queue::fake();
    $connection = installationCheckConnection();
    $other = installationCheckConnection(['installation_id' => '51843']);

    CheckGitHubInstallation::throttled($connection);
    CheckGitHubInstallation::throttled($connection);
    CheckGitHubInstallation::throttled($other);

    Queue::assertPushed(CheckGitHubInstallation::class, 2);

    $this->travel(CheckGitHubInstallation::THROTTLE_SECONDS + 1)->seconds();

    CheckGitHubInstallation::throttled($connection);

    Queue::assertPushed(CheckGitHubInstallation::class, 3);
});

test('the check runs on the default queue once, with a short timeout', function () {
    $job = new CheckGitHubInstallation(installationCheckConnection());

    expect($job->queue)->toBe('default')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(30)
        ->and(CheckGitHubInstallation::THROTTLE_SECONDS)->toBe(60);
});
