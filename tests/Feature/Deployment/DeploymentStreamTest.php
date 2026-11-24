<?php

declare(strict_types=1);

use App\Broadcasting\DeploymentChannel;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Events\DeploymentLogAppended;
use App\Events\DeploymentUpdated;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

function watcher(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function deploymentFor(Team $team): Deployment
{
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    return Deployment::factory()->for($application)->create();
}

function deploymentStreamAuthorize(User $user, string $channel): TestResponse
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'stream-key',
        'broadcasting.connections.reverb.secret' => 'stream-secret',
        'broadcasting.connections.reverb.app_id' => 'stream-app',
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');

    return test()->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

test('the deploy page is let into its deployment channel through the broadcasting endpoint', function (TeamRole $role) {
    $team = Team::factory()->create();
    $deployment = deploymentFor($team);

    deploymentStreamAuthorize(watcher($team, $role), 'private-deployments.' . $deployment->getKey())
        ->assertOk()
        ->assertJsonStructure(['auth']);
})->with([TeamRole::Owner, TeamRole::Member]);

test('the broadcasting endpoint keeps someone outside the team out of a deployment channel', function () {
    $deployment = deploymentFor(Team::factory()->create());

    deploymentStreamAuthorize(watcher(Team::factory()->create(), TeamRole::Owner), 'private-deployments.' . $deployment->getKey())
        ->assertForbidden();
});

test('the broadcasting endpoint lets a team member into the team channel', function () {
    $team = Team::factory()->create();

    deploymentStreamAuthorize(watcher($team, TeamRole::Member), 'private-teams.' . $team->getKey())
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('the update is broadcast on a channel private to that deployment', function () {
    $deployment = deploymentFor(Team::factory()->create());
    $event = new DeploymentUpdated($deployment);

    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-deployments.' . $deployment->getKey())
        ->and($event->broadcastAs())->toBe('deployment.updated');
});

test('a status update carries the status and how long the log is, never the log itself', function () {
    $deployment = deploymentFor(Team::factory()->create());
    $deployment->forceFill([
        'status' => DeploymentStatus::Running,
        'log' => "composer install\nnpm run build",
        'started_at' => now(),
    ])->save();

    $payload = (new DeploymentUpdated($deployment->fresh()))->broadcastWith();

    expect($payload)->toHaveKeys(['id', 'status', 'log_bytes', 'started_at', 'finished_at'])
        ->and($payload['status'])->toBe('running')
        ->and($payload['log_bytes'])->toBe(strlen("composer install\nnpm run build"))
        ->and($payload)->not->toHaveKey('log')
        ->and($payload)->not->toHaveKey('application');
});

test('a piece of the log is broadcast on the deployment channel with only its offset and text', function () {
    $deployment = deploymentFor(Team::factory()->create());
    $event = new DeploymentLogAppended($deployment->id, 42, "\n\$ npm ci");

    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0]->name)->toBe('private-deployments.' . $deployment->id)
        ->and($event->broadcastAs())->toBe('deployment.log')
        ->and($event->broadcastWith())->toBe(['offset' => 42, 'text' => "\n\$ npm ci"]);
});

function deploymentStreamRunning(int $lines): Deployment
{
    $deployment = deploymentFor(Team::factory()->create());
    $deployment->forceFill([
        'status' => DeploymentStatus::Running,
        'log' => implode("\n", array_fill(0, $lines, '[00:11:37] deps: npm warn deprecated "inflight@1.0.6": This module is not supported')),
        'started_at' => now(),
    ])->save();

    return $deployment->fresh();
}

function deploymentStreamRequestBytes(Deployment $deployment, array $payload): int
{
    return strlen((string) json_encode([
        'name' => 'deployment.updated',
        'data' => json_encode($payload),
        'channels' => ['private-deployments.' . $deployment->getKey()],
    ]));
}

test('every update fits the socket server with room for its headers, however long the log grows', function (int $lines) {
    $deployment = deploymentStreamRunning($lines);

    $payload = (new DeploymentUpdated($deployment))->broadcastWith();

    expect(deploymentStreamRequestBytes($deployment, $payload) + 1_000)->toBeLessThanOrEqual((int) config('reverb.servers.reverb.max_request_size'));
})->with([1, 40, 70, 85, 100, 400]);

test('a status update for a long log still says how long it is, so the page can fetch what it missed', function () {
    $deployment = deploymentStreamRunning(400);

    $payload = (new DeploymentUpdated($deployment))->broadcastWith();

    expect($payload['log_bytes'])->toBe(strlen((string) $deployment->log))
        ->and($payload['status'])->toBe('running')
        ->and($payload['id'])->toBe($deployment->getKey());
});

test('a member of the team may listen to the deployment channel', function () {
    $team = Team::factory()->create();
    $deployment = deploymentFor($team);

    expect((new DeploymentChannel)->join(watcher($team, TeamRole::Member), $deployment->getKey()))->toBeTrue();
});

test('an owner and an admin may listen too', function (TeamRole $role) {
    $team = Team::factory()->create();
    $deployment = deploymentFor($team);

    expect((new DeploymentChannel)->join(watcher($team, $role), $deployment->getKey()))->toBeTrue();
})->with([TeamRole::Owner, TeamRole::Admin]);

test('someone outside the team may not listen to the deployment channel', function () {
    $deployment = deploymentFor(Team::factory()->create());
    $outsider = watcher(Team::factory()->create(), TeamRole::Owner);

    expect((new DeploymentChannel)->join($outsider, $deployment->getKey()))->toBeFalse();
});

test('a user belonging to no team at all may not listen', function () {
    $deployment = deploymentFor(Team::factory()->create());

    expect((new DeploymentChannel)->join(User::factory()->create(), $deployment->getKey()))->toBeFalse();
});

test('a channel naming a deployment that does not exist is refused', function (string $name) {
    $user = watcher(Team::factory()->create(), TeamRole::Owner);

    expect((new DeploymentChannel)->join($user, $name))->toBeFalse();
})->with(['01JQQQQQQQQQQQQQQQQQQQQQQQ', '', 'not-an-id', '1 OR 1=1']);

test('the deployment page is visible to the team and shows the log', function () {
    $team = Team::factory()->create();
    $deployment = deploymentFor($team);
    $deployment->forceFill(['log' => 'Cloning into bare repository', 'status' => DeploymentStatus::Running])->save();

    $this->actingAs(watcher($team, TeamRole::Member))
        ->get(route('servers.applications.deployments.show', [
            $deployment->application->server,
            $deployment->application,
            $deployment,
        ]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('deployments/Show')
            ->where('deployment.log', 'Cloning into bare repository')
            ->where('deployment.status', 'running')
            ->where('streaming', false));
});

test('the page is told streaming is available when a broadcaster is configured', function () {
    config(['broadcasting.default' => 'reverb']);
    $team = Team::factory()->create();
    $deployment = deploymentFor($team);

    $this->actingAs(watcher($team, TeamRole::Owner))
        ->get(route('servers.applications.deployments.show', [
            $deployment->application->server,
            $deployment->application,
            $deployment,
        ]))
        ->assertInertia(fn($page) => $page->where('streaming', true));
});

test('the page falls back to refreshing when no broadcaster is configured', function (string $driver) {
    config(['broadcasting.default' => $driver]);
    $team = Team::factory()->create();
    $deployment = deploymentFor($team);

    $this->actingAs(watcher($team, TeamRole::Owner))
        ->get(route('servers.applications.deployments.show', [
            $deployment->application->server,
            $deployment->application,
            $deployment,
        ]))
        ->assertInertia(fn($page) => $page->where('streaming', false));
})->with(['null', 'log', '']);

test('another teams deployment page is refused', function () {
    $deployment = deploymentFor(Team::factory()->create());
    $outsider = watcher(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)
        ->get(route('servers.applications.deployments.show', [
            $deployment->application->server,
            $deployment->application,
            $deployment,
        ]))
        ->assertNotFound();
});

test('a deployment belonging to a different application is a not found', function () {
    $team = Team::factory()->create();
    $deployment = deploymentFor($team);
    $other = Application::factory()->for($team)->for($deployment->application->server)->create();

    $this->actingAs(watcher($team, TeamRole::Owner))
        ->get(route('servers.applications.deployments.show', [
            $deployment->application->server,
            $other,
            $deployment,
        ]))
        ->assertNotFound();
});

test('a deployment reached through the wrong server is a not found', function () {
    $team = Team::factory()->create();
    $deployment = deploymentFor($team);
    $otherServer = Server::factory()->for($team)->create();

    $this->actingAs(watcher($team, TeamRole::Owner))
        ->get(route('servers.applications.deployments.show', [
            $otherServer,
            $deployment->application,
            $deployment,
        ]))
        ->assertNotFound();
});

test('the page is behind authentication', function () {
    $deployment = deploymentFor(Team::factory()->create());

    $this->get(route('servers.applications.deployments.show', [
        $deployment->application->server,
        $deployment->application,
        $deployment,
    ]))->assertRedirect(route('login'));
});

test('an update is broadcast when the log grows and when the run ends', function () {
    Event::fake([DeploymentUpdated::class]);

    $deployment = deploymentFor(Team::factory()->create());

    DeploymentUpdated::dispatch($deployment);

    Event::assertDispatched(DeploymentUpdated::class, fn(DeploymentUpdated $event) => $event->deployment->is($deployment));
});
