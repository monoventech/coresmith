<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Jobs\PruneReleases;
use App\Jobs\RebootServer;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function housekeepingMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('an admin can reboot a server that is not deploying', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    $this->actingAs(housekeepingMember($team, TeamRole::Admin))
        ->from(route('servers.show', $server))
        ->post(route('servers.reboot', $server))
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('success', 'Reboot scheduled. web-1 restarts in a few seconds, apps come back via systemd.');

    Queue::assertPushed(RebootServer::class, 1);
});

test('a server Coresmith cannot reach is not sent a reboot over SSH', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'unreachable_since' => now()->subMinutes(5)]);

    $this->actingAs(housekeepingMember($team, TeamRole::Admin))
        ->from(route('servers.show', $server))
        ->post(route('servers.reboot', $server))
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('error', "Coresmith can't reach web-1 over SSH, so it can't reboot it. Restart the machine yourself.")
        ->assertSessionMissing('success');

    Queue::assertNotPushed(RebootServer::class);
});

test('a reboot is refused while a deployment is running on that server', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $application = Application::factory()->for($team)->for($server)->create();
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running, 'started_at' => now()]);

    $this->actingAs(housekeepingMember($team, TeamRole::Owner))
        ->from(route('servers.show', $server))
        ->post(route('servers.reboot', $server))
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('error', 'A deploy is running on web-1. Reboot once it finishes.')
        ->assertSessionMissing('success');

    Queue::assertNotPushed(RebootServer::class);
});

test('a deployment running on another server does not block a reboot', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $elsewhere = Application::factory()->for($team)->for(Server::factory()->for($team))->create();
    Deployment::factory()->for($elsewhere)->create(['status' => DeploymentStatus::Running]);

    $this->actingAs(housekeepingMember($team, TeamRole::Admin))
        ->post(route('servers.reboot', $server))
        ->assertSessionHas('success');

    Queue::assertPushed(RebootServer::class);
});

test('finished deployments on the server do not block a reboot', function (DeploymentStatus $status) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();
    Deployment::factory()->for($application)->create(['status' => $status]);

    $this->actingAs(housekeepingMember($team, TeamRole::Admin))
        ->post(route('servers.reboot', $server))
        ->assertSessionHas('success');

    Queue::assertPushed(RebootServer::class);
})->with([DeploymentStatus::Succeeded, DeploymentStatus::Failed, DeploymentStatus::Cancelled, DeploymentStatus::Pending]);

test('a member cannot reboot or prune a server', function (string $route, string $job) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(housekeepingMember($team, TeamRole::Member))
        ->post(route($route, $server))
        ->assertForbidden();

    Queue::assertNotPushed($job);
})->with([
    'reboot' => ['servers.reboot', RebootServer::class],
    'prune' => ['servers.prune-releases', PruneReleases::class],
]);

test('another teams server cannot be rebooted or pruned', function (string $route) {
    Queue::fake();
    $server = Server::factory()->for(Team::factory())->create();

    $this->actingAs(housekeepingMember(Team::factory()->create(), TeamRole::Owner))
        ->post(route($route, $server))
        ->assertForbidden();

    Queue::assertNothingPushed();
})->with(['servers.reboot', 'servers.prune-releases']);

test('an admin can prune old releases', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(housekeepingMember($team, TeamRole::Admin))
        ->from(route('servers.show', $server))
        ->post(route('servers.prune-releases', $server))
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('success', 'Pruning old releases. Each app keeps its last 5 and the live one, disk readings refresh within a minute.');

    Queue::assertPushed(PruneReleases::class, 1);
});

test('pruning is allowed while a deployment runs, since the live release is always kept', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running]);

    $this->actingAs(housekeepingMember($team, TeamRole::Admin))
        ->post(route('servers.prune-releases', $server))
        ->assertSessionHas('success');

    Queue::assertPushed(PruneReleases::class);
});

test('reboot and prune require authentication', function (string $route) {
    Queue::fake();
    $server = Server::factory()->create();

    $this->post(route($route, $server))->assertRedirect(route('login'));

    Queue::assertNothingPushed();
})->with(['servers.reboot', 'servers.prune-releases']);
