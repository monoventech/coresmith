<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\AuditServer;
use App\Jobs\FixAuditFinding;
use App\Jobs\ProvisionServer;
use App\Jobs\TestSSHConnection;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Panel\ServerPresenter;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Queue;

function checkedServer(Team $team, array $findings = [], int $minutesAgo = 5): Server
{
    return Server::factory()->for($team)->create([
        'status' => ServerStatus::Pending,
        'audit' => ['state' => 'done', 'ran_at' => now()->subMinutes($minutesAgo)->toIso8601String(), 'findings' => $findings],
    ]);
}

function teamMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('a guest is sent to login', function () {
    $this->get(route('servers.index'))->assertRedirect(route('login'));
});

test('the list only shows servers from the users own teams', function () {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();
    $user = teamMember($mine, TeamRole::Member);

    $visible = Server::factory()->for($mine)->create(['name' => 'mine']);
    Server::factory()->for($theirs)->create(['name' => 'theirs']);

    $this->actingAs($user)
        ->get(route('servers.index'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->component('Dashboard')
            ->has('servers', 1)
            ->where('servers.0.id', $visible->id));
});

test('another teams server cannot be viewed', function () {
    $user = teamMember(Team::factory()->create(), TeamRole::Owner);
    $server = Server::factory()->for(Team::factory()->create())->create();

    $this->actingAs($user)->get(route('servers.show', $server))->assertForbidden();
});

test('a member of the owning team can view the server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page->component('servers/Show')->where('server.id', $server->id));
});

test('the private key is never sent to the browser', function (string $route) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['ssh_private_key' => 'PRIVATE-KEY-MATERIAL']);

    $response = $this->actingAs(teamMember($team, TeamRole::Admin))->get(route($route, $server))->assertOk();

    expect($response->getContent())->not->toContain('PRIVATE-KEY-MATERIAL')
        ->and($response->getContent())->not->toContain('ssh_private_key');
})->with(['servers.show', 'servers.databases.index', 'servers.hardening', 'servers.activity']);

test('a member cannot create a server', function () {
    $team = Team::factory()->create();

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->post(route('servers.store'), [
            'team_id' => $team->id,
            'name' => 'web-1',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ])
        ->assertForbidden();

    expect(Server::count())->toBe(0);
});

test('an admin can create a server and it gets a generated key pair', function () {
    $team = Team::factory()->create();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.store'), [
            'team_id' => $team->id,
            'name' => 'web-1',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ])
        ->assertRedirect();

    $server = Server::firstWhere('name', 'web-1');

    expect($server)->not->toBeNull()
        ->and($server->ssh_public_key)->toStartWith('ssh-ed25519 ')
        ->and($server->ssh_private_key)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($server->fresh()->status)->toBe(ServerStatus::Pending);
});

test('a server cannot be created inside a team the user does not belong to', function () {
    $outsider = teamMember(Team::factory()->create(), TeamRole::Owner);
    $victim = Team::factory()->create();

    $this->actingAs($outsider)
        ->post(route('servers.store'), [
            'team_id' => $victim->id,
            'name' => 'intruder',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ])
        ->assertForbidden();

    expect(Server::count())->toBe(0);
});

test('server input is validated', function (array $payload, string $field) {
    $team = Team::factory()->create();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.store'), array_merge([
            'team_id' => $team->id,
            'name' => 'web-1',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ], $payload))
        ->assertSessionHasErrors($field);
})->with([
    [['ip_address' => 'not-an-ip'], 'ip_address'],
    [['ip_address' => '203.0.113.10; rm -rf /'], 'ip_address'],
    [['ssh_port' => 0], 'ssh_port'],
    [['ssh_port' => 70000], 'ssh_port'],
    [['ssh_user' => 'root; rm -rf /'], 'ssh_user'],
    [['ssh_user' => 'Root'], 'ssh_user'],
    [['name' => ''], 'name'],
]);

test('an ssh user ending in a newline is refused by its format, even when the request is not trimmed', function () {
    $team = Team::factory()->create();

    $this->withoutMiddleware(TrimStrings::class)
        ->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.store'), [
            'team_id' => $team->id,
            'name' => 'web-1',
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => "deploy\n",
        ])
        ->assertSessionHasErrors('ssh_user');

    expect(Server::count())->toBe(0);
});

test('a member cannot delete a server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->delete(route('servers.destroy', $server))
        ->assertForbidden();

    expect(Server::find($server->id))->not->toBeNull();
});

