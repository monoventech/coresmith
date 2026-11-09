<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\MoveCaddyAdminToSocket;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('a move that fails is recorded with what went wrong, for the hardening tab', function () {
    Sleep::fake();
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => ['cpu_cores' => 2]]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)
        ->respondTo('--unix-socket', 'curl: (7) Failed to connect', 7)
        ->respondTo("-X GET 'http://localhost:2019/config/'", "curl: (7) Failed to connect to localhost port 2019 after 0 ms: Couldn't connect to server\n", 7));

    app()->call([new MoveCaddyAdminToSocket($server), 'handle']);

    expect($server->fresh()->meta)->toBeInAnyKeyOrder([
        'cpu_cores' => 2,
        'caddy_admin_error' => "curl: (7) Failed to connect to localhost port 2019 after 0 ms: Couldn't connect to server",
    ]);
});

test('a move that succeeds clears an earlier failure', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => ['caddy_admin_error' => 'an old failure']]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => new FakeSSHTransport);

    app()->call([new MoveCaddyAdminToSocket($server), 'handle']);

    expect($server->fresh()->meta)->toBe(['caddy_admin' => 'socket']);
});

test('every hour, each active and reachable server still on tcp is moved, and only those', function () {
    Queue::fake();
    $due = Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['cpu_cores' => 2]]);
    Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['caddy_admin' => 'socket']]);
    Server::factory()->create(['status' => ServerStatus::Active, 'unreachable_since' => now()]);
    Server::factory()->create(['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:changed']);
    Server::factory()->create(['status' => ServerStatus::Pending]);

    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:move-caddy-admin');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');

    $event->run(app());

    Queue::assertPushed(MoveCaddyAdminToSocket::class, 1);
    Queue::assertPushed(MoveCaddyAdminToSocket::class, fn(MoveCaddyAdminToSocket $job): bool => $job->server->is($due));
});

test('a server is never moved by two jobs at once', function () {
    $server = Server::factory()->make(['id' => '01JC0000000000000000000000']);

    expect(new MoveCaddyAdminToSocket($server))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new MoveCaddyAdminToSocket($server))->uniqueId())->toBe('01JC0000000000000000000000');
});
