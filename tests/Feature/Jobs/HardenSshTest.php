<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\HardenSsh;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function hardenSshSettings(string $password = 'no', string $root = 'without-password', string $keyboard = 'no'): string
{
    return "port 22\npermitrootlogin {$root}\npubkeyauthentication yes\npasswordauthentication {$password}\nkbdinteractiveauthentication {$keyboard}\n";
}

function hardenSshRun(Server $server, FakeSSHTransport $transport): FakeSSHTransport
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    app()->call([new HardenSsh($server), 'handle']);

    return $transport;
}

function hardenSshServer(array $meta = ['cpu_cores' => 2]): Server
{
    return Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_port' => 2222, 'meta' => $meta]);
}

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('the SSH settings go in a file sshd reads before any other, so an earlier file cannot turn passwords back on', function () {
    $server = hardenSshServer();
    $transport = hardenSshRun($server, (new FakeSSHTransport)->respondTo('sshd -T', hardenSshSettings()));

    expect(array_keys($transport->uploads()))->toBe(['/etc/ssh/sshd_config.d/00-coresmith.conf'])
        ->and($transport->uploads()['/etc/ssh/sshd_config.d/00-coresmith.conf'])->toContain("Port 2222\nPasswordAuthentication no\nKbdInteractiveAuthentication no\nChallengeResponseAuthentication no\n")
        ->and(implode("\n", $transport->commands()))->toContain('rm -f /etc/ssh/sshd_config.d/99-coresmith.conf')
        ->and($server->fresh()->meta)->toBeInAnyKeyOrder(['cpu_cores' => 2, 'ssh_hardening' => 'verified']);
});

test('a server whose sshd never reads the drop-in folder gets the settings at the top of sshd_config instead, checked before they replace it', function () {
    $server = hardenSshServer();
    $transport = hardenSshRun($server, (new FakeSSHTransport)
        ->respondTo(SecurityHardening::READS_DROP_INS, '', 1)
        ->respondTo('sshd -T', hardenSshSettings()));
    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("printf '%s\\n' '# coresmith-sshd begin' 'PasswordAuthentication no' 'KbdInteractiveAuthentication no' 'ChallengeResponseAuthentication no' 'PermitRootLogin prohibit-password'")
        ->and($commands)->toContain('sshd -t -f "$tmp" && cat "$tmp" > /etc/ssh/sshd_config')
        ->and($commands)->not->toContain('sntrup761')
        ->and($server->fresh()->meta['ssh_hardening'] ?? null)->toBe('verified');
});

test('when sshd still accepts passwords after the drop-in, the settings also go at the top of sshd_config and sshd is checked again', function () {
    $server = hardenSshServer();
    $transport = hardenSshRun($server, (new FakeSSHTransport)->respondToSequence('sshd -T', [[hardenSshSettings(password: 'yes')], [hardenSshSettings()]]));

    expect(implode("\n", $transport->commands()))->toContain("'# coresmith-sshd begin'")
        ->and(count(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'sshd -T'))))->toBe(2)
        ->and($server->fresh()->meta['ssh_hardening'] ?? null)->toBe('verified');
});

test('sshd that still accepts passwords is recorded with what it reports, never as verified', function (string $settings, string $reported) {
    $server = hardenSshServer(['cpu_cores' => 2, 'ssh_hardening' => 'verified']);
    hardenSshRun($server, (new FakeSSHTransport)->respondTo('sshd -T', $settings));

    expect($server->fresh()->meta)->toBeInAnyKeyOrder(['cpu_cores' => 2, 'ssh_hardening_error' => $reported]);
})->with([
    'passwords' => [hardenSshSettings(password: 'yes'), 'sshd reports passwordauthentication yes'],
    'keyboard-interactive' => [hardenSshSettings(keyboard: 'yes'), 'sshd reports kbdinteractiveauthentication yes'],
    'root with a password' => [hardenSshSettings(root: 'yes'), 'sshd reports permitrootlogin yes'],
    'an older sshd' => ["permitrootlogin without-password\npasswordauthentication no\nchallengeresponseauthentication yes\n", 'sshd reports challengeresponseauthentication yes'],
    'nothing at all' => ['', "sshd didn't report its settings"],
]);

test('a step that fails is recorded with what went wrong, and a later success clears it', function () {
    $server = hardenSshServer();
    hardenSshRun($server, (new FakeSSHTransport)->respondTo('sshd -t', "/etc/ssh/sshd_config line 3: Bad configuration option: Foo\n", 255));

    expect($server->fresh()->meta)->toBeInAnyKeyOrder(['cpu_cores' => 2, 'ssh_hardening_error' => '/etc/ssh/sshd_config line 3: Bad configuration option: Foo']);

    hardenSshRun($server->fresh(), (new FakeSSHTransport)->respondTo('sshd -T', hardenSshSettings()));

    expect($server->fresh()->meta)->toBeInAnyKeyOrder(['cpu_cores' => 2, 'ssh_hardening' => 'verified']);
});

