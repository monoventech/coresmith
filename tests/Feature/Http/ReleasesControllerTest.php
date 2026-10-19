<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

function releasesMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function releasesApp(Team $team, array $attributes = []): Application
{
    return Application::factory()->for($team)->for(Server::factory()->for($team)->create(['name' => 'web-1']))->create(array_merge([
        'name' => 'Shop',
        'repository_branch' => 'main',
    ], $attributes));
}

function releasesShipped(Application $application, string $release, int $hoursAgo, array $attributes = []): Deployment
{
    return Deployment::factory()->for($application)->create(array_merge([
        'status' => DeploymentStatus::Succeeded,
        'release' => $release,
        'started_at' => now()->subHours($hoursAgo)->subMinutes(2),
        'finished_at' => now()->subHours($hoursAgo),
    ], $attributes));
}

function releasesUrl(Application $application, array $query = []): string
{
    return route('servers.applications.releases.index', [$application->server_id, $application, ...$query]);
}

function releasesRollback(Application $application, string $description, int $minutesAgo, string $to = '20260912090000'): void
{
    $activity = activity()->performedOn($application)->withProperties(['from' => '20260913120000', 'to' => $to])->log($description);
    $activity->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
}

function releasesWentLive(Application $application, string $release, int $minutesAgo): Deployment
{
    return Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Failed,
        'release' => $release,
        'started_at' => now()->subMinutes($minutesAgo + 2),
        'finished_at' => now()->subMinutes($minutesAgo),
        'log' => "[13:00:01] swap: current → releases/{$release}\n[13:00:05] deploy failed after the symlink swap. releases/{$release} is live, check its processes.",
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('the releases link opens the application that shipped most recently', function () {
    $team = Team::factory()->create();
    $earlier = releasesApp($team, ['name' => 'Blog']);
    $latest = releasesApp($team, ['name' => 'Shop']);
    releasesShipped($earlier, '20260913100000', hoursAgo: 4);
    releasesShipped($latest, '20260913120000', hoursAgo: 2);
    Deployment::factory()->for($earlier)->create(['status' => DeploymentStatus::Failed, 'release' => '20260913140000', 'finished_at' => now()]);
    Deployment::factory()->for($earlier)->create(['status' => DeploymentStatus::Succeeded, 'release' => null, 'finished_at' => now()]);
    releasesShipped(releasesApp(Team::factory()->create()), '20260913140500', hoursAgo: 0);

    $this->actingAs(releasesMember($team))
        ->get(route('releases.index'))
        ->assertRedirect(releasesUrl($latest));
});

test('with nothing shipped the releases link renders an empty page', function (bool $hasTeam) {
    $user = User::factory()->create();

    if ($hasTeam) {
        Team::factory()->create()->members()->attach($user, ['role' => TeamRole::Owner->value]);
    }

    $this->actingAs($user)
        ->get(route('releases.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('releases/Index')
            ->where('application', null)
            ->where('applications', [])
            ->where('releases', [])
            ->where('can', ['deploy' => false]));
})->with(['a team with no releases' => true, 'no team' => false]);

test('releases are the successful deployments with a release, newest first, with their details', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => '20260913120000']);
    $author = User::factory()->create(['name' => 'Grace Hopper']);
    releasesShipped($application, '20260912090000', hoursAgo: 29, attributes: ['commit_hash' => 'aaaaaaa1111111', 'commit_author' => null, 'triggered_by' => $author->id, 'branch' => null]);
    releasesShipped($application, '20260913120000', hoursAgo: 2, attributes: [
        'commit_hash' => '4f3c2b1a0e9d8c7b6a5f',
        'commit_author' => 'Ada Lovelace',
        'tag' => 'v2.4.0',
        'tag_signed' => true,
        'branch' => 'release/2.4',
    ]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'release' => '20260913130000', 'finished_at' => now()->subHour()]);

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('releases/Index')
            ->has('releases', 2)
            ->where('releases.0', fn($release) => collect($release)->except('id')->all() === [
                'release' => '20260913120000',
                'directory' => 'releases/20260913-120000',
                'commit' => '4f3c2b1',
                'author' => 'Ada Lovelace',
                'tag' => 'v2.4.0',
                'signed' => true,
                'branch' => 'release/2.4',
                'when' => 'today 12:05',
                'full' => 'Sep 13, 2026 at 12:05 UTC',
                'current' => true,
                'on_disk' => true,
            ])
            ->where('releases.1.author', 'Grace Hopper')
            ->where('releases.1.branch', 'main')
            ->where('releases.1.current', false));
});

