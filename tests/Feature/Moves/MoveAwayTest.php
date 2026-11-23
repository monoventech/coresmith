<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerTunnel;
use App\Models\Team;
use App\Models\User;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\TeamMove;
use App\Services\Network\LocalAddresses;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CloudBilling;

function moveAwayTeam(): Team
{
    $team = Team::factory()->owned()->create(['name' => 'Acme', 'slug' => 'acme']);
    $server = Server::factory()->active()->create(['team_id' => $team->id, 'name' => 'web-01', 'ip_address' => '203.0.113.20', 'host_key_fingerprint' => 'SHA256:' . str_repeat('W', 43)]);
    Application::factory()->create(['team_id' => $team->id, 'server_id' => $server->id, 'name' => 'site']);

    return $team->fresh();
}

function moveAwayOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function moveAwayCode(string $edition = 'cloud', string $panel = 'https://app.coresmith.io', array $reach = ['88.99.12.121']): string
{
    $claims = [
        'v' => 1,
        'id' => '01JMOVEAWAY000000000000000',
        'key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'until' => now()->addDay()->getTimestamp(),
        'panel' => $panel,
        'edition' => $edition,
    ];

    if ($edition === 'cloud' && $reach !== []) {
        $claims['reach'] = $reach;
    }

    return MoveCodes::PREFIX . rtrim(strtr(base64_encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
}

function moveAwayServer(Team $team): Server
{
    return $team->servers()->firstOrFail();
}

function moveAwayMachine(Team $team, ?string $ipv4 = '203.0.113.50', bool $direct = true): Server
{
    $server = moveAwayServer($team);
    $server->forceFill(['ip_address' => '127.0.0.1', 'runs_coresmith' => true, 'public_addresses' => ['ipv4' => $ipv4]])->save();
    $local = ['127.0.0.1', '::1', '192.168.0.248', ...($direct && $ipv4 !== null ? [$ipv4] : [])];
    app()->instance(LocalAddresses::class, new class($local) implements LocalAddresses
    {
        public function __construct(private readonly array $local) {}

        public function all(): array
        {
            return $this->local;
        }
    });

    return $server;
}

/**
 * @return list<string>
 */
function moveAwayHelperFolders(?string $folder = null): array
{
    static $folders = [];

    if ($folder !== null) {
        $folders[] = $folder;
    }

    return $folders;
}

/**
 * @param  Closure(string): array<string, mixed>  $answer
 */
function moveAwayHelper(Closure $answer): void
{
    $folder = sys_get_temp_dir() . '/coresmith-move-helper-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($folder);
    moveAwayHelperFolders($folder);
    config(['coresmith.this_machine.state_path' => $folder]);
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(function () use ($folder, $answer): void {
        $request = @file_get_contents("{$folder}/request");

        if ($request === false) {
            return;
        }

        [$id, $what] = explode(' ', trim($request), 2);
        unlink("{$folder}/request");
        file_put_contents("{$folder}/result.json", json_encode(['id' => $id, ...$answer($what)]));
    });
}

afterEach(function () {
    foreach (moveAwayHelperFolders() as $folder) {
        File::deleteDirectory($folder);
    }
});

test('a code from Coresmith Cloud pauses the team and its move file downloads', function () {
    $team = moveAwayTeam();

    $this->actingAs(moveAwayOwner($team))->from(route('settings.general'))
        ->post(route('settings.moving.store'), ['code' => moveAwayCode()])
        ->assertRedirect(route('settings.general'))
        ->assertSessionHas('success', 'Saved. Coresmith has paused this team. Upload the move file in Coresmith Cloud to finish.');

    $fresh = $team->fresh();

    expect($fresh->moving_at)->not->toBeNull()
        ->and($fresh->moving_to)->toBe('https://app.coresmith.io')
        ->and($fresh->moving_edition)->toBe('cloud')
        ->and(moveAwayServer($fresh)->status)->toBe(ServerStatus::Moving);

    $this->get(route('settings.moving.file'))->assertOk()->assertDownload('acme.coresmith-move');
});

test('a server Coresmith Cloud could not reach stops the move before anything pauses', function (Closure $arrange, string $refusal) {
    $team = moveAwayTeam();
    $arrange(moveAwayServer($team));

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode()])
        ->assertSessionHasErrors(['code' => $refusal]);

    expect($team->fresh()->moving_at)->toBeNull()
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Active);
})->with([
    'a home network' => [fn(Server $server) => $server->forceFill(['ip_address' => '192.168.0.10'])->save(), "web-01 is at 192.168.0.10, which Coresmith Cloud can't reach. Give it a public address or remove it from the team, then try again."],
    'IPv4 through Cloudflare' => [fn(Server $server) => ServerTunnel::query()->create(['server_id' => $server->id, 'state' => 'open']), "web-01 uses IPv4 through Cloudflare, which only works on self-hosted Coresmith. Turn it off on the server's page, then try again."],
]);

