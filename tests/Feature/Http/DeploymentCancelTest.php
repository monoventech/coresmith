<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Events\DeploymentFailed;
use App\Events\DeploymentUpdated;
use App\Events\FleetChanged;
use App\Http\Controllers\DeploymentController;
use App\Jobs\StopDeployBuild;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Panel\ActivityFeed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

function cancelEndpoint(Deployment $deployment): string
{
    return route('servers.applications.deployments.cancel', [$deployment->application->server_id, $deployment->application_id, $deployment]);
}

function cancelScreenUrl(Deployment $deployment): string
{
    return route('servers.applications.deployments.show', [$deployment->application->server_id, $deployment->application_id, $deployment]);
}

function cancelScreenMember(Team $team, TeamRole $role = TeamRole::Member, string $name = 'Maya Lindqvist'): User
{
    $user = User::factory()->create(['name' => $name]);
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function cancelScreenDeployment(Team $team, array $deployment = [], array $application = []): Deployment
{
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $app = Application::factory()->for($team)->for($server)->create(['name' => 'Shop', 'slug' => 'shop', 'current_release' => '20261019-090000', ...$application]);

    return Deployment::factory()->for($app)->create($deployment);
}

function cancelScreenRunning(Team $team, string $log = '[12:00:00] clone: fetching over https', array $deployment = []): Deployment
{
    return cancelScreenDeployment($team, [
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinute(),
        'release' => '20261020-120000',
        'log' => $log,
        ...$deployment,
    ]);
}

function cancelScreenJobDeployment(StopDeployBuild $job): Deployment
{
    return (fn(): Deployment => $this->deployment)->call($job);
}

test('a queued deploy is cancelled at once', function () {
    Queue::fake();
    Event::fake([DeploymentFailed::class]);
    $team = Team::factory()->create();
    $deployment = cancelScreenDeployment($team);
    $user = cancelScreenMember($team);

    $this->actingAs($user)
        ->from(cancelScreenUrl($deployment))
        ->post(cancelEndpoint($deployment))
        ->assertRedirect(cancelScreenUrl($deployment))
        ->assertSessionHas('success', 'Cancelled before it started. Nothing changed.');

    $fresh = $deployment->fresh();
    $activity = Activity::query()->where('description', 'deployment_cancelled')->sole();

    expect($fresh->status)->toBe(DeploymentStatus::Cancelled)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->started_at)->toBeNull()
        ->and($fresh->cancel_requested_at)->not->toBeNull()
        ->and($fresh->cancelled_by)->toBe($user->id)
        ->and($fresh->log)->toMatch('/^cancel: Maya Lindqvist asked to stop the deploy\n\[\d{2}:\d{2}:\d{2}\] deploy cancelled\. the current release is untouched\.$/')
        ->and($activity->subject_id)->toBe($deployment->application_id)
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->properties->toArray())->toBe(['by' => 'Maya Lindqvist', 'phase' => null]);

    Queue::assertNotPushed(StopDeployBuild::class);
    Event::assertNotDispatched(DeploymentFailed::class);
});

test('cancelling a queued deploy leaves the app as it was', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenDeployment($team, [], ['status' => ApplicationStatus::Failed]);

    $this->actingAs(cancelScreenMember($team))
        ->post(cancelEndpoint($deployment))
        ->assertSessionHas('success', 'Cancelled before it started. Nothing changed.');

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Failed);
});

test('a running deploy is asked to stop and its build is stopped', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenRunning($team);
    $user = cancelScreenMember($team);

    $this->actingAs($user)
        ->from(cancelScreenUrl($deployment))
        ->post(cancelEndpoint($deployment))
        ->assertRedirect(cancelScreenUrl($deployment))
        ->assertSessionHas('success', 'Cancelling. The current release keeps serving.');

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Running)
        ->and($fresh->cancel_requested_at)->not->toBeNull()
        ->and($fresh->cancelled_by)->toBe($user->id)
        ->and($fresh->finished_at)->toBeNull()
        ->and($fresh->log)->toBe('[12:00:00] clone: fetching over https')
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Queue::assertPushedOn('server-tasks', StopDeployBuild::class, fn(StopDeployBuild $job): bool => cancelScreenJobDeployment($job)->is($deployment));
    Queue::assertPushed(StopDeployBuild::class, 1);
});

test('a running deploy pushes its cancel to the open page at once', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenRunning($team);
    $pushed = [];

    Event::listen(DeploymentUpdated::class, function (DeploymentUpdated $update) use (&$pushed): void {
        $pushed[] = $update->broadcastWith();
    });

    $this->actingAs(cancelScreenMember($team))->post(cancelEndpoint($deployment));

    expect($pushed)->toHaveCount(1)
        ->and($pushed[0]['id'])->toBe($deployment->id)
        ->and($pushed[0]['cancel_requested'])->toBeTrue()
        ->and($pushed[0]['cancelled_by'])->toBe('Maya Lindqvist')
        ->and($pushed[0]['cancellable'])->toBeFalse()
        ->and($pushed[0]['state'])->toBe(['key' => 'cancelling', 'label' => 'Cancelling', 'tone' => 'default', 'live' => false]);
});

