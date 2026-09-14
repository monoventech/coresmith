<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Exceptions\HostKeyChangedException;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function hostKeyUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function connectWith(Server $server, string $hostKey): void
{
    $transport = (new FakeSSHTransport)->setHostKey($hostKey);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app(SSHConnectionManager::class)->for($server)->execute('true');
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('the first connection pins the key it sees', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    connectWith($server, 'the-original-key');

    expect($server->fresh()->host_key_fingerprint)
        ->toBe(HostKeyFingerprint::fromHostKey('the-original-key'))
        ->and($server->fresh()->pending_host_key_fingerprint)->toBeNull();
});

test('a matching key connects without touching anything', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('the-original-key'),
    ]);

    connectWith($server, 'the-original-key');

    expect($server->fresh()->pending_host_key_fingerprint)->toBeNull()
        ->and($server->fresh()->host_key_changed_at)->toBeNull();
});

test('a changed key blocks the connection and records what was presented', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('the-original-key'),
    ]);

    expect(fn() => connectWith($server, 'an-impostor-key'))->toThrow(HostKeyChangedException::class);

    $server->refresh();

    expect($server->pending_host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('an-impostor-key'))
        ->and($server->host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('the-original-key'))
        ->and($server->host_key_changed_at)->not->toBeNull();
});

test('the change is logged as a security event with both fingerprints', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('the-original-key'),
    ]);

    expect(fn() => connectWith($server, 'an-impostor-key'))->toThrow(HostKeyChangedException::class);

    $activity = Activity::where('description', 'ssh_host_key_changed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['expected'])->toBe(HostKeyFingerprint::fromHostKey('the-original-key'))
        ->and($activity->properties['actual'])->toBe(HostKeyFingerprint::fromHostKey('an-impostor-key'));
});

test('the connection stays blocked while the change is unresolved', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('the-original-key'),
    ]);

    expect(fn() => connectWith($server, 'an-impostor-key'))->toThrow(HostKeyChangedException::class);
    expect(fn() => connectWith($server->fresh(), 'an-impostor-key'))->toThrow(HostKeyChangedException::class);
});

test('accepting the new key lets the connection through afterwards', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('the-original-key'),
    ]);

    expect(fn() => connectWith($server, 'the-new-key'))->toThrow(HostKeyChangedException::class);

    $this->actingAs(hostKeyUser($team, TeamRole::Admin))
        ->post(route('servers.host-key.accept', $server), [
            'fingerprint' => $server->fresh()->pending_host_key_fingerprint,
        ])
        ->assertRedirect();

    $server->refresh();

    expect($server->host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('the-new-key'))
        ->and($server->pending_host_key_fingerprint)->toBeNull()
        ->and($server->host_key_changed_at)->toBeNull();

    connectWith($server, 'the-new-key');

    expect($server->fresh()->pending_host_key_fingerprint)->toBeNull();
});

test('accepting is logged with the key that was replaced', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('old'),
        'pending_host_key_fingerprint' => HostKeyFingerprint::fromHostKey('new'),
        'host_key_changed_at' => now(),
    ]);

    $this->actingAs(hostKeyUser($team, TeamRole::Admin))
        ->post(route('servers.host-key.accept', $server), [
            'fingerprint' => HostKeyFingerprint::fromHostKey('new'),
        ]);

    $activity = Activity::where('description', 'ssh_host_key_accepted')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['previous'])->toBe(HostKeyFingerprint::fromHostKey('old'))
        ->and($activity->properties['accepted'])->toBe(HostKeyFingerprint::fromHostKey('new'))
        ->and($activity->causer_id)->not->toBeNull();
});

