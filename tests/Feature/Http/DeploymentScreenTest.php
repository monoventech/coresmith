<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Jobs\DeployApplication;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Queue;

function screenMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function screenApp(Team $team, array $attributes = []): Application
{
    return Application::factory()->for($team)->for(Server::factory()->for($team)->create(['name' => 'web-1']))->create(array_merge([
        'name' => 'Shop',
        'repository_branch' => 'production',
    ], $attributes));
}

function screenUrl(Deployment $deployment, array $query = []): string
{
    return route('servers.applications.deployments.show', [$deployment->application->server_id, $deployment->application_id, $deployment, ...$query]);
}

function screenPrivate(object $job, string $property): mixed
{
    return (fn() => $this->{$property})->call($job);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('deploying records who asked and which branch, queues the build and opens the deploy screen', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);
    $user = screenMember($team);

    $response = $this->actingAs($user)
        ->post(route('servers.applications.deploy', [$application->server_id, $application]), ['from' => 'releases']);

    $deployment = Deployment::query()->sole();

    $response->assertRedirect(screenUrl($deployment, ['from' => 'releases']))
        ->assertSessionHas('success', 'Deploying Shop. The current release keeps serving until the symlink swap.');

    expect($deployment->triggered_by)->toBe($user->id)
        ->and($deployment->branch)->toBe('production')
        ->and($deployment->application_id)->toBe($application->id);

    Queue::assertPushed(DeployApplication::class, fn(DeployApplication $job): bool => screenPrivate($job, 'deployment')->is($deployment)
        && screenPrivate($job, 'timestamp') === '20260913-140509');
});

test('the deploy screen remembers every known origin', function (string $from) {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);

    $response = $this->actingAs(screenMember($team))
        ->post(route('servers.applications.deploy', [$application->server_id, $application]), ['from' => $from]);

    $response->assertRedirect(screenUrl(Deployment::query()->sole(), ['from' => $from]));
})->with(['fleet', 'deployments', 'app', 'releases', 'setup']);

test('a missing or unknown origin sends the deploy screen back to the fleet', function (array $payload) {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);

    $response = $this->actingAs(screenMember($team))
        ->post(route('servers.applications.deploy', [$application->server_id, $application]), $payload);

    $response->assertRedirect(screenUrl(Deployment::query()->sole(), ['from' => 'fleet']));
})->with([
    'missing' => [[]],
    'unknown' => [['from' => 'https://evil.example']],
]);

