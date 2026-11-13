<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Jobs\ManageFirewallRule;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function tabUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('the deployments page lists the teams full history and nothing from another team', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    Deployment::factory()->for($application)->count(3)->create();
    Deployment::factory()->for(Application::factory()->for($team)->for($server))->create();
    Deployment::factory()->for(Application::factory()->for(Team::factory()))->create();

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('deployments/Index')
            ->where('filter', 'all')
            ->where('filters.0.key', 'all')
            ->where('filters.0.count', 4)
            ->has('deployments', 4));
});

test('filtering the deployments page stays inside the team', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed]);
    Deployment::factory()->for($application)->succeeded()->create();
    Deployment::factory()->for(Application::factory()->for(Team::factory()))->create(['status' => DeploymentStatus::Failed]);

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('deployments.index', ['status' => 'failed']))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('filter', 'failed')
            ->where('filters.3.key', 'failed')
            ->where('filters.3.count', 1)
            ->has('deployments', 1)
            ->where('deployments.0.state.key', 'failed'));
});

test('the releases page lists only that applications successful releases', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    foreach (['20260901120000', '20260902120000', '20260903120000'] as $release) {
        Deployment::factory()->for($application)->succeeded()->create(['release' => $release]);
    }

    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'release' => '20260904120000']);
    Deployment::factory()->for(Application::factory()->for($team)->for($server))->succeeded()->create(['release' => '20260905120000']);

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.applications.releases.index', [$server, $application]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('releases/Index')
            ->where('application.id', $application->id)
            ->has('releases', 3)
            ->where('can.deploy', true));
});

test('the application page carries the toggles and what the application runs', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create([
        'power_state' => PowerState::Stopped,
        'toggles' => ['maintenance_mode' => true],
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
    ]);

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$server, $application]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('applications/Show')
            ->where('application.power_state', 'stopped')
            ->where('application.stopped', true)
            ->where('application.maintenance', true)
            ->has('application.processes', 1)
            ->has('toggles', 4)
            ->where('toggles.0.key', 'queue_worker')
            ->where('toggles.0.on', true)
            ->where('toggles.2.key', 'maintenance_mode')
            ->where('toggles.2.on', true)
            ->where('toggles.2.disabled', true)
            ->where('toggles.2.href', route('servers.applications.toggles.update', [$server, $application, 'maintenance_mode']))
            ->where('can.update', false));
});

test('an admin can flip a smart toggle from the application page', function () {
    Queue::fake();
    Notification::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(tabUser($team, TeamRole::Admin))
        ->put(route('servers.applications.toggles.update', [$server, $application, 'maintenance_mode']), ['on' => true])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($application->fresh()->toggles['maintenance_mode'] ?? false)->toBeTrue();
    Queue::assertPushed(SyncApplicationRoute::class);
});

test('a member cannot flip a smart toggle', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->put(route('servers.applications.toggles.update', [$server, $application, 'maintenance_mode']), ['on' => true])
        ->assertForbidden();

    expect($application->fresh()->toggles['maintenance_mode'] ?? false)->toBeFalse();
    Queue::assertNothingPushed();
});

test('a releases page or smart toggle for another server is a not found', function (string $method, string $name, array $extra) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $other = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(tabUser($team, TeamRole::Owner))
        ->{$method}(route($name, [$other, $application, ...$extra]))
        ->assertNotFound();

    Queue::assertNothingPushed();
})->with([
    ['get', 'servers.applications.releases.index', []],
    ['put', 'servers.applications.toggles.update', ['maintenance_mode']],
]);

test('another teams application pages are refused', function (string $method, string $name, array $extra) {
    Queue::fake();
    $application = Application::factory()->for(Team::factory())->create();

    $this->actingAs(tabUser(Team::factory()->create(), TeamRole::Owner))
        ->{$method}(route($name, [$application->server_id, $application, ...$extra]))
        ->assertForbidden();

    Queue::assertNothingPushed();
})->with([
    ['get', 'servers.applications.releases.index', []],
    ['put', 'servers.applications.toggles.update', ['maintenance_mode']],
]);

test('another teams server tabs are refused', function (string $name) {
    $server = Server::factory()->for(Team::factory()->create())->create();

    $this->actingAs(tabUser(Team::factory()->create(), TeamRole::Owner))
        ->get(route($name, $server))
        ->assertForbidden();
})->with(['servers.show', 'servers.databases.index', 'servers.hardening', 'servers.activity']);

