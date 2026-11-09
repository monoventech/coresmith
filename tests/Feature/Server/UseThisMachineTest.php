<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\AuditServer;
use App\Jobs\ConnectThisMachine;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeSSHTransport;

function useThisMachineOwner(TeamRole $role = TeamRole::Owner, string $team = 'Acme Labs'): array
{
    $owned = Team::factory()->create(['name' => $team]);
    $user = User::factory()->create();
    $owned->members()->attach($user, ['role' => $role->value]);

    return [$owned, $user->fresh()];
}

function useThisMachineInstalled(bool $installed = true): void
{
    $folder = sys_get_temp_dir() . '/coresmith-use-this-machine-' . bin2hex(random_bytes(4));
    test()->scratchFolders = [...test()->scratchFolders, $folder];

    if ($installed) {
        File::ensureDirectoryExists($folder);
    }

    config(['coresmith.this_machine.state_path' => $folder]);
}

beforeEach(function () {
    $this->scratchFolders = [];
});

afterEach(function () {
    foreach ($this->scratchFolders as $folder) {
        File::deleteDirectory($folder);
    }
});

test('someone who runs this install sees This machine in Add server, named after the machine', function () {
    useThisMachineInstalled();
    [$team, $owner] = useThisMachineOwner();

    $this->actingAs($owner)->get(route('servers.create'))->assertInertia(fn(AssertableInertia $page) => $page
        ->where('thisMachine.installed', true)
        ->where('thisMachine.taken_by', null)
        ->where('thisMachine.name', gethostname())
        ->where('thisMachine.installer', 'curl -fsSL https://get.coresmith.io/install | sudo bash'));
});

test('an install whose installer predates this offers it with the command that brings the installer up to date', function () {
    useThisMachineInstalled(false);
    [, $owner] = useThisMachineOwner();

    $this->actingAs($owner)->get(route('servers.create'))->assertInertia(fn(AssertableInertia $page) => $page->where('thisMachine.installed', false));
});

test("an admin who doesn't run the install, and anyone on Coresmith Cloud, isn't offered this machine", function (string $who) {
    useThisMachineInstalled();
    [, $user] = useThisMachineOwner($who === 'admin' ? TeamRole::Admin : TeamRole::Owner);

    if ($who === 'cloud') {
        config(['coresmith.edition' => 'cloud']);
    }

    $this->actingAs($user)->get(route('servers.create'))->assertInertia(fn(AssertableInertia $page) => $page->where('thisMachine', null));
})->with(['admin', 'cloud']);

test('using this machine adds it as a server reached on loopback as root, then asks for access', function () {
    Queue::fake();
    useThisMachineInstalled();
    [$team, $owner] = useThisMachineOwner();

    $response = $this->actingAs($owner)->post(route('servers.this-machine.store'), ['team_id' => $team->id, 'name' => 'coresmith-box']);

    $server = Server::query()->sole();

    $response->assertRedirect(route('servers.show', $server))->assertSessionHas('success', 'Coresmith is getting access to this machine. It checks the machine next.');

    expect($server->runs_coresmith)->toBeTrue()
        ->and($server->team_id)->toBe($team->id)
        ->and($server->name)->toBe('coresmith-box')
        ->and($server->ip_address)->toBe('127.0.0.1')
        ->and($server->ssh_user)->toBe('root')
        ->and($server->status)->toBe(ServerStatus::Pending)
        ->and($server->ssh_public_key)->toStartWith('ssh-ed25519 ')
        ->and($server->ssh_private_key)->not->toBeEmpty();

    Queue::assertPushed(ConnectThisMachine::class, fn(ConnectThisMachine $job): bool => $job->server->is($server));
});