test('another team cannot deploy and nothing is created', function () {
    Queue::fake();
    $application = screenApp(Team::factory()->create());

    $this->actingAs(screenMember(Team::factory()->create(), TeamRole::Owner))
        ->post(route('servers.applications.deploy', [$application->server_id, $application]))
        ->assertForbidden();

    expect(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('a deploy addressed through another server is a not found', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);
    $other = Server::factory()->for($team)->create();

    $this->actingAs(screenMember($team, TeamRole::Owner))
        ->post(route('servers.applications.deploy', [$other, $application]))
        ->assertNotFound();

    expect(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('the deploy screen carries the phases, the failure and the release details', function () {
    $team = Team::factory()->create();
    $application = screenApp($team, ['current_release' => '20260912090000']);
    $deployment = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Failed,
        'release' => '20260913140000',
        'branch' => 'production',
        'commit_hash' => '4f3c2b1a0e9d8c7b6a5f',
        'commit_message' => 'Ship the release screen',
        'tag' => 'v2.4.0',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
        'log' => implode("\n", [
            '[14:00:09] clone: fetching over https',
            '[14:00:12] deps: installing dependencies',
            '[14:00:40] migrate: running migrations against release 20260913140000',
            '[14:00:51] deploy failed. the current release is untouched.',
        ]),
    ]);

    $this->actingAs(screenMember($team))
        ->get(screenUrl($deployment))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('deployments/Show')
            ->where('server', ['id' => $application->server_id, 'name' => 'web-1', 'href' => route('servers.show', $application->server_id)])
            ->where('application.name', 'Shop')
            ->where('application.current_release', '20260912-090000')
            ->where('application.releases_href', route('servers.applications.releases.index', [$application->server_id, $application]))
            ->where('deployment.id', $deployment->id)
            ->where('deployment.status', 'failed')
            ->where('deployment.state.label', 'Failed, no impact')
            ->has('deployment.phases', 6)
            ->where('deployment.phases.3', ['key' => 'migrate', 'label' => 'Run migrations', 'state' => 'failed', 'duration' => '11s'])
            ->where('deployment.failed_phase', 'migrate')
            ->where('deployment.failed_step', null)
            ->where('deployment.reached_users', false)
            ->where('deployment.release', '20260913-140000')
            ->where('deployment.branch', 'production')
            ->where('deployment.commit', '4f3c2b1')
            ->where('deployment.commit_message', 'Ship the release screen')
            ->where('deployment.tag', 'v2.4.0')
            ->where('deployment.started_at', '2026-09-13T14:00:09+00:00')
            ->where('can.deploy', true));
});

test('a deploy whose storage copy failed names that step, while the rail shows the clone phase failed', function () {
    $team = Team::factory()->create();
    $deployment = Deployment::factory()->for(screenApp($team, ['current_release' => '20260912090000']))->create([
        'status' => DeploymentStatus::Failed,
        'release' => '20260913140000',
        'failed_step' => 'storage',
        'failure_reason' => null,
        'log' => implode("\n", [
            '[14:00:09] clone: fetching over https',
            "Could not copy the live release's storage into shared/storage, so nothing was changed. Fix the error above, then deploy again.",
            '[14:00:11] deploy failed. the current release is untouched.',
        ]),
    ]);

    $this->actingAs(screenMember($team))
        ->get(screenUrl($deployment))
        ->assertInertia(fn($page) => $page
            ->where('deployment.failed_step', 'storage')
            ->where('deployment.failed_phase', 'clone')
            ->where('deployment.phases.0.state', 'failed')
            ->where('deployment.failure_reason', null));
});

test('a deploy whose processes did not start is named a start failure, with every phase through the swap done', function () {
    $team = Team::factory()->create();
    $deployment = Deployment::factory()->for(screenApp($team, ['current_release' => '20260913140000']))->create([
        'status' => DeploymentStatus::Failed,
        'release' => '20260913140000',
        'start_failed' => true,
        'failure_reason' => 'coresmith-app-shop-web did not start, systemd reports it as activating. The process log says why. Fix it and deploy again, or roll back to the previous release.',
        'log' => implode("\n", [
            '[14:00:09] clone: fetching over https',
            '[14:00:12] deps: installing dependencies',
            '[14:00:40] build: building the release',
            '[14:00:51] migrate: running migrations against release 20260913140000',
            '[14:00:53] health: checking the release before it goes live',
            '[14:00:54] swap: current → releases/20260913140000',
            '[14:00:55] watch: starting coresmith-app-shop-web',
            '[14:00:58] deploy failed after the symlink swap. releases/20260913140000 is live, check its processes.',
        ]),
    ]);

    $this->actingAs(screenMember($team))
        ->get(screenUrl($deployment))
        ->assertInertia(fn($page) => $page
            ->where('deployment.start_failed', true)
            ->where('deployment.failed_worker', null)
            ->where('deployment.reached_users', true)
            ->where('deployment.failed_phase', null)
            ->where('deployment.phases', fn($phases) => collect($phases)->pluck('state')->unique()->values()->all() === ['done']));
});

test('a deploy that failed any other way is not named a start failure', function () {
    $team = Team::factory()->create();
    $deployment = Deployment::factory()->for(screenApp($team))->create(['status' => DeploymentStatus::Failed]);

    $this->actingAs(screenMember($team))
        ->get(screenUrl($deployment))
        ->assertInertia(fn($page) => $page->where('deployment.start_failed', false)->where('deployment.failed_worker', null));
});

test('a deploy whose worker did not start names the worker', function () {
    $team = Team::factory()->create();
    $deployment = Deployment::factory()->for(screenApp($team))->create(['status' => DeploymentStatus::Failed, 'start_failed' => true, 'failed_worker' => 'Sidekiq']);

    $this->actingAs(screenMember($team))
        ->get(screenUrl($deployment))
        ->assertInertia(fn($page) => $page->where('deployment.start_failed', true)->where('deployment.failed_worker', 'Sidekiq'));
});

test('a failed deploy carries the reason it failed', function () {
    $team = Team::factory()->create();
    $reason = "Branch release/2.0 is not in the repository. Check the branch name in the app's settings, then deploy again.";
    $deployment = Deployment::factory()->for(screenApp($team))->create(['status' => DeploymentStatus::Failed, 'failure_reason' => $reason]);

    $this->actingAs(screenMember($team))
        ->get(screenUrl($deployment))
        ->assertInertia(fn($page) => $page->where('deployment.failure_reason', $reason));
});

test('the back link follows where the deploy was started from', function (?string $from, string $label, string $route) {
    $team = Team::factory()->create();
    $application = screenApp($team);
    $deployment = Deployment::factory()->for($application)->create();
    $expected = match ($route) {
        'app' => route('servers.applications.show', [$application->server_id, $application]),
        'releases' => route('servers.applications.releases.index', [$application->server_id, $application]),
        'deployments' => route('deployments.index'),
        default => route('dashboard'),
    };

    $this->actingAs(screenMember($team))
        ->get(screenUrl($deployment, $from === null ? [] : ['from' => $from]))
        ->assertInertia(fn($page) => $page->where('back', ['label' => $label, 'href' => $expected]));
})->with([
    'the application' => ['app', 'Shop', 'app'],
    'the deployments list' => ['deployments', 'Deployments', 'deployments'],
    'the releases list' => ['releases', 'Releases', 'releases'],
    'the fleet' => ['fleet', 'Fleet', 'fleet'],
    'setup' => ['setup', 'Fleet', 'fleet'],
    'nowhere' => [null, 'Fleet', 'fleet'],
    'somewhere unknown' => ['elsewhere', 'Fleet', 'fleet'],
]);

test('live streaming is only offered when a broadcaster is configured', function (string $broadcaster, bool $streaming) {
    config(['broadcasting.default' => $broadcaster]);
    $team = Team::factory()->create();
    $deployment = Deployment::factory()->for(screenApp($team))->create();

    $this->actingAs(screenMember($team))
        ->get(screenUrl($deployment))
        ->assertInertia(fn($page) => $page->where('streaming', $streaming));
})->with([
    ['null', false],
    ['log', false],
    ['reverb', true],
]);

test('a deploy screen for another application or server is a not found', function () {
    $team = Team::factory()->create();
    $application = screenApp($team);
    $sibling = screenApp($team, ['name' => 'Blog']);
    $deployment = Deployment::factory()->for($sibling)->create();
    $member = screenMember($team, TeamRole::Owner);

    $this->actingAs($member)
        ->get(route('servers.applications.deployments.show', [$application->server_id, $application, $deployment]))
        ->assertNotFound();

    $this->actingAs($member)
        ->get(route('servers.applications.deployments.show', [$application->server_id, $sibling, $deployment]))
        ->assertNotFound();
});

test('another team cannot open a deploy screen', function () {
    $deployment = Deployment::factory()->for(screenApp(Team::factory()->create()))->create();

    $this->actingAs(screenMember(Team::factory()->create(), TeamRole::Owner))
        ->get(screenUrl($deployment))
        ->assertForbidden();
});

test('rolling back to a release of this application queues exactly that release', function (string $release, string $shown) {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => $release]);

    $this->actingAs(screenMember($team))
        ->post(route('servers.applications.rollback', [$application->server_id, $application]), ['release' => $release])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', "Rolling back. The symlink re-points to releases/{$shown}, nothing is rebuilt.");

    Queue::assertPushed(RollbackApplication::class, fn(RollbackApplication $job): bool => screenPrivate($job, 'release') === $release
        && screenPrivate($job, 'application')->is($application));
})->with([
    'compact' => ['20260912090000', '20260912-090000'],
    'split' => ['20260912-090000', '20260912-090000'],
]);

