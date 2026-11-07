<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Jobs\CheckGitHubHead;
use App\Jobs\CheckRemoteHeads;
use App\Jobs\PollRepositories;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function pollingApp(array $attributes = []): Application
{
    $server = isset($attributes['server_id']) ? Server::query()->findOrFail($attributes['server_id']) : Server::factory()->active()->create();

    return Application::factory()->for($server->team)->for($server)->create(array_merge([
        'repository_url' => 'https://github.com/monoven/site.git',
        'repository_branch' => 'main',
        'quick_deploy' => true,
    ], $attributes));
}

function pollingLinkedApp(array $attributes = []): Application
{
    $team = Team::factory()->create();
    $connection = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
    ]);

    return Application::factory()->for($team)->for(Server::factory()->for($team)->active()->create())->create(array_merge([
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_provider' => 'github',
        'repository_branch' => 'main',
        'source_provider_id' => $connection->id,
        'repository_id' => '70000001',
        'quick_deploy' => true,
    ], $attributes));
}

function pollingRun(): void
{
    app()->call([new PollRepositories, 'handle']);
}

function pollingCheckedApp(CheckGitHubHead $job): Application
{
    return (fn(): Application => $this->application)->call($job);
}

function pollingRemoteIds(CheckRemoteHeads $job): array
{
    return (fn(): array => $this->applicationIds)->call($job);
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

test('a linked app is checked through github when it is due', function () {
    $application = pollingLinkedApp();

    pollingRun();

    Queue::assertPushedOn('default', CheckGitHubHead::class, fn(CheckGitHubHead $job): bool => pollingCheckedApp($job)->is($application));
    Queue::assertPushed(CheckGitHubHead::class, 1);
    Queue::assertNotPushed(CheckRemoteHeads::class);

    expect($application->fresh()->polled_at?->toDateTimeString())->toBe('2026-10-19 14:05:00');
});

test('apps without a github link are checked from their server, one job per server', function () {
    $first = Server::factory()->active()->create();
    $second = Server::factory()->active()->create();
    $apps = [pollingApp(['server_id' => $first->id]), pollingApp(['server_id' => $first->id]), pollingApp(['server_id' => $second->id])];

    pollingRun();

    Queue::assertPushed(CheckRemoteHeads::class, 2);
    Queue::assertPushedOn('default', CheckRemoteHeads::class);
    Queue::assertNotPushed(CheckGitHubHead::class);

    $firstIds = collect([$apps[0]->id, $apps[1]->id])->sort()->implode(',');

    expect(Queue::pushed(CheckRemoteHeads::class)->map(fn(CheckRemoteHeads $job): string => $job->uniqueId())->sort()->values()->all())
        ->toBe(collect(["{$first->id}:{$firstIds}", "{$second->id}:{$apps[2]->id}"])->sort()->values()->all())
        ->and(collect($apps)->map(fn(Application $application) => $application->fresh()->polled_at)->filter()->all())->toBe([]);
});

test('apps on one server are checked five at a time', function () {
    $server = Server::factory()->active()->create();
    $apps = collect(range(1, 7))->map(fn() => pollingApp(['server_id' => $server->id]));

    pollingRun();

    $chunks = Queue::pushed(CheckRemoteHeads::class)->map(fn(CheckRemoteHeads $job): array => pollingRemoteIds($job));

    expect($chunks->map(fn(array $ids): int => count($ids))->sort()->values()->all())->toBe([2, 5])
        ->and($chunks->flatten()->sort()->values()->all())->toBe($apps->pluck('id')->sort()->values()->all());
});

test('an app checked recently waits for its interval', function () {
    pollingApp(['polled_at' => now()->subMinutes(2)]);

    pollingRun();

    Queue::assertNothingPushed();

    $this->travel(4)->minutes();
    pollingRun();

    Queue::assertPushed(CheckRemoteHeads::class, 1);
});

test('a check that lands a few seconds early still counts as due', function (int $secondsAgo, int $jobs) {
    pollingLinkedApp(['polled_at' => now()->subSeconds($secondsAgo)]);

    pollingRun();

    Queue::assertPushed(CheckGitHubHead::class, $jobs);
})->with([
    'polled 56 seconds ago' => [56, 1],
    'polled 54 seconds ago' => [54, 0],
]);

test('apps that are off, removed, on an inactive server, without a repository or mid deploy are skipped', function (Closure $make) {
    $application = $make();

    pollingRun();

    Queue::assertNothingPushed();

    expect(Application::withTrashed()->find($application->id)->polled_at)->toBeNull();
})->with([
    'auto deploy off' => [fn() => pollingApp(['quick_deploy' => false])],
    'removed' => [fn() => tap(pollingApp())->delete()],
    'on a server still provisioning' => [fn() => pollingApp(['server_id' => Server::factory()->create(['status' => ServerStatus::Provisioning])->id])],
    'without a repository' => [fn() => pollingApp(['repository_url' => null])],
    'mid deploy' => [fn() => tap(pollingApp(), fn(Application $application) => Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running]))],
    'waiting to deploy' => [fn() => tap(pollingApp(), fn(Application $application) => Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Pending]))],
    'a linked app mid deploy' => [fn() => tap(pollingLinkedApp(), fn(Application $application) => Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running]))],
]);

test('only the latest deploy that was not cancelled holds a check back', function (array $statuses, int $jobs) {
    $application = pollingApp();

    foreach ($statuses as $minutesAgo => $status) {
        Deployment::factory()->for($application)->create(['status' => $status, 'created_at' => now()->subMinutes($minutesAgo)]);
    }

    pollingRun();

    Queue::assertPushed(CheckRemoteHeads::class, $jobs);
})->with([
    'an old deploy left running, then one that finished' => [[30 => DeploymentStatus::Running, 10 => DeploymentStatus::Succeeded], 1],
    'a running deploy, then one cancelled behind it' => [[30 => DeploymentStatus::Running, 10 => DeploymentStatus::Cancelled], 0],
]);

test('polling runs every minute', function () {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'coresmith:poll-repositories');
    $job = new PollRepositories;

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($job->queue)->toBe('default')
        ->and($job->timeout)->toBe(60)
        ->and($job->tries)->toBe(1);
});