test('the machine Coresmith runs on waits for its owner to agree, and says so beside the agreement', function () {
    $team = moveAwayTeam();
    moveAwayMachine($team);

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode()])
        ->assertSessionHasErrors(['hand_over' => 'web-01 is the machine Coresmith runs on. Agree to hand it over, or remove it from the team, then try again.'])
        ->assertSessionDoesntHaveErrors('code');

    expect($team->fresh()->moving_at)->toBeNull()
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Active);
});

test('the machine Coresmith runs on stops a move to another self-hosted Coresmith before anything pauses', function () {
    $team = moveAwayTeam();
    moveAwayServer($team)->forceFill(['ip_address' => '127.0.0.1', 'runs_coresmith' => true])->save();

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode('self-hosted', 'https://panel.example.com')])
        ->assertSessionHasErrors(['code' => "web-01 is the machine Coresmith runs on, which can't move to another Coresmith. Remove it from the team, then try again."]);

    expect($team->fresh()->moving_at)->toBeNull()
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Active);
});

test('with its owner agreeing, the machine Coresmith runs on lets Coresmith Cloud in before the team pauses', function () {
    $team = moveAwayTeam();
    moveAwayMachine($team);
    $asked = [];
    moveAwayHelper(function (string $what) use (&$asked): array {
        $asked[] = $what;

        return ['ok' => true];
    });

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode(), 'hand_over' => '1'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($asked)->toBe(['handover 88.99.12.121'])
        ->and($team->fresh()->moving_at)->not->toBeNull()
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Moving);
});

test('a helper that turns the handover down leaves the team as it was', function () {
    $team = moveAwayTeam();
    moveAwayMachine($team);
    moveAwayHelper(fn(string $what): array => ['ok' => false, 'error' => 'sshd still turns root away from 88.99.12.121, so the old settings are back. Check PermitRootLogin in /etc/ssh/sshd_config and the files it includes.']);

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode(), 'hand_over' => '1'])
        ->assertSessionHasErrors(['hand_over' => 'sshd still turns root away from 88.99.12.121, so the old settings are back. Check PermitRootLogin in /etc/ssh/sshd_config and the files it includes.']);

    expect($team->fresh()->moving_at)->toBeNull()
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Active);
});

test('the machine Coresmith runs on stays when it can not be handed over', function (?string $ipv4, array $reach, string $field, string $refusal, bool $direct = true) {
    $team = moveAwayTeam();
    moveAwayMachine($team, $ipv4, $direct);
    $asked = [];
    moveAwayHelper(function (string $what) use (&$asked): array {
        $asked[] = $what;

        return ['ok' => true];
    });

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode(reach: $reach), 'hand_over' => '1'])
        ->assertSessionHasErrors([$field => $refusal]);

    expect($asked)->toBe([])
        ->and($team->fresh()->moving_at)->toBeNull();
})->with([
    'a code from before Coresmith Cloud named its addresses' => ['203.0.113.50', [], 'code', 'That code is from before Coresmith Cloud could take over the machine Coresmith runs on. Create a new one in Coresmith Cloud, then try again.'],
    'no public IPv4 address' => [null, ['88.99.12.121'], 'hand_over', "web-01 has no public IPv4 address Coresmith Cloud can reach, so it can't move. Remove it from the team, then try again."],
    'a private address' => ['192.168.0.248', ['88.99.12.121'], 'hand_over', "web-01 has no public IPv4 address Coresmith Cloud can reach, so it can't move. Remove it from the team, then try again."],
    'a public address that belongs to a home router' => ['198.51.100.7', ['88.99.12.121'], 'hand_over', "web-01 reaches the internet through another device, such as a home router, so Coresmith Cloud can't reach it. Remove it from the team, then try again.", false],
]);

test('cancelling a move that handed the machine over puts root sign-in back', function () {
    $team = moveAwayTeam();
    moveAwayMachine($team);
    $asked = [];
    moveAwayHelper(function (string $what) use (&$asked): array {
        $asked[] = $what;

        return ['ok' => true];
    });
    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode(), 'hand_over' => '1']);

    $this->delete(route('settings.moving.destroy'))->assertSessionHasNoErrors();

    expect($asked)->toBe(['handover 88.99.12.121', 'keep'])
        ->and($team->fresh()->moving_at)->toBeNull()
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Active);
});

test('General names the machine Coresmith runs on before the move and while it moves', function () {
    $team = moveAwayTeam();
    moveAwayMachine($team);
    moveAwayHelper(fn(): array => ['ok' => true]);

    $this->actingAs(moveAwayOwner($team))->get(route('settings.general'))
        ->assertInertia(fn(Assert $page) => $page->where('away.machine', 'web-01')->where('away.moving', null));

    $this->post(route('settings.moving.store'), ['code' => moveAwayCode(), 'hand_over' => '1']);

    $this->get(route('settings.general'))
        ->assertInertia(fn(Assert $page) => $page->where('away.machine', 'web-01')->where('away.moving.to', 'Coresmith Cloud'));
});

