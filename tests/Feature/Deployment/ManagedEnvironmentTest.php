<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Deployment\ManagedEnvironment;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function managedEnvApp(array $env, bool $reverb, array $attributes = []): Application
{
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    return Application::factory()->for($server)->create([
        'slug' => 'chat',
        'domain' => 'chat.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'base_port' => 8200,
        'env_variables' => implode("\n", array_map(fn(string $key, string $value): string => "{$key}={$value}", array_keys($env), $env)) . "\n",
        'toggles' => ['reverb' => $reverb],
        'stack' => ['packages' => ['reverb' => true]],
        ...$attributes,
    ]);
}

function managedWritten(Application $application): string
{
    $transport = new FakeSSHTransport;

    app(EnvironmentFile::class)->write((new SSHConnection($application->server, $transport))->connect(), $application);

    return $transport->uploads()['/etc/coresmith/env/chat.env'] ?? '';
}

const MANAGED_CREDENTIALS = ['APP_ENV' => 'production', 'REVERB_APP_ID' => 'chat-app', 'REVERB_APP_KEY' => 'chat-key', 'REVERB_APP_SECRET' => 's3cret'];

test('reverb on writes what the server and the browser build need after the app own values', function () {
    expect(managedWritten(managedEnvApp(MANAGED_CREDENTIALS, true)))->toBe(implode("\n", [
        'APP_ENV="production"',
        'REVERB_APP_ID="chat-app"',
        'REVERB_APP_KEY="chat-key"',
        'REVERB_APP_SECRET="s3cret"',
        'REVERB_SERVER_HOST="127.0.0.1"',
        'REVERB_SERVER_PORT="8202"',
        'REVERB_HOST="chat.example.com"',
        'REVERB_PORT="443"',
        'REVERB_SCHEME="https"',
        'VITE_REVERB_HOST="chat.example.com"',
        'VITE_REVERB_PORT="443"',
        'VITE_REVERB_SCHEME="https"',
        'VITE_REVERB_APP_KEY="chat-key"',
    ]) . "\n");
});

test("the app's own value wins and is not repeated", function () {
    $written = managedWritten(managedEnvApp([...MANAGED_CREDENTIALS, 'REVERB_HOST' => 'ws.example.com', 'VITE_REVERB_PORT' => '8443'], true));

    expect(substr_count($written, 'REVERB_HOST='))->toBe(2)
        ->and($written)->toContain("\nREVERB_HOST=\"ws.example.com\"\n")
        ->and($written)->toContain("\nVITE_REVERB_HOST=\"chat.example.com\"\n")
        ->and(substr_count($written, 'VITE_REVERB_PORT='))->toBe(1)
        ->and($written)->toContain("\nVITE_REVERB_PORT=\"8443\"\n");
});

test('the stack being deployed decides, not the one running now', function () {
    $application = managedEnvApp(MANAGED_CREDENTIALS, true, ['stack' => ['packages' => ['reverb' => false]]]);

    expect(app(ManagedEnvironment::class)->values($application, ['packages' => ['reverb' => true]]))->toHaveKey('REVERB_SERVER_PORT')
        ->and(app(ManagedEnvironment::class)->values(managedEnvApp(MANAGED_CREDENTIALS, true, ['slug' => 'chat-two']), ['packages' => ['reverb' => false]]))->toBe([]);
});

test('nothing is managed while reverb is off or not ready', function (array $env, bool $reverb, array $attributes) {
    $application = managedEnvApp($env, $reverb, $attributes);

    expect(app(ManagedEnvironment::class)->values($application))->toBe([])
        ->and(managedWritten($application))->not->toContain('REVERB_SERVER_PORT');
})->with([
    'off' => [MANAGED_CREDENTIALS, false, []],
    'a release without the package' => [MANAGED_CREDENTIALS, true, ['stack' => ['packages' => ['reverb' => false]]]],
    'no secret' => [['REVERB_APP_ID' => 'chat-app', 'REVERB_APP_KEY' => 'chat-key'], true, []],
    'a key that is not path safe' => [[...MANAGED_CREDENTIALS, 'REVERB_APP_KEY' => 'chat/key'], true, []],
    'no domain' => [MANAGED_CREDENTIALS, true, ['domain' => null]],
]);
