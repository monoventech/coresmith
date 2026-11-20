<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\RestoreProtection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProtectionReading;

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

/**
 * @return array{Team, User, Server}
 */
function protectionFixture(TeamRole $role = TeamRole::Owner, array $attributes = []): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => true]);

    return [$team, $user->fresh(), Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'os_name' => 'debian', 'host_key_fingerprint' => null, ...$attributes])];
}

test('turning a protection back on queues the fix and marks it as running', function () {
    Queue::fake();
    $this->travelTo(now()->startOfMinute());
    [, $owner, $server] = protectionFixture();

    $this->actingAs($owner)->post(route('servers.protections.restore', [$server, 'fail2ban']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Turning it back on.');

    Queue::assertPushed(RestoreProtection::class, fn(RestoreProtection $job): bool => $job->server->is($server) && $job->protection === 'fail2ban');
    expect(((array) $server->fresh()->meta)['protections_restoring'])->toBe(['fail2ban' => now()->toIso8601String()]);
});

test('only someone who can change the server can turn a protection back on', function () {
    Queue::fake();
    [, $member, $server] = protectionFixture(TeamRole::Member);

    $this->actingAs($member)->post(route('servers.protections.restore', [$server, 'fail2ban']))->assertForbidden();

    Queue::assertNothingPushed();
});

test('SSH and unknown addresses on Coresmith\'s own machine are left to its owner, so there is nothing to turn on', function (string $protection) {
    Queue::fake();
    [, $owner, $server] = protectionFixture(attributes: ['runs_coresmith' => true]);

    $this->actingAs($owner)->post(route('servers.protections.restore', [$server, $protection]))->assertNotFound();

    Queue::assertNothingPushed();
})->with(['ssh', 'unknown_hosts']);

test('a protection Coresmith does not know is not a route', function () {
    [, $owner, $server] = protectionFixture();

    $this->actingAs($owner)->post('/servers/' . $server->id . '/protections/firewall')->assertNotFound();
});

test('a fix that worked clears its failure and the running mark, and the check reads the server again', function () {
    [, , $server] = protectionFixture();
    $server->forceFill(['meta' => ['protections_errors' => ['kernel' => 'an old failure'], 'protections_restoring' => ['kernel' => now()->toIso8601String()]]])->save();
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport->respondTo('== fail2ban', ProtectionReading::output()));

    app()->call([new RestoreProtection($server, 'kernel'), 'handle']);

    $meta = (array) $server->fresh()->meta;

    expect($meta['protections_errors'] ?? [])->toBe([])
        ->and($meta['protections_restoring'] ?? [])->toBe([])
        ->and($meta['protections']['kernel'])->toBe('on')
        ->and(collect($transport->commands())->contains(fn(string $command): bool => str_contains($command, 'sysctl --system')))->toBeTrue();
});

test('opening HTTP/3 lets UDP 443 through the firewall without touching packages, and says so', function () {
    [, $owner, $server] = protectionFixture();
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport->respondTo('== fail2ban', ProtectionReading::output()));

    $this->actingAs($owner)->post(route('servers.protections.restore', [$server, 'http3']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Opening it.');

    app()->call([new RestoreProtection($server, 'http3'), 'handle']);

    expect(implode("\n", $transport->commands()))->toContain('ufw allow 443/udp')
        ->and(collect($transport->commands())->contains(fn(string $command): bool => str_contains($command, 'apt-get')))->toBeFalse()
        ->and(((array) $server->fresh()->meta)['protections']['http3'])->toBe('on');
});

test('setting up the weekly clean-up caps system logs, installs its timer and runs it once, without updating packages first', function () {
    [, $owner, $server] = protectionFixture(attributes: ['meta' => ['disk_gb' => 80]]);
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport->respondTo('== fail2ban', ProtectionReading::output()));

    $this->actingAs($owner)->post(route('servers.protections.restore', [$server, 'maintenance']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Setting it up.');

    app()->call([new RestoreProtection($server, 'maintenance'), 'handle']);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('systemctl enable --now coresmith-maintenance.timer')
        ->toContain('systemctl restart systemd-journald')
        ->toContain('systemctl start coresmith-maintenance.service')
        ->and(collect($transport->commands())->contains(fn(string $command): bool => str_contains($command, 'apt-get update')))->toBeFalse()
        ->and(collect($transport->uploads())->keys()->all())->toContain('/etc/systemd/journald.conf.d/coresmith.conf', '/etc/systemd/system/coresmith-maintenance.timer', '/etc/systemd/system/coresmith-maintenance.service', '/usr/local/sbin/coresmith-maintenance')
        ->and(((array) $server->fresh()->meta)['protections']['maintenance'])->toBe('on');
});

test('a fix that failed keeps why, for the hardening tab', function () {
    [, , $server] = protectionFixture();
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)
        ->respondTo('apt-get update', "E: Could not get lock /var/lib/apt/lists/lock\n", 100)
        ->respondTo('== fail2ban', ProtectionReading::output()));

    app()->call([new RestoreProtection($server, 'patches'), 'handle']);

    expect(((array) $server->fresh()->meta)['protections_errors'])->toBe(['patches' => 'E: Could not get lock /var/lib/apt/lists/lock'])
        ->and(((array) $server->fresh()->meta)['protections_restoring'] ?? [])->toBe([]);
});