test('a cancel that cannot be pushed still stops the build', function () {
    Queue::fake();
    Exceptions::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenRunning($team);

    Event::listen(DeploymentUpdated::class, function (): void {
        throw new RuntimeException('Broadcaster is down');
    });

    $this->actingAs(cancelScreenMember($team))
        ->post(cancelEndpoint($deployment))
        ->assertSessionHas('success', 'Cancelling. The current release keeps serving.');

    expect($deployment->fresh()->cancel_requested_at)->not->toBeNull();
    Queue::assertPushed(StopDeployBuild::class, 1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Broadcaster is down');
});

test('asking twice is harmless', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenRunning($team);
    $first = cancelScreenMember($team);
    $second = cancelScreenMember($team, TeamRole::Owner, 'Ada Lovelace');

    foreach ([$first, $second] as $user) {
        $this->actingAs($user)
            ->post(cancelEndpoint($deployment))
            ->assertSessionHas('success', 'Cancelling. The current release keeps serving.');
    }

    expect($deployment->fresh()->cancelled_by)->toBe($first->id)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Running);

    Queue::assertPushed(StopDeployBuild::class, 1);
});

test('a deploy past its migrations, a running blueprint or a finished deploy is refused', function (array $attributes, string $error) {
    Queue::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenDeployment($team, ['release' => '20261020-120000', ...$attributes]);
    $before = $deployment->fresh()->getAttributes();

    $this->actingAs(cancelScreenMember($team))
        ->from(cancelScreenUrl($deployment))
        ->post(cancelEndpoint($deployment))
        ->assertRedirect(cancelScreenUrl($deployment))
        ->assertSessionHas('error', $error)
        ->assertSessionMissing('success');

    expect($deployment->fresh()->getAttributes())->toBe($before)
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'running its migrations' => [
        ['status' => DeploymentStatus::Running, 'log' => "[12:00:00] clone: fetching over https\n[12:00:40] migrate: running migrations against release 20261020-120000"],
        'Migrations have started, so this deploy finishes on its own. Roll back afterwards if you need to.',
    ],
    'checking its health' => [
        ['status' => DeploymentStatus::Running, 'log' => "[12:00:00] clone: fetching over https\n[12:00:41] health: checking the release before it goes live"],
        'Migrations have started, so this deploy finishes on its own. Roll back afterwards if you need to.',
    ],
    'past the swap' => [
        ['status' => DeploymentStatus::Running, 'log' => "[12:00:00] clone: fetching over https\n[12:00:42] swap: current → releases/20261020-120000"],
        'Migrations have started, so this deploy finishes on its own. Roll back afterwards if you need to.',
    ],
    'running a custom blueprint' => [
        ['status' => DeploymentStatus::Running, 'log' => "[12:00:00] clone: fetching over https\n[12:00:30] build: running the custom blueprint"],
        "This app deploys with a custom blueprint, which can run migrations, so it can't be stopped partway.",
    ],
    'deployed' => [['status' => DeploymentStatus::Succeeded], 'This deploy has already finished.'],
    'failed' => [['status' => DeploymentStatus::Failed], 'This deploy has already finished.'],
    'cancelled' => [['status' => DeploymentStatus::Cancelled], 'This deploy has already finished.'],
]);

test('members may cancel what they may deploy, another team is refused and a mismatched address is not found', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenDeployment($team);
    $application = $deployment->application;
    $sibling = Application::factory()->for($team)->for($application->server)->create(['name' => 'Blog']);
    $siblingDeployment = Deployment::factory()->for($sibling)->create();
    $otherServer = Server::factory()->for($team)->create();
    $owner = cancelScreenMember($team, TeamRole::Owner, 'Ada Lovelace');

    $this->actingAs(cancelScreenMember(Team::factory()->create(), TeamRole::Owner, 'Eve Outsider'))
        ->post(cancelEndpoint($deployment))
        ->assertForbidden();

    foreach ([
        [$otherServer, $application, $deployment],
        [$application->server_id, $sibling, $deployment],
        [$application->server_id, $application, $siblingDeployment],
    ] as $address) {
        $this->actingAs($owner)
            ->post(route('servers.applications.deployments.cancel', $address))
            ->assertNotFound();
    }

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Pending)
        ->and($siblingDeployment->fresh()->status)->toBe(DeploymentStatus::Pending);

    $this->actingAs(cancelScreenMember($team))
        ->post(cancelEndpoint($deployment))
        ->assertSessionHas('success', 'Cancelled before it started. Nothing changed.');

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled);
});

test('the cancel route has its own rate limit', function () {
    expect(Route::getRoutes()->getByName('servers.applications.deployments.cancel')->gatherMiddleware())
        ->toContain('throttle:10,1,deploy-cancel');
});