test('accepting the new key catches the server up on ssh key changes it missed', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->active()->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('old'),
        'pending_host_key_fingerprint' => HostKeyFingerprint::fromHostKey('new'),
        'host_key_changed_at' => now(),
    ]);

    $this->actingAs(hostKeyUser($team, TeamRole::Admin))
        ->post(route('servers.host-key.accept', $server), ['fingerprint' => HostKeyFingerprint::fromHostKey('new')])
        ->assertRedirect();

    Queue::assertPushed(SyncAuthorizedKeys::class, fn(SyncAuthorizedKeys $job): bool => $job->uniqueId() === (string) $server->id);
});

test('accepting a fingerprint that is not the one presented is refused', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('old'),
        'pending_host_key_fingerprint' => HostKeyFingerprint::fromHostKey('new'),
        'host_key_changed_at' => now(),
    ]);

    $this->actingAs(hostKeyUser($team, TeamRole::Admin))
        ->post(route('servers.host-key.accept', $server), [
            'fingerprint' => HostKeyFingerprint::fromHostKey('something-else'),
        ])
        ->assertSessionHas('error');

    expect($server->fresh()->host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('old'))
        ->and($server->fresh()->pending_host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('new'));
});

test('accepting when nothing is pending changes nothing', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('old'),
        'pending_host_key_fingerprint' => null,
    ]);

    $this->actingAs(hostKeyUser($team, TeamRole::Admin))
        ->post(route('servers.host-key.accept', $server), ['fingerprint' => 'anything'])
        ->assertSessionHas('error');

    expect($server->fresh()->host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('old'));
});

test('dismissing clears the prompt but keeps the original key trusted', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('the-original-key'),
        'pending_host_key_fingerprint' => HostKeyFingerprint::fromHostKey('an-impostor-key'),
        'host_key_changed_at' => now(),
    ]);

    $this->actingAs(hostKeyUser($team, TeamRole::Admin))
        ->post(route('servers.host-key.reject', $server))
        ->assertRedirect();

    $server->refresh();

    expect($server->pending_host_key_fingerprint)->toBeNull()
        ->and($server->host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('the-original-key'));

    expect(fn() => connectWith($server, 'an-impostor-key'))->toThrow(HostKeyChangedException::class);
});

test('a member can neither accept nor dismiss a host key change', function (string $action) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('old'),
        'pending_host_key_fingerprint' => HostKeyFingerprint::fromHostKey('new'),
    ]);

    $this->actingAs(hostKeyUser($team, TeamRole::Member))
        ->post(route($action, $server), ['fingerprint' => HostKeyFingerprint::fromHostKey('new')])
        ->assertForbidden();

    expect($server->fresh()->host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('old'));
})->with(['servers.host-key.accept', 'servers.host-key.reject']);

test('another teams host key cannot be accepted', function () {
    $server = Server::factory()->for(Team::factory()->create())->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('old'),
        'pending_host_key_fingerprint' => HostKeyFingerprint::fromHostKey('new'),
    ]);

    $this->actingAs(hostKeyUser(Team::factory()->create(), TeamRole::Owner))
        ->post(route('servers.host-key.accept', $server), ['fingerprint' => HostKeyFingerprint::fromHostKey('new')])
        ->assertForbidden();

    expect($server->fresh()->host_key_fingerprint)->toBe(HostKeyFingerprint::fromHostKey('old'));
});

test('the server page carries the pending key so it can be shown', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('old'),
        'pending_host_key_fingerprint' => HostKeyFingerprint::fromHostKey('new'),
        'host_key_changed_at' => now(),
    ]);

    $this->actingAs(hostKeyUser($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('server.host_key.observed', HostKeyFingerprint::fromHostKey('new'))
            ->where('server.host_key.expected', HostKeyFingerprint::fromHostKey('old'))
            ->where('server.host_key.changed_at', $server->fresh()->host_key_changed_at->toIso8601String()));
});

test('the server page carries no pending key when nothing has changed', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('old'),
        'pending_host_key_fingerprint' => null,
    ]);

    $this->actingAs(hostKeyUser($team, TeamRole::Member))
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('server.host_key', null));
});
