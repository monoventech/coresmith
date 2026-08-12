<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Jobs\ManageFirewallRule;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function tabUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('the deployments tab lists the applications full history', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    Deployment::factory()->for($application)->count(3)->create();
    Deployment::factory()->for(Application::factory()->for($team)->for($server))->create();

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.applications.deployments.index', [$server, $application]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('applications/Deployments')->has('deployments', 3));
});

test('the features tab carries the toggles and what the application runs', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create([
        'power_state' => PowerState::Stopped,
        'toggles' => ['maintenance_mode' => true],
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
    ]);

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.applications.features', [$server, $application]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('applications/Features')
            ->where('application.power_state', 'stopped')
            ->where('application.toggles.maintenance_mode', true)
            ->has('application.processes', 1)
            ->where('can.update', false));
});

test('a deployment or feature page for another server is a not found', function (string $name) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $other = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(tabUser($team, TeamRole::Owner))
        ->get(route($name, [$other, $application]))
        ->assertNotFound();
})->with(['servers.applications.deployments.index', 'servers.applications.features']);

test('another teams application tabs are refused', function (string $name) {
    $application = Application::factory()->for(Team::factory())->create();

    $this->actingAs(tabUser(Team::factory()->create(), TeamRole::Owner))
        ->get(route($name, [$application->server_id, $application]))
        ->assertForbidden();
})->with(['servers.applications.deployments.index', 'servers.applications.features']);

test('the firewall tab lists only the changes coresmith applied to that server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $other = Server::factory()->for($team)->create();

    activity()->performedOn($server)->withProperties(['action' => 'allow', 'port' => 8080])->log('firewall_rule_changed');
    activity()->performedOn($other)->withProperties(['action' => 'deny', 'port' => 9000])->log('firewall_rule_changed');
    activity()->performedOn($server)->log('something_else');

    $this->actingAs(tabUser($team, TeamRole::Owner))
        ->get(route('servers.firewall.index', $server))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('servers/Firewall')
            ->has('changes', 1)
            ->where('changes.0.port', 8080));
});

test('an admin can queue a firewall change', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(tabUser($team, TeamRole::Admin))
        ->post(route('servers.firewall.store', $server), ['action' => 'allow', 'port' => 8080])
        ->assertRedirect();

    Queue::assertPushed(ManageFirewallRule::class);
});

test('the ssh port cannot be denied, because that locks coresmith out', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['ssh_port' => 2222]);

    $this->actingAs(tabUser($team, TeamRole::Admin))
        ->post(route('servers.firewall.store', $server), ['action' => 'deny', 'port' => 2222])
        ->assertSessionHas('error');

    Queue::assertNothingPushed();
});

test('the ssh port can still be allowed', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['ssh_port' => 2222]);

    $this->actingAs(tabUser($team, TeamRole::Admin))
        ->post(route('servers.firewall.store', $server), ['action' => 'allow', 'port' => 2222])
        ->assertRedirect();

    Queue::assertPushed(ManageFirewallRule::class);
});

test('a member cannot change the firewall', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->post(route('servers.firewall.store', $server), ['action' => 'allow', 'port' => 8080])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a member sees the firewall tab but is not offered the form', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.firewall.index', $server))
        ->assertInertia(fn ($page) => $page->where('can.manage', false));
});

test('a firewall change is refused for a port outside the valid range', function (string $port) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(tabUser($team, TeamRole::Admin))
        ->post(route('servers.firewall.store', $server), ['action' => 'allow', 'port' => $port])
        ->assertSessionHasErrors('port');

    Queue::assertNothingPushed();
})->with(['0', '65536', '-1', 'http']);

test('a malformed source address is refused', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(tabUser($team, TeamRole::Admin))
        ->post(route('servers.firewall.store', $server), ['action' => 'allow', 'port' => 8080, 'from_ip' => 'not-an-ip'])
        ->assertSessionHasErrors('from_ip');

    Queue::assertNothingPushed();
});

test('every tab route on a server is behind authentication', function (string $name) {
    $server = Server::factory()->create();

    $this->get(route($name, $server))->assertRedirect(route('login'));
})->with(['servers.firewall.index']);

test('the activity log is the only firewall source, so nothing is invented', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    expect(Activity::query()->where('description', 'firewall_rule_changed')->count())->toBe(0);

    $this->actingAs(tabUser($team, TeamRole::Owner))
        ->get(route('servers.firewall.index', $server))
        ->assertInertia(fn ($page) => $page->has('changes', 0));
});