test('an admin can delete a server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->delete(route('servers.destroy', $server))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', "Detached. {$server->name} was removed from the panel. The VM is untouched.");

    expect(Server::find($server->id))->toBeNull()
        ->and(Server::withTrashed()->find($server->id))->not->toBeNull();
});

test('a server still being created cannot be detached', function () {
    $team = Team::factory()->create();
    $account = CloudProvider::factory()->for($team)->create();
    $server = Server::factory()->fromProvider($account)->creating()->create(['name' => 'outpost-02']);
    CloudTask::start($server, CloudTask::CREATE, 'boot');

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->from(route('servers.show', $server))
        ->delete(route('servers.destroy', $server))
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('error', 'Coresmith is still working on outpost-02 at Hetzner Cloud. Wait for it to finish or fail, then detach it.');

    expect(Server::find($server->id))->not->toBeNull();
});

test('detaching a provider server says it keeps running and billing', function () {
    $team = Team::factory()->create();
    $account = CloudProvider::factory()->for($team)->create();
    $server = Server::factory()->fromProvider($account)->create(['name' => 'outpost-02', 'status' => ServerStatus::Active]);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->delete(route('servers.destroy', $server))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Detached. outpost-02 keeps running at Hetzner Cloud, and Hetzner Cloud keeps billing for it.');

    expect(Server::find($server->id))->toBeNull();
});

test('removing a creation that never reached the provider says nothing was created', function () {
    $team = Team::factory()->create();
    $account = CloudProvider::factory()->for($team)->create();
    $server = Server::factory()->fromProvider($account)->creating()->create(['name' => 'outpost-02', 'provider_server_id' => null]);
    CloudTask::start($server, CloudTask::CREATE, 'create');
    CloudTask::fail($server, 'Hetzner Cloud refused the request.');

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->delete(route('servers.destroy', $server))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Removed. Nothing was created at Hetzner Cloud.');

    expect(Server::find($server->id))->toBeNull();
});

test('the server page carries what the provider knows, and nothing for a server added by hand', function () {
    $team = Team::factory()->create();
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner production']);
    $cloud = Server::factory()->fromProvider($account)->create(['status' => ServerStatus::Active]);
    $manual = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    $admin = teamMember($team, TeamRole::Admin);

    $this->actingAs($admin)->get(route('servers.show', $cloud))
        ->assertInertia(fn($page) => $page
            ->where('server.cloud.provider', 'Hetzner Cloud')
            ->where('server.cloud.account', 'Hetzner production')
            ->where('server.cloud.instance', '4711')
            ->where('server.cloud.can.detach', true));

    $this->actingAs($admin)->get(route('servers.show', $manual))
        ->assertInertia(fn($page) => $page->where('server.cloud', null));
});

test('a member cannot update a server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->put(route('servers.update', $server), ['name' => 'renamed'])
        ->assertForbidden();

    expect($server->fresh()->name)->toBe('web-1');
});

test('an admin can rename a server without resending its other settings', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'ssh_port' => 2222]);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->put(route('servers.update', $server), ['name' => 'web-renamed'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($server->fresh()->name)->toBe('web-renamed')
        ->and($server->fresh()->ssh_port)->toBe(2222);
});

test('provisioning another teams server is refused and queues nothing', function () {
    Queue::fake();
    $outsider = teamMember(Team::factory()->create(), TeamRole::Owner);
    $server = Server::factory()->for(Team::factory()->create())->create();

    $this->actingAs($outsider)->post(route('servers.provision', $server))->assertForbidden();

    Queue::assertNothingPushed();
});

test('an admin can provision a server whose recent check found nothing blocking', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = checkedServer($team, [['key' => 'app_users', 'severity' => 'warning', 'title' => '1 Coresmith app user exists', 'detail' => '', 'fix' => null]]);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.provision', $server))
        ->assertRedirect()
        ->assertSessionHas('success', 'Provisioning started. Every phase is logged, close this page and it keeps going.');

    expect($server->fresh()->status)->toBe(ServerStatus::Provisioning);

    Queue::assertPushed(ProvisionServer::class);
});

test('provisioning waits for blockers to be resolved', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = checkedServer($team, [['key' => 'port_80', 'severity' => 'blocker', 'title' => 'Port 80 is in use by nginx', 'detail' => '', 'fix' => 'stop-nginx']]);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.provision', $server))
        ->assertSessionHas('error', 'Resolve the blockers first, then check again.');

    expect($server->fresh()->status)->toBe(ServerStatus::Pending);

    Queue::assertNotPushed(ProvisionServer::class);
});

