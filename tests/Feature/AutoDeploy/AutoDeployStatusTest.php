<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\AutoDeploy\AutoDeployStatus;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubApps;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function statusLinkedApp(array $app = [], array $provider = []): Application
{
    $team = Team::factory()->create();
    $connection = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create(array_merge([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
    ], $provider));

    return Application::factory()->for($team)->for(Server::factory()->for($team)->create(['name' => 'forge-01']))->create(array_merge([
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_provider' => 'github',
        'repository_branch' => 'main',
        'source_provider_id' => $connection->id,
        'repository_id' => '70000001',
        'quick_deploy' => true,
    ], $app));
}

function statusUnlinkedApp(array $attributes = []): Application
{
    $team = Team::factory()->create();

    return Application::factory()->for($team)->for(Server::factory()->for($team)->create(['name' => 'forge-01']))->create(array_merge([
        'repository_url' => 'https://github.com/monoven/site.git',
        'repository_branch' => 'main',
        'quick_deploy' => true,
    ], $attributes));
}

function statusOf(Application $application): array
{
    $status = app(AutoDeployStatus::class);
    $application = $application->fresh();

    return [$status->describe($application), $status->interval($application), $status->source($application)];
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
    config([
        'app.url' => 'https://panel.example.com',
        'services.github' => ['app_id' => null, 'app_slug' => 'coresmith', 'client_id' => null, 'client_secret' => null, 'private_key' => null, 'webhook_secret' => null],
    ]);
});

test('an app with auto deploy off says what turning it on does', function (Closure $make) {
    expect(statusOf($make())[0])->toBe('Deploy main whenever it changes');
})->with([
    'linked' => [fn() => statusLinkedApp(['quick_deploy' => false])],
    'unlinked' => [fn() => statusUnlinkedApp(['quick_deploy' => false])],
]);

test('a linked app whose app receives webhooks deploys every push', function () {
    $application = statusLinkedApp();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:02:00'));
    app(GitHubAppHealth::class)->received(app(GitHubApps::class)->for($application->githubConnection()), 'push', 'delivery-1');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));

    expect(statusOf($application))->toBe(['Deploys every push to main. GitHub reached this panel 3 minutes ago.', 10, 'github']);
});

test('a linked app on a panel github cannot reach checks every minute and says why', function () {
    config(['app.url' => 'https://192.168.0.248']);
    $application = statusLinkedApp();
    GitHubApp::query()->update(['hook_url' => null, 'webhook_secret' => null]);

    expect(statusOf($application))->toBe(['Checks main for new commits every minute. GitHub cannot reach 192.168.0.248, it is not a public address.', 1, 'github']);
});

test('a linked app with no evidence yet checks through github and says so', function () {
    expect(statusOf(statusLinkedApp()))->toBe(['Checks main for new commits every minute. No delivery from GitHub has reached this panel yet.', 1, 'github']);
});

test('an unlinked app checks from its server', function () {
    expect(statusOf(statusUnlinkedApp()))->toBe([
        'Checks main for new commits every 5 minutes from forge-01. This app is not linked to a GitHub connection, so pushes are not delivered here.',
        5,
        'server',
    ]);
});

test('a linked app whose connection was removed falls back to its server', function (string $state, string $reason) {
    $application = statusLinkedApp();
    $connection = $application->githubConnection();

    match ($state) {
        'disconnected' => $connection->delete(),
        'app removed' => GitHubApp::query()->delete(),
        'suspended' => $connection->markSuspended(now()),
        'suspended, then disconnected' => (function () use ($connection): void {
            $connection->markSuspended(now());
            $connection->delete();
        })(),
    };

    expect(statusOf($application))->toBe(["Checks main for new commits every 5 minutes from forge-01. {$reason}", 5, 'server']);
})->with([
    'disconnected' => ['disconnected', 'Its GitHub connection was removed, so pushes are not delivered here.'],
    'its github app removed' => ['app removed', 'Its GitHub connection was removed, so pushes are not delivered here.'],
    'suspended' => ['suspended', 'Its GitHub installation is suspended, so pushes are not delivered here.'],
    'suspended, then disconnected' => ['suspended, then disconnected', 'Its GitHub connection was removed, so pushes are not delivered here.'],
]);

