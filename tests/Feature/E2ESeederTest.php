<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Panel\ActivityFeed;
use Database\Seeders\E2ESeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;

function e2eSeedRoot(): string
{
    return storage_path('framework/testing/e2e-seeder-' . getmypid());
}

function e2eSeedRun(): void
{
    File::deleteDirectory(e2eSeedRoot());

    config([
        'coresmith.updates.root' => e2eSeedRoot() . '/root',
        'coresmith.updates.state_path' => e2eSeedRoot() . '/state',
        'coresmith.updates.keys_path' => e2eSeedRoot() . '/keys',
    ]);

    test()->seed(E2ESeeder::class);
}

afterEach(fn() => File::deleteDirectory(e2eSeedRoot()));

test('the browser seed records atlas moving to the monoventech connection, and forge-01 describes it', function () {
    e2eSeedRun();

    $team = Team::query()->where('slug', 'monoven')->firstOrFail();
    $atlas = Application::query()->where('team_id', $team->id)->where('slug', 'atlas')->firstOrFail();
    $github = SourceProvider::query()->where('team_id', $team->id)->where('name', 'monoventech')->firstOrFail();
    $moved = Activity::query()->where('description', 'application_relinked')->sole();
    $sentences = collect(app(ActivityFeed::class)->forServer(Server::query()->where('name', 'forge-01')->firstOrFail()))
        ->map(fn(array $row): string => implode('', array_column($row['parts'], 'text')));

    expect($moved->subject_id)->toBe($atlas->id)
        ->and($moved->properties->get('to'))->toBe($github->id)
        ->and($moved->properties->get('repository'))->toBe('monoventech/atlas')
        ->and(SourceProvider::withTrashed()->find($moved->properties->get('from'))?->trashed())->toBeTrue()
        ->and($sentences->all())->toContain('atlas now clones monoventech/atlas through GitHub on monoventech');
});

test('the browser seed gives every browser project its own running deploy to cancel while it streams', function () {
    e2eSeedRun();

    $streamed = collect(E2ESeeder::PROJECTS)->map(fn(string $code): Application => Application::query()->where('slug', "streamed-{$code}")->firstOrFail());

    expect($streamed->map(fn(Application $application): string => $application->server->name)->unique()->values()->all())->toBe(['cancel-01'])
        ->and($streamed->map(fn(Application $application): int => $application->base_port)->unique())->toHaveCount(5)
        ->and($streamed->every(fn(Application $application): bool => $application->deployments()->sole()->status === DeploymentStatus::Running && $application->deployments()->sole()->cancel_requested_at === null))->toBeTrue();
});

test('the browser seed has a team whose github connection lost its app, with a repository to pick from the cache', function () {
    e2eSeedRun();

    $stray = Team::query()->where('slug', 'stray')->firstOrFail();
    $connection = SourceProvider::query()->where('team_id', $stray->id)->sole();
    $owner = User::query()->where('email', E2ESeeder::OWNER_EMAIL)->firstOrFail();

    expect($connection->connected())->toBeTrue()
        ->and(app(GitHubApps::class)->for($connection))->toBeNull()
        ->and($owner->hasTeamAbility($stray, Ability::CreateApplication))->toBeTrue()
        ->and($stray->servers()->pluck('name')->all())->toBe(['stray-01'])
        ->and(app(RepositoryCatalog::class)->cached($connection)['repositories'][0]['full_name'] ?? null)->toBe('stray-works/harbor')
        ->and(Cache::get(RepositoryCatalog::branchesKey($connection, '70000201')))->toBe(['main']);
});
