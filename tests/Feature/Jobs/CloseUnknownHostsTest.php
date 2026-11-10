<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\CloseUnknownHosts;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('a server without the catch-all gets it after every app route, saved, and is marked closed', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => ['caddy_admin' => 'socket']]);
    $transport = (new FakeSSHTransport)->respondTo("-X GET --unix-socket /run/caddy-admin/admin.sock 'http://localhost/id/coresmith-unknown'", 'curl: (22) The requested URL returned error: 404', 22);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    app()->call([new CloseUnknownHosts($server), 'handle']);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("-X POST --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/apps/http/servers/coresmith/routes'")
        ->toContain('"@id":"coresmith-unknown"')
        ->toContain('"abort":true')
        ->and($server->fresh()->meta)->toBeInAnyKeyOrder(['caddy_admin' => 'socket', 'caddy_unknown_hosts' => 'closed']);
});

test('a server that already has the catch-all gets no second one, and is marked closed', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => ['caddy_admin' => 'socket']]);
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    app()->call([new CloseUnknownHosts($server), 'handle']);

    expect(implode("\n", $transport->commands()))->not->toContain('-X POST')
        ->and($server->fresh()->unknownHostsClosed())->toBeTrue();
});

test('a server Caddy refuses stays as it was, to be tried again', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => ['caddy_admin' => 'socket']]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)
        ->respondTo("'http://localhost/id/coresmith-unknown'", 'curl: (22) The requested URL returned error: 404', 22)
        ->respondTo('-X POST', 'curl: (22) The requested URL returned error: 400', 22));

    app()->call([new CloseUnknownHosts($server), 'handle']);

    expect($server->fresh()->unknownHostsClosed())->toBeFalse();
});

test('every hour, each active and reachable server not yet closed is closed, never Coresmith\'s own machine', function () {
    Queue::fake();
    $due = Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['caddy_admin' => 'socket']]);
    Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['caddy_unknown_hosts' => 'closed']]);
    Server::factory()->create(['status' => ServerStatus::Active, 'runs_coresmith' => true]);
    Server::factory()->create(['status' => ServerStatus::Active, 'unreachable_since' => now()]);
    Server::factory()->create(['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:changed']);
    Server::factory()->create(['status' => ServerStatus::Pending]);

    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:close-unknown-hosts');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');

    $event->run(app());

    Queue::assertPushed(CloseUnknownHosts::class, 1);
    Queue::assertPushed(CloseUnknownHosts::class, fn(CloseUnknownHosts $job): bool => $job->server->is($due));
});

test('a server is never closed by two jobs at once', function () {
    $server = Server::factory()->make(['id' => '01JC0000000000000000000000']);

    expect(new CloseUnknownHosts($server))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new CloseUnknownHosts($server))->uniqueId())->toBe('01JC0000000000000000000000');
});