test('only the newest five releases and the live one are still on disk', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => '20260906000000']);

    foreach (range(6, 12) as $day) {
        releasesShipped($application, sprintf('202609%02d000000', $day), hoursAgo: (13 - $day) * 24);
    }

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page
            ->has('releases', 7)
            ->where('releases', fn($releases) => collect($releases)->pluck('on_disk', 'release')->all() === [
                '20260912000000' => true,
                '20260911000000' => true,
                '20260910000000' => true,
                '20260909000000' => true,
                '20260908000000' => true,
                '20260907000000' => false,
                '20260906000000' => true,
            ])
            ->where('releases.6.current', true));
});

test('an app is reported as rolled back when a rollback came after its last deploy that went live', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => '20260912090000']);
    releasesShipped($application, '20260912090000', hoursAgo: 29);
    releasesShipped($application, '20260913120000', hoursAgo: 2);
    releasesRollback($application, 'application_rolled_back', minutesAgo: 30);

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page
            ->where('application.rolled_back', '20260912-090000')
            ->where('releases.0.current', false)
            ->where('releases.1.current', true));
});

test('a live release whose deploy failed is listed and marked current, and nothing claims a rollback', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => '20260913130000']);
    releasesShipped($application, '20260912090000', hoursAgo: 29);
    releasesShipped($application, '20260913120000', hoursAgo: 2);
    releasesWentLive($application, '20260913130000', minutesAgo: 60);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'release' => '20260913140000', 'finished_at' => now()->subMinutes(5)]);

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page
            ->where('application.rolled_back', null)
            ->where('releases', fn($releases) => collect($releases)->map(fn($release) => [$release['release'], $release['current'], $release['on_disk']])->all() === [
                ['20260913130000', true, true],
                ['20260913120000', false, true],
                ['20260912090000', false, true],
            ]));
});

test('an app is not called rolled back unless its newest rollback started and came after its last live deploy', function (Closure $history) {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => '20260912090000']);
    releasesShipped($application, '20260912090000', hoursAgo: 29);
    releasesShipped($application, '20260913120000', hoursAgo: 2);
    $history($application);

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page
            ->where('application.rolled_back', null)
            ->where('releases.1.current', true));
})->with([
    'no rollback at all' => [fn(Application $application) => null],
    'a rollback before the last deploy went live' => [fn(Application $application) => releasesRollback($application, 'application_rolled_back', minutesAgo: 3 * 60)],
    'a rollback before a failed deploy went live' => [function (Application $application): void {
        releasesRollback($application, 'application_rolled_back', minutesAgo: 30);
        releasesWentLive($application, '20260913130000', minutesAgo: 10);
    }],
    'a rollback whose processes did not start' => [fn(Application $application) => releasesRollback($application, 'rollback_processes_failed', minutesAgo: 30)],
    'a successful rollback followed by one that did not start' => [function (Application $application): void {
        releasesRollback($application, 'application_rolled_back', minutesAgo: 40);
        releasesRollback($application, 'rollback_processes_failed', minutesAgo: 30);
    }],
]);