test('the activity tab lists only what happened on that server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $other = Server::factory()->for($team)->create(['name' => 'web-2']);

    activity()->performedOn($server)->log('server_rebooted');
    activity()->performedOn($other)->log('server_rebooted');
    Deployment::factory()->for(Application::factory()->for($team)->for($other))->succeeded()->create();

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.activity', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('servers/Activity')
            ->has('activity', 1)
            ->where('activity.0.parts.0.text', 'web-1'));
});

test('the hardening tab lists only the firewall changes coresmith applied to that server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $other = Server::factory()->for($team)->create();

    activity()->performedOn($server)->withProperties(['action' => 'allow', 'port' => 8080])->log('firewall_rule_changed');
    activity()->performedOn($other)->withProperties(['action' => 'deny', 'port' => 9000])->log('firewall_rule_changed');
    activity()->performedOn($server)->log('something_else');

    $this->actingAs(tabUser($team, TeamRole::Owner))
        ->get(route('servers.hardening', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('servers/Hardening')
            ->has('firewall.changes', 1)
            ->where('firewall.changes.0.port', 8080));
});

test('the hardening tab claims key-only SSH only once sshd has reported it, and says what sshd reported otherwise', function (array $attributes, string $state, string $detail, array $ssh) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create($attributes);

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.hardening', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('protections.rows.0.state', $state)
            ->where('protections.rows.0.detail', $detail)
            ->where('ssh', $ssh));
})->with([
    'verified' => [['meta' => ['ssh_hardening' => 'verified']], 'on', 'Password sign-in is off.', ['verified' => true, 'owner' => false, 'passwords_off' => false, 'logins' => null, 'source' => null, 'lines' => [], 'reload' => 'ssh', 'checked' => null]],
    'still accepting passwords' => [['meta' => ['ssh_hardening_error' => 'sshd reports passwordauthentication yes']], 'off', "Coresmith couldn't turn off password sign-in yet: sshd reports passwordauthentication yes", ['verified' => false, 'owner' => false, 'passwords_off' => false, 'logins' => null, 'source' => null, 'lines' => [], 'reload' => 'ssh', 'checked' => null]],
    'not checked yet' => [['meta' => []], 'pending', 'Coresmith turns off password sign-in within the hour.', ['verified' => false, 'owner' => false, 'passwords_off' => false, 'logins' => null, 'source' => null, 'lines' => [], 'reload' => 'ssh', 'checked' => null]],
    "left as its owner set it up on Coresmith's machine" => [['runs_coresmith' => true, 'meta' => ['ssh_hardening' => 'owner', 'ssh_passwords' => 'off']], 'yours', "Password sign-in is off in this machine's own SSH settings.", ['verified' => false, 'owner' => true, 'passwords_off' => true, 'logins' => null, 'source' => null, 'lines' => [], 'reload' => 'ssh', 'checked' => null]],
]);

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

test('a member sees the hardening tab but is not offered the firewall form', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['ssh_port' => 2222]);

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.hardening', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('firewall.ssh_port', 2222)
            ->where('server.can.manageFirewall', false));
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
})->with(['servers.show', 'servers.databases.index', 'servers.hardening', 'servers.activity']);

test('the activity log is the only firewall source, so nothing is invented', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    expect(Activity::query()->where('description', 'firewall_rule_changed')->count())->toBe(0);

    $this->actingAs(tabUser($team, TeamRole::Owner))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page->has('firewall.changes', 0));
});

test('the hardening tab says unknown addresses are closed once they are, and only then', function (array $attributes, string $state, string $detail) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create($attributes);

    $this->actingAs(tabUser($team, TeamRole::Member))
        ->get(route('servers.hardening', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('protections.rows.5.key', 'unknown_hosts')
            ->where('protections.rows.5.state', $state)
            ->where('protections.rows.5.detail', $detail));
})->with([
    'closed' => [['meta' => ['caddy_unknown_hosts' => 'closed']], 'on', "Requests for addresses you didn't add are closed without an answer."],
    'not yet' => [['meta' => []], 'pending', "Coresmith closes requests for addresses you didn't add within the hour."],
    "Coresmith's own machine, whose own catch-all serves no app" => [['runs_coresmith' => true, 'meta' => ['caddy_unknown_hosts' => 'closed']], 'on', "Requests for addresses you didn't add never reach your apps."],
]);