test('only one server can be this machine, and a second try says which team has it', function () {
    Queue::fake();
    useThisMachineInstalled();
    [$first] = useThisMachineOwner(team: 'Acme Labs');
    Server::factory()->for($first)->create(['runs_coresmith' => true]);
    [$second, $owner] = useThisMachineOwner(team: 'Globex');

    $this->actingAs($owner)->post(route('servers.this-machine.store'), ['team_id' => $second->id, 'name' => 'again'])
        ->assertSessionHas('error', 'This machine is already a server in Acme Labs.');

    expect(Server::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test("an install whose installer predates this can't add the machine yet, and says what to run", function () {
    Queue::fake();
    useThisMachineInstalled(false);
    [$team, $owner] = useThisMachineOwner();

    $this->actingAs($owner)->post(route('servers.this-machine.store'), ['team_id' => $team->id, 'name' => 'box'])
        ->assertSessionHas('error', "This install's installer is from before Coresmith could use its own machine. Run it again with curl -fsSL https://get.coresmith.io/install | sudo bash, then try again.");

    expect(Server::query()->count())->toBe(0);
});

test('only someone who runs this install, and can add servers to the team, can use this machine', function (string $who) {
    Queue::fake();
    useThisMachineInstalled();
    [$team, $user] = useThisMachineOwner($who === 'admin' ? TeamRole::Admin : TeamRole::Owner);

    if ($who === 'outsider') {
        $team = Team::factory()->create();
    }

    $this->actingAs($user)->post(route('servers.this-machine.store'), ['team_id' => $team->id, 'name' => 'box'])->assertForbidden();

    expect(Server::query()->count())->toBe(0);
})->with(['admin', 'outsider']);

test('on Coresmith Cloud, nothing adds the machine Coresmith runs on', function () {
    Queue::fake();
    useThisMachineInstalled();
    [$team, $owner] = useThisMachineOwner();
    config(['coresmith.edition' => 'cloud']);

    $response = $this->actingAs($owner)->post(route('servers.this-machine.store'), ['team_id' => $team->id, 'name' => 'box']);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->headers->get('Location'))->not->toContain('/servers/')
        ->and(Server::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

function useThisMachineHelper(?string $refusal = null): void
{
    useThisMachineInstalled();
    $folder = (string) config('coresmith.this_machine.state_path');

    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(function () use ($folder, $refusal): void {
        $request = @file_get_contents("{$folder}/request");

        if ($request === false) {
            return;
        }

        $id = explode(' ', trim($request), 2)[0];
        unlink("{$folder}/request");
        file_put_contents("{$folder}/result.json", json_encode($refusal === null
            ? ['id' => $id, 'ok' => true, 'port' => 2222, 'host_keys' => ['ED25519' => (string) HostKeyFingerprint::fromPublicKey(FakeSSHTransport::HOST_KEY)]]
            : ['id' => $id, 'ok' => false, 'error' => $refusal]));
    });
}

function useThisMachineServer(array $attributes = []): Server
{
    return Server::factory()->create(['runs_coresmith' => true, 'ip_address' => '127.0.0.1', 'ssh_user' => 'root', 'host_key_fingerprint' => null, 'status' => ServerStatus::Pending, ...$attributes]);
}

test("once the helper lets Coresmith in, the server gets the machine's port, a pinned host key and its first check", function () {
    Queue::fake([AuditServer::class]);
    useThisMachineHelper();
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    $server = useThisMachineServer();

    ConnectThisMachine::dispatchSync($server);

    $server->refresh();

    expect($server->ssh_port)->toBe(2222)
        ->and($server->host_key_fingerprint)->toBe((string) HostKeyFingerprint::fromPublicKey(FakeSSHTransport::HOST_KEY))
        ->and($server->last_connected_at)->not->toBeNull()
        ->and($server->last_connection_error)->toBeNull()
        ->and($transport->commands())->toContain('whoami');

    Queue::assertPushed(AuditServer::class);
});

test('a machine that answers with a different host key than the helper reported is never trusted', function () {
    Queue::fake([AuditServer::class]);
    useThisMachineHelper();
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('impostor'));
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    $server = useThisMachineServer();

    ConnectThisMachine::dispatchSync($server);

    expect($server->fresh()->last_connected_at)->toBeNull()
        ->and($server->fresh()->pending_host_key_fingerprint)->not->toBeNull();

    Queue::assertNotPushed(AuditServer::class);
});

test("a refusal from the helper is shown on the server, in the helper's words, so the owner knows what to fix", function () {
    Queue::fake([AuditServer::class]);
    useThisMachineHelper('sshd still turns root away from this machine, so the old settings are back. Check PermitRootLogin in /etc/ssh/sshd_config.');
    $server = useThisMachineServer();

    ConnectThisMachine::dispatchSync($server);

    expect($server->fresh()->last_connection_error)->toBe('sshd still turns root away from this machine, so the old settings are back. Check PermitRootLogin in /etc/ssh/sshd_config.')
        ->and($server->fresh()->last_connected_at)->toBeNull();

    Queue::assertNotPushed(AuditServer::class);
});

test('a server that is no longer waiting to be let in is left alone', function (array $attributes) {
    useThisMachineHelper('asked anyway');
    $server = useThisMachineServer($attributes);

    ConnectThisMachine::dispatchSync($server);

    expect($server->fresh()->last_connection_error)->toBeNull();
})->with([
    'not this machine' => [['runs_coresmith' => false]],
    'already provisioned' => [['status' => ServerStatus::Active]],
]);

test('trying again clears the last refusal and asks the helper again', function () {
    Queue::fake();
    [$team, $owner] = useThisMachineOwner();
    $server = useThisMachineServer(['team_id' => $team->id, 'last_connection_error' => 'The helper was busy.']);

    $this->actingAs($owner)->post(route('servers.this-machine.retry', $server))->assertSessionHas('success', 'Asking for access to this machine again.');

    expect($server->fresh()->last_connection_error)->toBeNull();
    Queue::assertPushed(ConnectThisMachine::class);
});

test("trying again is only for this machine while it's waiting to be let in", function () {
    [$team, $owner] = useThisMachineOwner();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Pending]);

    $this->actingAs($owner)->post(route('servers.this-machine.retry', $server))->assertNotFound();
});