test('a rollback where only a worker did not start is still called a rollback, because its release serves', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => '20260912090000']);
    releasesShipped($application, '20260912090000', hoursAgo: 29);
    releasesShipped($application, '20260913120000', hoursAgo: 2);
    releasesRollback($application, 'rollback_worker_failed', minutesAgo: 30);

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page->where('application.rolled_back', '20260912-090000'));
});

test('rolling forward again onto the release that went live last is not called a rollback', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => '20260913120000']);
    releasesShipped($application, '20260912090000', hoursAgo: 29);
    releasesShipped($application, '20260913120000', hoursAgo: 2);
    releasesRollback($application, 'application_rolled_back', minutesAgo: 60);
    releasesRollback($application, 'application_rolled_back', minutesAgo: 30, to: '20260913120000');

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page
            ->where('application.rolled_back', null)
            ->where('releases.0.current', true));
});

test('rolling back from a live release that did not start is called a rollback', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => '20260913120000']);
    releasesShipped($application, '20260913120000', hoursAgo: 2);
    releasesWentLive($application, '20260913130000', minutesAgo: 60);
    releasesRollback($application, 'application_rolled_back', minutesAgo: 30, to: '20260913120000');

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page
            ->where('application.rolled_back', '20260913-120000')
            ->has('releases', 1)
            ->where('releases.0.current', true));
});

test('an application on its newest release is not rolled back', function (?string $current) {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['current_release' => $current]);
    releasesShipped($application, '20260912090000', hoursAgo: 29);
    releasesShipped($application, '20260913120000', hoursAgo: 2);

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page
            ->where('application.rolled_back', null)
            ->where('releases.0.current', true));
})->with(['recorded' => '20260913120000', 'never recorded' => null]);

test('at most twenty releases are listed', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team);

    foreach (range(1, 22) as $hour) {
        releasesShipped($application, sprintf('20260912%02d0000', $hour), hoursAgo: 30 - $hour);
    }

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application))
        ->assertInertia(fn($page) => $page->has('releases', 20)->where('releases.0.release', '20260912220000'));
});

test('the page links the application and lists the team applications to switch between', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team, ['name' => 'Shop']);
    $blog = releasesApp($team, ['name' => 'Blog']);
    releasesApp(Team::factory()->create(), ['name' => 'Theirs']);

    $this->actingAs(releasesMember($team))
        ->get(releasesUrl($application, ['rollback' => '20260912090000']))
        ->assertInertia(fn($page) => $page
            ->where('application.id', $application->id)
            ->where('application.server', 'web-1')
            ->where('application.href', route('servers.applications.show', [$application->server_id, $application]))
            ->where('application.deploy_href', route('servers.applications.deploy', [$application->server_id, $application]))
            ->where('application.rollback_href', route('servers.applications.rollback', [$application->server_id, $application]))
            ->where('applications', [
                ['id' => $blog->id, 'name' => 'Blog', 'href' => releasesUrl($blog), 'current' => false],
                ['id' => $application->id, 'name' => 'Shop', 'href' => releasesUrl($application), 'current' => true],
            ])
            ->where('rollback', '20260912090000')
            ->where('can.deploy', true));
});

test('another team cannot see an applications releases', function () {
    $application = releasesApp(Team::factory()->create());

    $this->actingAs(releasesMember(Team::factory()->create(), TeamRole::Owner))
        ->get(releasesUrl($application))
        ->assertForbidden();
});

test('releases addressed through another server are a not found', function () {
    $team = Team::factory()->create();
    $application = releasesApp($team);
    $other = Server::factory()->for($team)->create();

    $this->actingAs(releasesMember($team, TeamRole::Owner))
        ->get(route('servers.applications.releases.index', [$other, $application]))
        ->assertNotFound();
});

test('the releases pages require authentication', function () {
    $application = releasesApp(Team::factory()->create());

    $this->get(route('releases.index'))->assertRedirect(route('login'));
    $this->get(releasesUrl($application))->assertRedirect(route('login'));
});
