<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\ServerTabsController;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

function serverTabMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function serverTabServer(Team $team, array $attributes = []): Server
{
    return Server::factory()->for($team)->create(array_merge([
        'name' => 'web-1',
        'status' => ServerStatus::Active,
        'ip_address' => '203.0.113.10',
        'ssh_port' => 2222,
        'ssh_user' => 'deploy',
        'host_key_fingerprint' => 'SHA256:Xl/jKyuvKrFTpjRJe7mfKlKvBCANaqbn86pG1sf2G4k',
        'audit_logging_enabled' => true,
    ], $attributes));
}

test('the hardening tab lists the isolation layers, hardening, firewall and access details', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team, ['meta' => ['ssh_hardening' => 'verified']]);

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('servers/Hardening')
            ->where('server.id', $server->id)
            ->where('server.tabs.hardening', route('servers.hardening', $server))
            ->where('server.can.update', false)
            ->where('isolation', ServerTabsController::ISOLATION_LAYERS)
            ->where('hardening', ServerTabsController::HARDENING)
            ->where('admin_move_error', null)
            ->where('ssh', ['verified' => true, 'owner' => false, 'passwords_off' => false, 'error' => null, 'logins' => null, 'source' => null, 'lines' => [], 'reload' => 'ssh', 'checked' => null])
            ->where('firewall.open', [2222, 80, 443])
            ->where('firewall.ssh_port', 2222)
            ->where('firewall.changes', [])
            ->where('access', [
                'ssh' => 'deploy@203.0.113.10:2222',
                'fingerprint' => 'SHA256:Xl/jKyuvKrFTpjRJe7mfKlKvBCANaqbn86pG1sf2G4k',
                'public_key' => $server->ssh_public_key,
                'audit_logging' => true,
            ]));
});

test('a server whose admin api moved to the socket says so among its hardening', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team, ['meta' => ['caddy_admin' => 'socket', 'caddy_admin_error' => 'an old failure']]);

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page
            ->where('hardening', fn($items): bool => in_array('Admin API on a socket only Caddy and root can open', $items->all(), true)
                && !in_array(ServerTabsController::ADMIN_API_ON_LOCALHOST, $items->all(), true))
            ->where('admin_move_error', null));
});

test('a server whose admin api could not move yet says why', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team, ['meta' => ['caddy_admin_error' => 'curl: (7) Failed to connect to localhost port 2019', 'ssh_hardening' => 'verified']]);

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page
            ->where('hardening', ServerTabsController::HARDENING)
            ->where('admin_move_error', 'curl: (7) Failed to connect to localhost port 2019'));
});

test('a server still running the shared Redis says its databases are dedicated except for it', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team, ['meta' => ['installed_databases' => ['redis']]]);

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page
            ->where('isolation', fn($layers): bool => in_array('Dedicated database credentials, except the shared Redis on port 6379', $layers->all(), true)
                && !in_array('Dedicated database credentials', $layers->all(), true)
                && $layers->count() === 9));
});

test("on Coresmith's own machine whose firewall was already on, only the web ports are listed as opened", function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team, ['runs_coresmith' => true, 'ssh_port' => 22, 'meta' => ['firewall_ssh' => 'kept']]);

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page->where('firewall.open', [80, 443])->where('firewall.kept_ssh', true));
});

test("on Coresmith's own machine the hardening tab says SSH is left as its owner set it up, and whether it takes passwords", function (array $meta, bool $off) {
    $team = Team::factory()->create();
    $server = serverTabServer($team, ['runs_coresmith' => true, 'meta' => $meta]);

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page
            ->where('ssh', ['verified' => false, 'owner' => true, 'passwords_off' => $off, 'error' => null, 'logins' => null, 'source' => null, 'lines' => [], 'reload' => 'ssh', 'checked' => null])
            ->where('hardening', fn($items) => collect($items)->contains(ServerTabsController::SSH_LEFT_TO_OWNER) && !collect($items)->contains(ServerTabsController::SSH_KEY_ONLY)));
})->with([
    'passwords off' => [['ssh_hardening' => 'owner', 'ssh_passwords' => 'off'], true],
    'passwords on' => [['ssh_hardening' => 'owner'], false],
]);

test('the default ssh port is not listed twice among the open ports', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team, ['ssh_port' => 22]);

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page->where('firewall.open', [22, 80, 443]));
});

test('firewall changes are this servers own, newest first', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team);
    $other = serverTabServer($team, ['name' => 'web-2']);

    activity()->performedOn($server)->withProperties(['action' => 'allow', 'port' => 6379, 'from' => '203.0.113.5'])->log('firewall_rule_changed');
    activity()->performedOn($server)->withProperties(['action' => 'deny', 'port' => 8080])->log('firewall_rule_changed');
    activity()->performedOn($other)->withProperties(['action' => 'allow', 'port' => 9000])->log('firewall_rule_changed');
    activity()->performedOn($server)->withProperties(['action' => 'allow', 'port' => 1])->log('server_rebooted');

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page
            ->has('firewall.changes', 2)
            ->where('firewall.changes.0.action', 'deny')
            ->where('firewall.changes.0.port', 8080)
            ->where('firewall.changes.0.from', null)
            ->where('firewall.changes.1.action', 'allow')
            ->where('firewall.changes.1.port', 6379)
            ->where('firewall.changes.1.from', '203.0.113.5'));
});

test('at most twenty five firewall changes are listed', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team);

    Collection::times(27, fn(int $port) => activity()->performedOn($server)->withProperties(['action' => 'allow', 'port' => $port])->log('firewall_rule_changed'));

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page->has('firewall.changes', 25)->where('firewall.changes.0.port', 27));
});

test('the private key never reaches the hardening tab', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team, ['ssh_private_key' => 'PRIVATE-KEY-MATERIAL']);

    $response = $this->actingAs(serverTabMember($team, TeamRole::Owner))->get(route('servers.hardening', $server));

    expect($response->getContent())->not->toContain('PRIVATE-KEY-MATERIAL')
        ->and($response->getContent())->not->toContain('ssh_private_key');
});

test('the activity tab shows the feed for this server and its applications only', function () {
    $team = Team::factory()->create();
    $server = serverTabServer($team);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);
    $other = serverTabServer($team, ['name' => 'web-2']);

    activity()->performedOn($server)->log('server_rebooted');
    activity()->performedOn($application)->log('application_started');
    activity()->performedOn($other)->log('server_rebooted');

    $this->actingAs(serverTabMember($team))
        ->get(route('servers.activity', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('servers/Activity')
            ->where('server.name', 'web-1')
            ->has('activity', 2)
            ->where('activity', fn($rows) => collect($rows)->map(fn($row) => implode('', array_column($row['parts'], 'text')))->sort()->values()->all() === [
                'shop started again',
                'web-1 rebooted, and its apps started again',
            ]));
});

test('another team cannot open a servers tabs', function (string $route) {
    $server = serverTabServer(Team::factory()->create());

    $this->actingAs(serverTabMember(Team::factory()->create(), TeamRole::Owner))
        ->get(route($route, $server))
        ->assertForbidden();
})->with(['servers.hardening', 'servers.activity']);

test('the server tabs require authentication', function (string $route) {
    $server = serverTabServer(Team::factory()->create());

    $this->get(route($route, $server))->assertRedirect(route('login'));
})->with(['servers.hardening', 'servers.activity']);
