<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\OpenCaddyTimeouts;
use App\Models\Server;
use App\Services\Caddy\CaddyInstaller;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

function openTimeoutsServer(array $meta = ['caddy_admin' => 'socket']): Server
{
    return Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => $meta]);
}

test('new Caddy servers give a request five minutes to arrive and never cut a response short', function () {
    $server = json_decode(app(CaddyInstaller::class)->config(), true)['apps']['http']['servers'][CaddyInstaller::SERVER];

    expect($server['read_timeout'])->toBe('300s')
        ->and($server)->not->toHaveKey('write_timeout')
        ->and($server['read_header_timeout'])->toBe('5s')
        ->and($server['idle_timeout'])->toBe('120s');
});

test('a server set up with the old timeouts gets five minutes to read a request and no write timeout, saved, and is marked', function () {
    $server = openTimeoutsServer();
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    app()->call([new OpenCaddyTimeouts($server), 'handle']);

    expect(implode("\n", $transport->commands()))
        ->toContain("-X POST --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/apps/http/servers/coresmith/read_timeout' -H 'Content-Type: application/json' -d '\"300s\"'")
        ->toContain("-X DELETE --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/apps/http/servers/coresmith/write_timeout'")
        ->toContain('caddy-config.staged.json')
        ->and($server->fresh()->meta)->toBeInAnyKeyOrder(['caddy_admin' => 'socket', 'caddy_timeouts' => 'open']);
});

test('a server that has no write timeout left is marked all the same', function () {
    $server = openTimeoutsServer();
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)
        ->respondTo('-X DELETE', 'curl: (22) The requested URL returned error: 404', 22));

    app()->call([new OpenCaddyTimeouts($server), 'handle']);

    expect($server->fresh()->meta['caddy_timeouts'] ?? null)->toBe('open');
});

test('a server Caddy refuses stays as it was, to be tried again', function () {
    $server = openTimeoutsServer();
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)
        ->respondTo('-X POST', 'curl: (22) The requested URL returned error: 400', 22));

    app()->call([new OpenCaddyTimeouts($server), 'handle']);

    expect($server->fresh()->meta['caddy_timeouts'] ?? null)->toBeNull();
});

test('every hour, each active and reachable server whose timeouts are not open gets them, Coresmith\'s own machine included', function () {
    Queue::fake();
    $due = Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['caddy_admin' => 'socket']]);
    $own = Server::factory()->create(['status' => ServerStatus::Active, 'runs_coresmith' => true]);
    Server::factory()->create(['status' => ServerStatus::Active, 'meta' => ['caddy_timeouts' => 'open']]);
    Server::factory()->create(['status' => ServerStatus::Active, 'unreachable_since' => now()]);
    Server::factory()->create(['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:changed']);
    Server::factory()->create(['status' => ServerStatus::Pending]);

    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:open-caddy-timeouts');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');

    $event->run(app());

    Queue::assertPushed(OpenCaddyTimeouts::class, 2);
    Queue::assertPushed(OpenCaddyTimeouts::class, fn(OpenCaddyTimeouts $job): bool => $job->server->is($due));
    Queue::assertPushed(OpenCaddyTimeouts::class, fn(OpenCaddyTimeouts $job): bool => $job->server->is($own));
});

test('a server never has its timeouts changed by two jobs at once', function () {
    $server = Server::factory()->make(['id' => '01JC0000000000000000000000']);

    expect(new OpenCaddyTimeouts($server))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new OpenCaddyTimeouts($server))->uniqueId())->toBe('01JC0000000000000000000000');
});