test('every hour, each active and reachable server not yet verified gets its SSH settings, and only those', function () {
    Queue::fake();
    $due = Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['cpu_cores' => 2]]);
    $failing = Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['ssh_hardening_error' => 'sshd reports passwordauthentication yes']]);
    Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['ssh_hardening' => 'verified']]);
    Server::factory()->create(['status' => ServerStatus::Active, 'unreachable_since' => now()]);
    Server::factory()->create(['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:changed']);
    Server::factory()->create(['status' => ServerStatus::Pending]);

    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:harden-ssh');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');

    $event->run(app());

    Queue::assertPushed(HardenSsh::class, 2);
    Queue::assertPushed(HardenSsh::class, fn(HardenSsh $job): bool => $job->server->is($due));
    Queue::assertPushed(HardenSsh::class, fn(HardenSsh $job): bool => $job->server->is($failing));
});

test('a server never gets its SSH settings from two jobs at once', function () {
    $server = Server::factory()->make(['id' => '01JC0000000000000000000000']);

    expect(new HardenSsh($server))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new HardenSsh($server))->uniqueId())->toBe('01JC0000000000000000000000');
});

function hardenSshThisMachine(bool $withKey, array $attributes = []): Server
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    if ($withKey) {
        $owner->sshKeys()->create(['name' => 'laptop', 'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIK3c2T1iNFs1YD4dSAe0LJ6V8Gt5hhX8ArXQpZ5MA7qC owner@laptop', 'fingerprint' => 'SHA256:laptop']);
    }

    return Server::factory()->for($team)->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_port' => 22, 'runs_coresmith' => true, 'ip_address' => '127.0.0.1', 'meta' => [], ...$attributes]);
}

test("on Coresmith's own machine, password logins stay on until an owner or admin has an SSH key, so nobody is locked out of it", function () {
    $server = hardenSshThisMachine(false);
    $transport = hardenSshRun($server, (new FakeSSHTransport)->respondTo('sshd -T', hardenSshSettings('yes', 'without-password', 'yes')));
    $dropIn = (string) $transport->uploads()['/etc/ssh/sshd_config.d/00-coresmith.conf'];

    expect($dropIn)->not->toContain('PasswordAuthentication')
        ->and($dropIn)->not->toContain('KbdInteractiveAuthentication')
        ->and($dropIn)->not->toContain('ChallengeResponseAuthentication')
        ->and($dropIn)->toContain("PermitRootLogin prohibit-password\n")
        ->and($server->fresh()->meta)->toBe(['ssh_hardening' => 'passwords_kept'])
        ->and($server->fresh()->sshHardened())->toBeFalse();
});

test("once an owner or admin has a key there, Coresmith's own machine turns password logins off like every server", function () {
    $server = hardenSshThisMachine(true);
    $transport = hardenSshRun($server, (new FakeSSHTransport)->respondTo('sshd -T', hardenSshSettings()));

    expect($transport->uploads()['/etc/ssh/sshd_config.d/00-coresmith.conf'])->toContain("PasswordAuthentication no\nKbdInteractiveAuthentication no\nChallengeResponseAuthentication no\n")
        ->and($server->fresh()->meta)->toBe(['ssh_hardening' => 'verified']);
});

test("even while password logins stay on, root never signs in with a password on Coresmith's own machine", function () {
    $server = hardenSshThisMachine(false);

    hardenSshRun($server, (new FakeSSHTransport)->respondTo('sshd -T', hardenSshSettings('yes', 'yes')));

    expect($server->fresh()->meta)->toBe(['ssh_hardening_error' => 'sshd reports permitrootlogin yes']);
});

test("the first owner or admin key synced onto Coresmith's own machine turns its password logins off", function (array $server, bool $hardened) {
    Queue::fake([HardenSsh::class]);
    $machine = hardenSshThisMachine(true, ['status' => ServerStatus::Active, ...$server]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => new FakeSSHTransport);

    app()->call([new SyncAuthorizedKeys($machine), 'handle']);

    $hardened ? Queue::assertPushed(HardenSsh::class, fn(HardenSsh $job): bool => $job->server->is($machine)) : Queue::assertNotPushed(HardenSsh::class);
})->with([
    'passwords kept until now' => [['meta' => ['ssh_hardening' => 'passwords_kept']], true],
    'passwords already off' => [['meta' => ['ssh_hardening' => 'verified']], false],
    'another server' => [['runs_coresmith' => false, 'meta' => ['ssh_hardening' => 'passwords_kept']], false],
]);
