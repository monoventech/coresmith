<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\ReservePortBlock;
use App\Models\Application;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function guardRun(Application $application, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new ReservePortBlock($application), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function guardListeners(string $line): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('ss -tlnpH', $line);
}

test('an app whose block another process uses moves to a free block and says why', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $application = Application::factory()->for($server)->create(['base_port' => 8000, 'current_release' => null]);

    guardRun($application, guardListeners('LISTEN 0 511 0.0.0.0:8005 0.0.0.0:* users:(("java",pid=1,fd=3))'));

    expect($application->fresh()->base_port)->toBe(8010);

    $activity = Activity::query()->where('description', 'port_block_moved')->sole();

    expect($activity->properties->toArray())->toBeInAnyKeyOrder(['from' => 8000, 'to' => 8010, 'port' => 8005, 'process' => 'java']);
});

test('two listeners in two consecutive blocks push the app past both', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $application = Application::factory()->for($server)->create(['base_port' => 8000, 'current_release' => null]);

    guardRun($application, guardListeners(implode("\n", [
        'LISTEN 0 511 0.0.0.0:8005 0.0.0.0:* users:(("java",pid=1,fd=3))',
        'LISTEN 0 511 0.0.0.0:8015 0.0.0.0:* users:(("redis",pid=2,fd=3))',
    ])));

    expect($application->fresh()->base_port)->toBe(8020);
});

test('a free block, a deployed app or a server that is not active is left alone', function (array $server, array $application, string $line) {
    $app = Application::factory()->for(Server::factory()->create([...$server, 'host_key_fingerprint' => null]))->create(['base_port' => 8000, ...$application]);

    guardRun($app, guardListeners($line));

    expect($app->fresh()->base_port)->toBe(8000)
        ->and(Activity::query()->where('description', 'port_block_moved')->exists())->toBeFalse();
})->with([
    'free' => [['status' => ServerStatus::Active], ['current_release' => null], 'LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("caddy",pid=1,fd=3))'],
    'deployed' => [['status' => ServerStatus::Active], ['current_release' => '20260914-100000'], 'LISTEN 0 511 0.0.0.0:8005 0.0.0.0:* users:(("java",pid=1,fd=3))'],
    'pending server' => [['status' => ServerStatus::Pending], ['current_release' => null], 'LISTEN 0 511 0.0.0.0:8005 0.0.0.0:* users:(("java",pid=1,fd=3))'],
]);