test('a server never checked, or checked over an hour ago, is checked again instead of provisioned', function (?int $minutesAgo) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = $minutesAgo === null ? Server::factory()->for($team)->create(['status' => ServerStatus::Pending]) : checkedServer($team, [], $minutesAgo);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.provision', $server))
        ->assertSessionHas('error', 'Coresmith checks the server before provisioning, so that check is running now. Provision once it finishes.');

    Queue::assertPushed(AuditServer::class);
    Queue::assertNotPushed(ProvisionServer::class);
})->with(['never' => [null], 'stale' => [61]]);

test('a check that never reported back does not wedge provisioning', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'status' => ServerStatus::Pending,
        'audit' => ['state' => 'running', 'started_at' => now()->subMinutes(6)->toIso8601String()],
    ]);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.provision', $server))
        ->assertSessionHas('error', 'Coresmith checks the server before provisioning, so that check is running now. Provision once it finishes.');

    Queue::assertPushed(AuditServer::class);
});

test('a stale running check is presented as one that did not finish', function () {
    $server = Server::factory()->create([
        'status' => ServerStatus::Pending,
        'audit' => ['state' => 'running', 'started_at' => now()->subMinutes(6)->toIso8601String()],
    ]);

    $audit = app(ServerPresenter::class)->header($server, teamMember($server->team, TeamRole::Admin))['provisioning']['audit'];

    expect($audit['state'])->toBe('failed')
        ->and($audit['error'])->toBe('The check stopped before it finished. Check again.');
});

test('provisioning a server that is already active is not found', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    $this->actingAs(teamMember($team, TeamRole::Admin))->post(route('servers.provision', $server))->assertNotFound();

    expect($server->fresh()->status)->toBe(ServerStatus::Active);

    Queue::assertNotPushed(ProvisionServer::class);
});

test('an admin can check a pending server and a member cannot', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Pending]);

    $this->actingAs(teamMember($team, TeamRole::Member))->post(route('servers.audit', $server))->assertForbidden();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.audit', $server))
        ->assertSessionHas('success', "Checking {$server->name}. Nothing on the server changes while Coresmith looks.");

    Queue::assertPushed(AuditServer::class);
});

test('a server that is already provisioned is not audited', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    $this->actingAs(teamMember($team, TeamRole::Admin))->post(route('servers.audit', $server))->assertNotFound();

    Queue::assertNothingPushed();
});

test('an admin can apply a fix the latest check offered', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = checkedServer($team, [['key' => 'port_80', 'severity' => 'blocker', 'title' => 'Port 80 is in use by nginx', 'detail' => '', 'fix' => 'stop-nginx']]);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.audit.fix', [$server, 'stop-nginx']))
        ->assertSessionHas('success', "Stopping nginx on {$server->name}, then checking again.");

    expect($server->fresh()->audit['state'])->toBe('running');

    Queue::assertPushed(FixAuditFinding::class);
});

test('a fix the check did not offer, or on a server past provisioning, is not found', function (string $fix, ServerStatus $status) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = checkedServer($team, [['key' => 'port_80', 'severity' => 'blocker', 'title' => 'Port 80 is in use by nginx', 'detail' => '', 'fix' => 'stop-nginx']]);
    $server->forceFill(['status' => $status])->save();

    $this->actingAs(teamMember($team, TeamRole::Admin))->post(route('servers.audit.fix', [$server, $fix]))->assertNotFound();

    Queue::assertNothingPushed();
})->with([
    'not offered' => ['stop-apache2', ServerStatus::Pending],
    'not a fix' => ['stop-sshd', ServerStatus::Pending],
    'active server' => ['stop-nginx', ServerStatus::Active],
]);

test('a member cannot apply a fix', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = checkedServer($team, [['key' => 'port_80', 'severity' => 'blocker', 'title' => 'Port 80 is in use by nginx', 'detail' => '', 'fix' => 'stop-nginx']]);

    $this->actingAs(teamMember($team, TeamRole::Member))->post(route('servers.audit.fix', [$server, 'stop-nginx']))->assertForbidden();

    Queue::assertNothingPushed();
});

test('applying a fix on another teams server is refused and queues nothing', function () {
    Queue::fake();
    $outsider = teamMember(Team::factory()->create(), TeamRole::Owner);
    $server = checkedServer(Team::factory()->create(), [['key' => 'port_80', 'severity' => 'blocker', 'title' => 'Port 80 is in use by nginx', 'detail' => '', 'fix' => 'stop-nginx']]);

    $this->actingAs($outsider)->post(route('servers.audit.fix', [$server, 'stop-nginx']))->assertForbidden();

    Queue::assertNothingPushed();
});