test('a cancel the helper turns down keeps the move and says why', function () {
    $team = moveAwayTeam();
    moveAwayMachine($team);
    moveAwayHelper(fn(string $what): array => $what === 'keep'
        ? ['ok' => false, 'error' => "Coresmith Cloud has already taken this machine over, so the move can't be cancelled. Remove Coresmith from it instead."]
        : ['ok' => true]);
    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode(), 'hand_over' => '1']);

    $this->delete(route('settings.moving.destroy'))
        ->assertSessionHasErrors(['move' => "Coresmith Cloud has already taken this machine over, so the move can't be cancelled. Remove Coresmith from it instead."]);

    expect($team->fresh()->moving_at)->not->toBeNull()
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Moving);
});

test('a code from another self-hosted Coresmith takes a server on a home network', function () {
    $team = moveAwayTeam();
    moveAwayServer($team)->forceFill(['ip_address' => '192.168.0.10'])->save();

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode('self-hosted', 'https://panel.example.com')])
        ->assertSessionHas('success', 'Saved. Coresmith has paused this team. Upload the move file on panel.example.com to finish.');

    expect($team->fresh()->moving_edition)->toBe('self-hosted');
});

test('a team that is deploying or has a host key waiting stays as it was', function (Closure $arrange, string $refusal) {
    $team = moveAwayTeam();
    $arrange($team);

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode()])
        ->assertSessionHasErrors(['code' => $refusal]);

    expect($team->fresh()->moving_at)->toBeNull()
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Active);
})->with([
    'a deploy running' => [fn(Team $team) => Deployment::factory()->create(['application_id' => $team->applications()->firstOrFail()->id, 'status' => DeploymentStatus::Running]), 'site is deploying. Wait for it to finish, then download the move file.'],
    'a host key waiting' => [fn(Team $team) => moveAwayServer($team)->forceFill(['pending_host_key_fingerprint' => 'SHA256:' . str_repeat('X', 43)])->save(), "web-01's host key changed. Accept or reject the new key on its page, then download the move file."],
]);

test('while it moves the team takes no changes, says so on every page, and can stay', function () {
    $team = moveAwayTeam();
    $owner = moveAwayOwner($team);
    $this->actingAs($owner)->post(route('settings.moving.store'), ['code' => moveAwayCode()]);

    $this->post(route('servers.store'), ['team_id' => $team->id, 'name' => 'web-02', 'ip_address' => '203.0.113.21', 'ssh_port' => 22, 'ssh_user' => 'root'])
        ->assertSessionHas('error', 'This team is moving to Coresmith Cloud, so Coresmith no longer changes it. Cancel the move in Settings, General to make changes here again.');

    $this->get(route('dashboard'))->assertInertia(fn(Assert $page) => $page
        ->where('billing.banner.title', 'This team is moving to Coresmith Cloud.')
        ->where('billing.banner.action', 'See the move')
        ->where('billing.href', route('settings.general')));

    $this->delete(route('settings.moving.destroy'))->assertSessionHas('success', 'Saved. Coresmith manages this team again.');

    expect($team->fresh()->moving_at)->toBeNull()
        ->and($team->servers()->count())->toBe(1)
        ->and(moveAwayServer($team)->status)->toBe(ServerStatus::Active);
});

test('General offers the move to the team owner only', function () {
    $team = moveAwayTeam();
    $admin = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    Team::factory()->create(['owner_id' => $admin->id])->members()->attach($admin, ['role' => TeamRole::Owner->value]);

    $this->actingAs(moveAwayOwner($team))->get(route('settings.general'))
        ->assertInertia(fn(Assert $page) => $page->where('away.moving', null)->where('away.machine', null));

    $this->actingAs($admin)->get(route('settings.general'))
        ->assertInertia(fn(Assert $page) => $page->where('away', null));

    $this->post(route('settings.moving.store'), ['code' => moveAwayCode()])->assertForbidden();
});

test('a self-hosted team with no subscription moves without touching billing', function () {
    Http::preventStrayRequests();
    $team = moveAwayTeam();

    app(TeamMove::class)->start($team, moveAwayOwner($team), moveAwayCode());

    expect($team->fresh()->moving_at)->not->toBeNull();
});

test('Coresmith Cloud has no self-hosted move routes', function () {
    CloudBilling::enable();
    $team = Team::factory()->onTrial()->owned()->create();

    $this->actingAs(moveAwayOwner($team))->post(route('settings.moving.store'), ['code' => moveAwayCode()])->assertNotFound();
});
