<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function syncKeysMember(Team $team, TeamRole $role, ?string $publicKey = null): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    if ($publicKey !== null) {
        SshKey::factory()->for($user)->create(['public_key' => $publicKey]);
    }

    return $user;
}

function syncKeysRun(Server $server, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new SyncAuthorizedKeys($server))->handle(app(SSHConnectionManager::class), new SecurityHardening);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function syncKeysLogged(): bool
{
    return Activity::query()->where('description', 'authorized_keys_synced')->exists();
}

test('a sync writes the keys of the teams owners and admins to the server and records how many', function () {
    $team = Team::factory()->create();
    syncKeysMember($team, TeamRole::Owner, 'ssh-ed25519 AAAAowner owner@laptop');
    syncKeysMember($team, TeamRole::Admin, 'ssh-ed25519 AAAAadmin admin@laptop');
    syncKeysMember($team, TeamRole::Member, 'ssh-ed25519 AAAAdeployer deployer@laptop');
    $server = Server::factory()->for($team)->active()->create();
    $transport = new FakeSSHTransport;

    syncKeysRun($server, $transport);

    $hardening = new SecurityHardening;
    $activity = Activity::query()->where('description', 'authorized_keys_synced')->sole();

    expect($transport->commands())->toBe([$hardening->authorizedKeysSyncCommand('root', $hardening->userKeys($server))])
        ->and($transport->commands()[0])->toContain('ssh-ed25519 AAAAowner owner@laptop')
        ->and($transport->commands()[0])->toContain('ssh-ed25519 AAAAadmin admin@laptop')
        ->and($transport->commands()[0])->not->toContain('AAAAdeployer')
        ->and($activity->subject_id)->toBe($server->id)
        ->and($activity->properties->toArray())->toBe(['keys' => 2])
        ->and($transport->connected())->toBeFalse();
});

test('a sync with no keys left still runs, so the last key comes off the server', function () {
    $team = Team::factory()->create();
    syncKeysMember($team, TeamRole::Owner);
    $server = Server::factory()->for($team)->active()->create();
    $transport = new FakeSSHTransport;

    syncKeysRun($server, $transport);

    expect($transport->commands())->toBe([(new SecurityHardening)->authorizedKeysSyncCommand('root', [])])
        ->and(Activity::query()->where('description', 'authorized_keys_synced')->sole()->properties->toArray())->toBe(['keys' => 0]);
});

test('a sync as a non root ssh user works in that users home without sudo', function () {
    $team = Team::factory()->create();
    syncKeysMember($team, TeamRole::Owner, 'ssh-ed25519 AAAAowner owner@laptop');
    $server = Server::factory()->for($team)->active()->create(['ssh_user' => 'deploy']);
    $transport = new FakeSSHTransport;

    syncKeysRun($server, $transport);

    expect($transport->commands())->toHaveCount(1)
        ->and($transport->commands()[0])->toStartWith("mkdir -p '/home/deploy/.ssh' && ")
        ->and($transport->commands()[0])->not->toContain('sudo');
});

test('a server that is not active or is waiting on a host key decision is left alone', function (array $state) {
    $team = Team::factory()->create();
    syncKeysMember($team, TeamRole::Owner, 'ssh-ed25519 AAAAowner owner@laptop');
    $server = Server::factory()->for($team)->active()->create($state);
    $transport = new FakeSSHTransport;

    syncKeysRun($server, $transport);

    expect($transport->loginAttempted())->toBeFalse()
        ->and($transport->commands())->toBe([])
        ->and(syncKeysLogged())->toBeFalse();
})->with([
    'pending' => [['status' => ServerStatus::Pending]],
    'provisioning' => [['status' => ServerStatus::Provisioning]],
    'failed' => [['status' => ServerStatus::Failed]],
    'disconnected' => [['status' => ServerStatus::Disconnected]],
    'presenting a new host key' => [['pending_host_key_fingerprint' => 'SHA256:a-key-nobody-has-accepted']],
]);

test('a server detached from the panel is left alone', function () {
    $team = Team::factory()->create();
    syncKeysMember($team, TeamRole::Owner, 'ssh-ed25519 AAAAowner owner@laptop');
    $server = Server::factory()->for($team)->active()->create();
    $server->delete();
    $transport = new FakeSSHTransport;

    syncKeysRun($server, $transport);

    expect($transport->loginAttempted())->toBeFalse()
        ->and(syncKeysLogged())->toBeFalse();
});

test('a sync the server refuses is not recorded as done', function () {
    $server = Server::factory()->active()->create();
    $transport = (new FakeSSHTransport)->respondTo('authorized_keys', 'mktemp: Permission denied', 1);

    expect(fn() => syncKeysRun($server, $transport))->toThrow(SSHCommandFailedException::class);

    expect(syncKeysLogged())->toBeFalse()
        ->and($transport->connected())->toBeFalse();
});

test('two syncs of the same server never write authorized_keys at the same time', function () {
    Sleep::fake(syncWithCarbon: true);
    $server = Server::factory()->active()->create();
    $transport = new FakeSSHTransport;
    $running = Cache::lock(SyncAuthorizedKeys::lockKey($server), 60);

    expect($running->get())->toBeTrue();

    expect(fn() => syncKeysRun($server, $transport))->toThrow(LockTimeoutException::class);

    expect($transport->commands())->toBe([])
        ->and(syncKeysLogged())->toBeFalse();

    $running->release();
});

test('a sync still waiting in the queue absorbs further syncs of the same server only', function () {
    Queue::fake();
    $first = Server::factory()->active()->create();
    $second = Server::factory()->active()->create();

    SyncAuthorizedKeys::dispatch($first);
    SyncAuthorizedKeys::dispatch($first);
    SyncAuthorizedKeys::dispatch($second);

    expect(new SyncAuthorizedKeys($first))->toBeInstanceOf(ShouldBeUnique::class)
        ->and(Queue::pushed(SyncAuthorizedKeys::class)->map(fn(SyncAuthorizedKeys $job): string => $job->uniqueId())->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());

    Queue::assertPushedOn('server-tasks', SyncAuthorizedKeys::class);
});

test('a sync frees its place in the queue as soon as it starts, so a change made while it runs gets its own sync', function () {
    $server = Server::factory()->active()->create();
    $freeWhileRunning = null;

    SSHConnectionManager::createTransportUsing(function () use ($server, &$freeWhileRunning): FakeSSHTransport {
        $freeWhileRunning = Cache::lock(UniqueLock::getKey(new SyncAuthorizedKeys($server)))->get();

        return new FakeSSHTransport;
    });

    try {
        SyncAuthorizedKeys::dispatch($server);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($freeWhileRunning)->toBeTrue()
        ->and(syncKeysLogged())->toBeTrue();
});

test('only active servers of the given teams are picked for a sync', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $active = Server::factory()->for($team)->active()->create();
    $alsoActive = Server::factory()->for($other)->active()->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Pending]);
    Server::factory()->for($team)->active()->create()->delete();
    Server::factory()->active()->create();

    expect(SyncAuthorizedKeys::activeServersOf([$team->id, $other->id])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$active->id, $alsoActive->id])->sort()->values()->all())
        ->and(SyncAuthorizedKeys::activeServersOf([]))->toBeEmpty();
});