test('a member cannot trigger a connection test', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(teamMember($team, TeamRole::Member))
        ->post(route('servers.test-connection', $server))
        ->assertForbidden();

    Queue::assertNotPushed(TestSSHConnection::class);
});

test('a server being created has no connection to test', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $account = CloudProvider::factory()->for($team)->create();
    $server = Server::factory()->fromProvider($account)->creating()->create();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.test-connection', $server))
        ->assertNotFound();

    Queue::assertNotPushed(TestSSHConnection::class);
});

test('a failed server resumes from the phase that failed', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Failed, 'provisioning_phase' => 3, 'provisioning_error' => 'Directories failed.']);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.provisioning.retry', $server))
        ->assertSessionHas('success', 'Retrying from phase 4. The three phases before it finished and are kept.');

    expect($server->fresh()->status)->toBe(ServerStatus::Provisioning)
        ->and($server->fresh()->provisioning_error)->toBeNull();

    Queue::assertPushed(ProvisionServer::class, fn(ProvisionServer $job): bool => (fn() => $this->fromScratch)->call($job) === false);
});

test('a failed server can start over from phase 1', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Failed, 'provisioning_phase' => 3, 'provisioning_log' => 'phase 1 ... ok', 'provisioning_error' => 'Directories failed.']);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.provisioning.restart', $server))
        ->assertSessionHas('success', 'Starting over from phase 1. Each phase is written to be safe to run again.');

    expect($server->fresh()->provisioning_log)->toBeNull()
        ->and($server->fresh()->provisioning_error)->toBeNull();

    Queue::assertPushed(ProvisionServer::class, fn(ProvisionServer $job): bool => (fn() => $this->fromScratch)->call($job) === true);
});

test('only a failed server can be retried or started over, and a failed one is not provisioned directly', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $admin = teamMember($team, TeamRole::Admin);
    $active = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    $failed = Server::factory()->for($team)->create(['status' => ServerStatus::Failed, 'provisioning_phase' => 2]);

    $this->actingAs($admin)->post(route('servers.provisioning.retry', $active))->assertNotFound();
    $this->actingAs($admin)->post(route('servers.provisioning.restart', $active))->assertNotFound();
    $this->actingAs($admin)->post(route('servers.provision', $failed))->assertSessionHas('error', 'Provisioning already ran on this server. Retry it from the phase that failed, or start over.');

    Queue::assertNothingPushed();
});

test('every server route requires authentication', function (string $method, string $route) {
    $server = Server::factory()->create();
    $url = in_array($route, ['servers.index', 'servers.create'], true)
        ? route($route)
        : route($route, $server);

    $this->{$method}($url)->assertRedirect(route('login'));
})->with([
    ['get', 'servers.index'],
    ['get', 'servers.create'],
    ['get', 'servers.show'],
    ['put', 'servers.update'],
    ['delete', 'servers.destroy'],
    ['post', 'servers.provision'],
    ['post', 'servers.audit'],
    ['post', 'servers.test-connection'],
    ['post', 'servers.reboot'],
    ['post', 'servers.prune-releases'],
]);

test('a disconnected server can be checked and offered fixes, because its panel offers both', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Disconnected]);

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.audit', $server))
        ->assertSessionHas('success');

    Queue::assertPushed(AuditServer::class);

    $checked = checkedServer($team, [['key' => 'port_80', 'severity' => 'blocker', 'title' => 'Port 80 is in use by nginx', 'detail' => '', 'fix' => 'stop-nginx']]);
    $checked->forceFill(['status' => ServerStatus::Disconnected])->save();

    $this->actingAs(teamMember($team, TeamRole::Admin))
        ->post(route('servers.audit.fix', ['server' => $checked, 'fix' => 'stop-nginx']))
        ->assertSessionHas('success');

    Queue::assertPushed(FixAuditFinding::class);
});

test('each new server key is labelled with its server, safely, whatever the name', function (string $name, string $label) {
    $team = Team::factory()->create();

    $this->actingAs(teamMember($team, TeamRole::Owner))
        ->post(route('servers.store'), [
            'team_id' => $team->id,
            'name' => $name,
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
        ])
        ->assertRedirect();

    $key = Server::query()->latest('id')->firstOrFail()->ssh_public_key;

    expect($key)->toEndWith(' ' . $label)
        ->and($key)->not->toContain("\n");
})->with([
    'a plain name' => ['Web 1', 'coresmith@web-1'],
    'a name with a line break' => ["edge\nssh-ed25519 AAAA attacker", 'coresmith@edge-ssh-ed25519-aaaa-attacker'],
]);