test('the deploy page says it is stopping', function () {
    $team = Team::factory()->create();
    $user = cancelScreenMember($team);
    $deployment = cancelScreenRunning($team, deployment: ['cancel_requested_at' => now(), 'cancelled_by' => $user->id]);

    $this->actingAs($user)
        ->get(cancelScreenUrl($deployment))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('deployment.status', 'running')
            ->where('deployment.cancel_requested', true)
            ->where('deployment.cancellable', false)
            ->where('deployment.cancelled_by', 'Maya Lindqvist')
            ->where('deployment.state', ['key' => 'cancelling', 'label' => 'Cancelling', 'tone' => 'default', 'live' => false])
            ->where('deployment_links.cancel', cancelEndpoint($deployment))
            ->missing('deployment.cancel_href'));
});

test('the deploy page offers a cancel until the migrations start', function (array $attributes, bool $cancellable) {
    $team = Team::factory()->create();
    $deployment = cancelScreenDeployment($team, ['release' => '20261020-120000', ...$attributes]);

    $this->actingAs(cancelScreenMember($team))
        ->get(cancelScreenUrl($deployment))
        ->assertInertia(fn($page) => $page
            ->where('deployment.cancellable', $cancellable)
            ->where('deployment.cancel_requested', false)
            ->where('deployment.cancelled_by', null));
})->with([
    'queued' => [['status' => DeploymentStatus::Pending], true],
    'cloning' => [['status' => DeploymentStatus::Running, 'log' => '[12:00:00] clone: fetching over https'], true],
    'building' => [['status' => DeploymentStatus::Running, 'log' => "[12:00:00] clone: fetching over https\n[12:00:30] build: building the release"], true],
    'running its migrations' => [['status' => DeploymentStatus::Running, 'log' => '[12:00:40] migrate: running migrations against release 20261020-120000'], false],
    'running a custom blueprint' => [['status' => DeploymentStatus::Running, 'log' => '[12:00:30] build: running the custom blueprint'], false],
    'deployed' => [['status' => DeploymentStatus::Succeeded], false],
    'cancelled' => [['status' => DeploymentStatus::Cancelled], false],
]);

test('the cancel link keeps the team address the page was opened under', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $deployment = cancelScreenRunning($team);
    $path = '/servers/' . $deployment->application->server_id . '/applications/' . $deployment->application_id . '/deployments/' . $deployment->id;

    $this->actingAs(cancelScreenMember($team))
        ->get('/acme' . $path)
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('deployment_links.cancel', fn(string $href): bool => str_ends_with($href, '/acme' . $path . '/cancel')));
});

test('the pushed deploy payload says who cancelled and carries no link', function () {
    $team = Team::factory()->create();
    $user = cancelScreenMember($team);
    $deployment = cancelScreenDeployment($team, ['status' => DeploymentStatus::Cancelled, 'cancel_requested_at' => now(), 'cancelled_by' => $user->id]);

    $payload = (new DeploymentUpdated($deployment->fresh()))->broadcastWith();

    expect($payload)->toMatchArray(['cancel_requested' => true, 'cancelled_by' => 'Maya Lindqvist', 'cancellable' => false])
        ->and(array_filter(array_keys($payload), fn(string $key): bool => str_ends_with($key, '_href')))->toBe([])
        ->and($payload)->toBe(DeploymentController::present($deployment->fresh(), app(DeploymentPhases::class)));
});

test('deploy updates are only broadcast when a broadcaster is configured', function (string $driver, bool $broadcast) {
    config(['broadcasting.default' => $driver]);
    $deployment = cancelScreenDeployment(Team::factory()->create());

    expect((new DeploymentUpdated($deployment))->broadcastWhen())->toBe($broadcast);
})->with([
    ['null', false],
    ['log', false],
    ['', false],
    ['reverb', true],
]);

test('a cancel pings the team once it is recorded', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenDeployment($team);
    $seen = [];

    Event::listen(FleetChanged::class, function (FleetChanged $ping) use (&$seen): void {
        $seen[] = [$ping->teamId, $ping->kind, $ping->subjectId, Deployment::query()->findOrFail($ping->subjectId)->status];
    });

    $this->actingAs(cancelScreenMember($team))->post(cancelEndpoint($deployment));

    expect($seen)->toBe([[$team->id, 'deployment', $deployment->id, DeploymentStatus::Cancelled]]);
});

test('the feed says who cancelled a deploy and that nothing changed', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $deployment = cancelScreenDeployment($team);

    $this->actingAs(cancelScreenMember($team))->post(cancelEndpoint($deployment));

    $row = collect(app(ActivityFeed::class)->forTeam($team))->sole();

    expect($row['parts'])->toBe([
        ['text' => 'Deploy cancelled, ', 'mono' => false],
        ['text' => 'Shop', 'mono' => true],
        ['text' => ', by Maya Lindqvist, nothing changed', 'mono' => false],
    ])->and($row['href'])->toBe(route('servers.applications.show', [$deployment->application->server_id, $deployment->application_id]));
});

test('the feed leaves out a name it no longer knows', function () {
    $team = Team::factory()->create();
    $deployment = cancelScreenDeployment($team);

    activity()->performedOn($deployment->application)->withProperties(['by' => null, 'phase' => 'build'])->log('deployment_cancelled');

    expect(implode('', array_column(collect(app(ActivityFeed::class)->forTeam($team))->sole()['parts'], 'text')))
        ->toBe('Deploy cancelled, Shop, nothing changed');
});
