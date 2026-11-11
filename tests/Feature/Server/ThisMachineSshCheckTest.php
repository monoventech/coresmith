<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\CheckThisMachineSsh;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function thisMachineSshCheckMember(Team $team, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user;
}

function thisMachineSshCheckServer(Team $team, array $attributes = []): Server
{
    return Server::factory()->for($team)->create([
        'name' => 'beelink',
        'runs_coresmith' => true,
        'os_name' => 'ubuntu',
        'meta' => ['ssh_hardening' => 'owner', 'ssh_passwords' => 'on', 'ssh_password_logins' => ['password', 'root'], 'ssh_password_sources' => ['password' => '/etc/ssh/sshd_config.d/50-cloud-init.conf'], 'ssh_passwords_checked_at' => '2026-11-11T13:00:00+00:00'],
        ...$attributes,
    ]);
}

test('checking again queues a read of this machine and says so', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = thisMachineSshCheckServer($team);

    $this->actingAs(thisMachineSshCheckMember($team))
        ->post(route('servers.ssh-check', $server))
        ->assertRedirect()
        ->assertSessionHas('success', 'Checking now.');

    Queue::assertPushed(CheckThisMachineSsh::class, fn(CheckThisMachineSsh $job): bool => $job->server->is($server));
});

test('only this machine, with SSH left to its owner, can be checked', function (array $attributes) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = thisMachineSshCheckServer($team, $attributes);

    $this->actingAs(thisMachineSshCheckMember($team))
        ->post(route('servers.ssh-check', $server))
        ->assertNotFound();

    Queue::assertNothingPushed();
})->with([
    'a server Coresmith manages' => [['runs_coresmith' => false, 'meta' => ['ssh_hardening' => 'verified']]],
    'this machine before the hand-back' => [['meta' => ['ssh_hardening' => 'verified']]],
]);

test('members who cannot change the server cannot check it', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = thisMachineSshCheckServer($team);

    $this->actingAs(thisMachineSshCheckMember($team, TeamRole::Member))
        ->post(route('servers.ssh-check', $server))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('checking again is slowed down after a few tries', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = thisMachineSshCheckServer($team);
    $owner = thisMachineSshCheckMember($team);

    foreach (range(1, 6) as $try) {
        $this->actingAs($owner)->post(route('servers.ssh-check', $server))->assertRedirect();
    }

    $this->actingAs($owner)->post(route('servers.ssh-check', $server))->assertTooManyRequests();
});

test('the Hardening tab carries what is on, the lines to write and how to reload', function (string $os, string $reload) {
    $team = Team::factory()->create();
    $server = thisMachineSshCheckServer($team, ['os_name' => $os]);

    $this->actingAs(thisMachineSshCheckMember($team, TeamRole::Member))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page
            ->where('ssh.passwords_off', false)
            ->where('ssh.logins', ['password' => true, 'keyboard' => false, 'root' => true])
            ->where('ssh.source', '/etc/ssh/sshd_config.d/50-cloud-init.conf')
            ->where('ssh.lines', ['PasswordAuthentication no', 'PermitRootLogin prohibit-password'])
            ->where('ssh.reload', $reload)
            ->where('ssh.checked', fn($checked) => is_string($checked) && $checked !== ''));
})->with([
    'ubuntu reloads ssh' => ['ubuntu', 'ssh'],
    'debian reloads ssh' => ['debian', 'ssh'],
    'rocky reloads sshd' => ['rocky', 'sshd'],
]);

test('a login that sshd turns on by itself has no file to name', function () {
    $team = Team::factory()->create();
    $server = thisMachineSshCheckServer($team, ['meta' => ['ssh_hardening' => 'owner', 'ssh_passwords' => 'on', 'ssh_password_logins' => ['keyboard'], 'ssh_password_sources' => []]]);

    $this->actingAs(thisMachineSshCheckMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page
            ->where('ssh.logins', ['password' => false, 'keyboard' => true, 'root' => false])
            ->where('ssh.source', null)
            ->where('ssh.lines', ['KbdInteractiveAuthentication no']));
});

test('with password login off, or not read yet, there is nothing to warn about', function (array $meta) {
    $team = Team::factory()->create();
    $server = thisMachineSshCheckServer($team, ['meta' => $meta]);

    $this->actingAs(thisMachineSshCheckMember($team))
        ->get(route('servers.hardening', $server))
        ->assertInertia(fn($page) => $page->where('ssh.logins', null)->where('ssh.lines', []));
})->with([
    'off' => [['ssh_hardening' => 'owner', 'ssh_passwords' => 'off']],
    'not read yet' => [['ssh_hardening' => 'owner']],
]);
