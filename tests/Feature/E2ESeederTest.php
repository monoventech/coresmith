<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Jobs\ReadSchedulerLog;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\MoveCode;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CreateServerTask;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Moves\MoveFile;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\CronJobs;
use App\Services\Server\SchedulerLogReader;
use App\Services\Teams\TeamAccess;
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

test("the browser seed has the panel's own host refused by the audit itself", function () {
    e2eSeedRun();

    $audit = Server::query()->where('name', 'panel-host')->sole()->audit;

    expect($audit['state'])->toBe('done')
        ->and(array_column($audit['findings'], 'key'))->toBe(['panel_host']);
});

test('the browser seed has a creation Coresmith could not confirm, which the panel offers to destroy', function () {
    e2eSeedRun();

    $server = Server::query()->where('name', 'lost-create')->sole();
    $task = CloudTask::of($server);

    expect($server->provider_server_id)->toBeNull()
        ->and($task->state)->toBe(CloudTask::FAILED)
        ->and($task->data['uncertain'])->toBe(3)
        ->and(CreateServerTask::uncertain($server))->toBeTrue();
});

test('the browser seed has a destroy in progress, and one per browser project that a test finishes', function () {
    e2eSeedRun();

    $names = ['destroying-server', ...array_map(fn(string $code): string => "gone-{$code}", array_values(E2ESeeder::PROJECTS))];

    foreach ($names as $name) {
        $server = Server::query()->where('name', $name)->sole();
        $task = CloudTask::of($server);

        expect($server->status)->toBe(ServerStatus::Active)
            ->and($server->provider_server_id)->not->toBeNull()
            ->and($task->type)->toBe(CloudTask::DESTROY)
            ->and($task->state)->toBe(CloudTask::RUNNING)
            ->and($task->data['requested'] ?? null)->toBeTrue();
    }

    expect(Server::query()->whereIn('name', $names)->pluck('provider_server_id')->unique())->toHaveCount(count($names));
});

test('the browser seed gives every browser project its own provider account to rename', function () {
    e2eSeedRun();

    $stray = Team::query()->where('slug', 'stray')->sole();
    $names = array_map(fn(string $code): string => "Sandbox {$code}", array_values(E2ESeeder::PROJECTS));

    expect(CloudProvider::query()->where('team_id', $stray->id)->whereIn('name', $names)->count())->toBe(count($names))
        ->and(CloudProvider::query()->where('team_id', $stray->id)->whereIn('name', $names)->whereHas('servers')->exists())->toBeFalse();
});

test('the browser seed has a scheduler log to show, one that could not be read, and an app at its cron job limit', function () {
    e2eSeedRun();

    $log = Cache::get(ReadSchedulerLog::cacheKey(Application::query()->where('name', 'cron-jobs')->sole()));
    $unread = Cache::get(ReadSchedulerLog::cacheKey(Application::query()->where('name', 'cron-paused')->sole()));

    expect($log['lines'])->toHaveCount(SchedulerLogReader::LINES)
        ->and($log['error'])->toBeNull()
        ->and(collect($log['lines'])->contains(fn(string $line): bool => mb_strlen($line) === SchedulerLogReader::LINE_LENGTH))->toBeTrue()
        ->and(collect($log['lines'])->contains(fn(string $line): bool => str_contains($line, "\t")))->toBeTrue()
        ->and(end($log['lines']))->toBe('[2026-11-06 09:00:00] Running [prune] .... DONE')
        ->and($unread)->toMatchArray(['lines' => [], 'error' => "Couldn't read the scheduler log on bench-01."])
        ->and(Application::query()->where('name', 'cron-full')->sole()->cron_jobs)->toHaveCount(CronJobs::MAX_ENTRIES);
});

test('the browser seed gives every browser an owner with a move file to import and a server whose move stopped', function () {
    e2eSeedRun();

    foreach (E2ESeeder::PROJECTS as $code) {
        $mover = User::query()->where('email', E2ESeeder::moverEmail($code))->firstOrFail();
        $move = MoveCode::query()->where('user_id', $mover->id)->firstOrFail();
        $landed = Team::query()->where('slug', "landed-{$code}")->firstOrFail();
        $server = $landed->servers()->firstOrFail();

        expect($mover->managesInstance())->toBeTrue()
            ->and($mover->current_team_id)->toBe($landed->id)
            ->and(app(MoveFile::class)->open((string) $move->file, $move)['team'])->toBe(['name' => "Arrivals {$code}", 'slug' => "arrivals-{$code}"])
            ->and($server->status)->toBe(ServerStatus::Moving)
            ->and($server->takeover['state'])->toBe('failed');
    }
});

test('the browser seed files the monoven apps into projects and has a member who sees only one environment', function () {
    e2eSeedRun();

    $team = Team::query()->where('slug', 'monoven')->sole();
    $storefront = $team->projects()->where('name', 'Storefront')->sole();
    $staging = $storefront->environments()->where('name', 'Staging')->sole();
    $member = User::query()->where('email', E2ESeeder::ENVIRONMENT_EMAIL)->sole();
    $access = app(TeamAccess::class);

    expect($team->projects()->orderBy('name')->pluck('name')->all())->toBe(['Atlas', 'Storefront'])
        ->and($storefront->environments()->pluck('name')->all())->toBe(['Production', 'Staging'])
        ->and($team->applications()->where('name', 'storefront-staging')->sole()->environment_id)->toBe($staging->id)
        ->and($team->databases()->where('name', 'atlas_production')->sole()->environment?->name)->toBe('Production')
        ->and($access->summary($team, $member))->toBe(['everything' => false, 'servers' => [], 'applications' => [], 'projects' => [], 'environments' => [$staging->id]])
        ->and($team->applications()->whereIn('name', array_map(fn(string $code): string => "filed-{$code}", array_values(E2ESeeder::PROJECTS)))->count())->toBe(count(E2ESeeder::PROJECTS))
        ->and($team->servers()->whereIn('name', array_map(fn(string $code): string => "label-{$code}", array_values(E2ESeeder::PROJECTS)))->whereNull('environment')->count())->toBe(count(E2ESeeder::PROJECTS));
});