test('a failed check replaces the reason', function (Closure $make, string $expected) {
    expect(statusOf($make())[0])->toBe($expected);
})->with([
    'checked through github' => [
        fn() => statusLinkedApp(['poll_error' => 'GitHub did not answer.', 'polled_at' => CarbonImmutable::parse('2026-10-18 14:01:00')]),
        'Checks main for new commits every minute. Could not check main at 14:01: GitHub did not answer.',
    ],
    'checked from the server' => [
        fn() => statusUnlinkedApp(['poll_error' => 'Coresmith could not connect to forge-01.', 'polled_at' => CarbonImmutable::parse('2026-10-18 14:01:00')]),
        'Checks main for new commits every 5 minutes from forge-01. Could not check main at 14:01: Coresmith could not connect to forge-01.',
    ],
    'a check that never recorded its time' => [
        fn() => statusUnlinkedApp(['poll_error' => 'Branch main is not in the repository.', 'polled_at' => null]),
        'Checks main for new commits every 5 minutes from forge-01. Could not check main: Branch main is not in the repository.',
    ],
]);

test('a failed check also replaces the sentence on an app that receives webhooks', function () {
    $application = statusLinkedApp(['poll_error' => 'GitHub did not answer.', 'polled_at' => CarbonImmutable::parse('2026-10-18 14:01:00')]);
    app(GitHubAppHealth::class)->received(app(GitHubApps::class)->for($application->githubConnection()), 'push', 'delivery-1');

    expect(statusOf($application))->toBe(['Deploys every push to main. Could not check main at 14:01: GitHub did not answer.', 10, 'github']);
});

test('intervals follow configuration and read naturally', function () {
    config(['coresmith.auto_deploy.poll_minutes' => 3, 'coresmith.auto_deploy.server_poll_minutes' => 1, 'coresmith.auto_deploy.safety_net_minutes' => 20]);
    $polling = statusLinkedApp();
    $pushed = statusLinkedApp();
    app(GitHubAppHealth::class)->received(app(GitHubApps::class)->for($pushed->githubConnection()), 'push', 'delivery-1');

    expect(statusOf($polling))->toBe(['Checks main for new commits every 3 minutes. No delivery from GitHub has reached this panel yet.', 3, 'github'])
        ->and(statusOf(statusUnlinkedApp()))->toBe([
            'Checks main for new commits every minute from forge-01. This app is not linked to a GitHub connection, so pushes are not delivered here.',
            1,
            'server',
        ])
        ->and(statusOf($pushed)[1])->toBe(20);
});

test('auto deploy is checked every minute through github, every ten minutes as a safety net and every five from a server by default', function () {
    expect(config('coresmith.auto_deploy'))->toBe(['poll_minutes' => 1, 'safety_net_minutes' => 10, 'server_poll_minutes' => 5]);
});

test('an app remembers the branch head it last saw and its last check', function () {
    $application = statusUnlinkedApp([
        'repository_head' => str_repeat('a', 40),
        'repository_head_source' => 'poll',
        'repository_head_at' => '2026-10-18 14:00:00',
        'polled_at' => '2026-10-18 14:01:00',
        'poll_error' => str_repeat('x', 255),
    ])->fresh();

    expect($application->repository_head)->toBe(str_repeat('a', 40))
        ->and($application->repository_head_source)->toBe('poll')
        ->and($application->repository_head_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($application->repository_head_at->toDateTimeString())->toBe('2026-10-18 14:00:00')
        ->and($application->polled_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($application->polled_at->toDateTimeString())->toBe('2026-10-18 14:01:00')
        ->and($application->poll_error)->toBe(str_repeat('x', 255));
});

test('the auto deploy columns are nullable, carry no foreign key and reverse cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_18_200300_add_auto_deploy_state_to_applications.php');
    $added = ['repository_head', 'repository_head_source', 'repository_head_at', 'polled_at', 'poll_error'];
    $columns = collect(Schema::getColumns('applications'))->keyBy('name');

    expect(Schema::hasColumns('applications', $added))->toBeTrue()
        ->and(collect($added)->every(fn(string $column): bool => $columns[$column]['nullable']))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('applications'))->pluck('columns')->flatten()->intersect($added)->all())->toBe([]);

    $migration->down();

    expect(array_filter($added, fn(string $column): bool => Schema::hasColumn('applications', $column)))->toBe([]);

    $migration->up();

    expect(Schema::hasColumns('applications', $added))->toBeTrue();
});