test('rolling back without a release goes to the previous one', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);

    $this->actingAs(screenMember($team))
        ->post(route('servers.applications.rollback', [$application->server_id, $application]))
        ->assertSessionHas('success', 'Rolling back. The symlink re-points to the previous release, nothing is rebuilt.');

    Queue::assertPushed(RollbackApplication::class, fn(RollbackApplication $job): bool => screenPrivate($job, 'release') === null);
});

test('a release that does not belong to this application is refused', function (string $release) {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);
    Deployment::factory()->for(screenApp($team, ['name' => 'Blog']))->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260912090000']);

    $this->actingAs(screenMember($team))
        ->post(route('servers.applications.rollback', [$application->server_id, $application]), ['release' => $release])
        ->assertSessionHasErrors('release');

    Queue::assertNothingPushed();
})->with([
    'another applications release' => '20260912090000',
    'a release never deployed' => '20260101000000',
    'not a release name' => '../../etc/passwd',
    'an injection' => '20260912090000; reboot',
]);

test('a release name ending in a newline is refused by its format, even when the request is not trimmed', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => "20261019-120000\n"]);

    $this->withoutMiddleware(TrimStrings::class)
        ->actingAs(screenMember($team))
        ->post(route('servers.applications.rollback', [$application->server_id, $application]), ['release' => "20261019-120000\n"])
        ->assertSessionHasErrors(['release' => 'The release field format is invalid.']);

    Queue::assertNothingPushed();
});

test('another team cannot roll back, and a rollback through another server is a not found', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = screenApp($team);
    $other = Server::factory()->for($team)->create();

    $this->actingAs(screenMember(Team::factory()->create(), TeamRole::Owner))
        ->post(route('servers.applications.rollback', [$application->server_id, $application]))
        ->assertForbidden();

    $this->actingAs(screenMember($team, TeamRole::Owner))
        ->post(route('servers.applications.rollback', [$other, $application]))
        ->assertNotFound();

    Queue::assertNothingPushed();
});
