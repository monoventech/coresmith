<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AppSize;
use App\Enums\PowerState;
use App\Models\Application;
use App\Models\Server;
use App\Services\Process\FpmPoolBuilder;
use App\Services\Process\ProcessManager;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function processManager(): ProcessManager
{
    $asdf = new AsdfManager;

    return new ProcessManager(new SystemdUnitBuilder($asdf), new FpmPoolBuilder($asdf));
}

function processConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

    return (new SSHConnection($server, $transport))->connect();
}

function managedApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'my-app',
        'name' => 'My App',
        'system_user' => 'coresmith-app-abcd',
        'type' => ApplicationType::Php,
        'php_version' => '8.5.5',
        'app_size' => 'small',
        'base_port' => 8000,
        'web_directory' => '/public',
        'directory' => '/',
    ], $attributes));
}

test('idle php apps cost no memory because the pool is ondemand', function () {
    $pool = (new FpmPoolBuilder(new AsdfManager))->pool(managedApp());

    expect($pool)->toContain('pm = ondemand')
        ->and($pool)->not->toContain('pm = dynamic')
        ->and($pool)->toContain('pm.process_idle_timeout = 10s');
});

test('the fpm pool restarts workers to contain memory leaks', function () {
    expect((new FpmPoolBuilder(new AsdfManager))->pool(managedApp()))->toContain('pm.max_requests = 500');
});

test('worker count follows the app size', function (string $size, int $children) {
    expect((new FpmPoolBuilder(new AsdfManager))->pool(managedApp(['app_size' => $size])))
        ->toContain('pm.max_children = '.$children);
})->with([['small', 5], ['medium', 15], ['large', 30]]);

test('the fpm socket is owned by caddy and not world readable', function () {
    $pool = (new FpmPoolBuilder(new AsdfManager))->pool(managedApp());

    expect($pool)->toContain('listen.owner = caddy')
        ->and($pool)->toContain('listen.group = caddy')
        ->and($pool)->toContain('listen.mode = 0660');
});

test('the pool confines php to the app directory and disables process functions', function () {
    $pool = (new FpmPoolBuilder(new AsdfManager))->pool(managedApp());

    expect($pool)->toContain('open_basedir] = /var/coresmith/apps/my-app:/tmp:/opt/asdf-data/installs')
        ->and($pool)->toContain('disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,dl,putenv');
});

test('the pool runs as the app user, never root', function () {
    $pool = (new FpmPoolBuilder(new AsdfManager))->pool(managedApp());

    expect($pool)->toContain('user = coresmith-app-abcd')
        ->and($pool)->toContain('group = coresmith-app-abcd');
});

test('a unit executes the real binary path with no shell wrapper', function () {
    $app = managedApp(['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0']);
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build($app, 'web', '/opt/asdf-data/installs/nodejs/24.15.0/bin/node server.js');

    expect($unit)->toContain('ExecStart=/opt/asdf-data/installs/nodejs/24.15.0/bin/node server.js')
        ->and($unit)->not->toContain('bash -lc')
        ->and($unit)->not->toContain('/bin/sh -c');
});

test('a unit carries the systemd hardening directives', function (string $directive) {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(), 'web', '/bin/true');

    expect($unit)->toContain($directive);
})->with([
    'NoNewPrivileges=true',
    'ProtectSystem=strict',
    'ProtectHome=tmpfs',
    'PrivateTmp=true',
    'ProtectKernelTunables=true',
    'ProtectKernelModules=true',
    'ProtectControlGroups=true',
]);

test('a unit reads env from the root owned file rather than embedding secrets', function () {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(), 'web', '/bin/true');

    expect($unit)->toContain('EnvironmentFile=/etc/coresmith/env/my-app.env');
});

test('memory limits follow the app size', function (string $size, int $max, int $high) {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(['app_size' => $size]), 'web', '/bin/true');

    expect($unit)->toContain("MemoryMax={$max}M")->and($unit)->toContain("MemoryHigh={$high}M");
})->with([['small', 256, 204], ['medium', 512, 409], ['large', 1024, 819]]);

test('the soft memory limit sits below the hard limit so reclaim happens before an oom kill', function (AppSize $size) {
    expect($size->memoryHighMb())->toBeLessThan($size->memoryMaxMb());
})->with([AppSize::Small, AppSize::Medium, AppSize::Large, AppSize::Custom]);

test('a php app installs an fpm pool and unit', function () {
    $transport = new FakeSSHTransport;

    processManager()->install(processConnection($transport), managedApp());

    $uploads = $transport->uploads();

    expect($uploads)->toHaveKey('/etc/php-fpm.d/coresmith-app-my-app.conf')
        ->and($uploads)->toHaveKey('/etc/systemd/system/coresmith-app-my-app-fpm.service')
        ->and(implode("\n", $transport->commands()))->toContain('systemctl daemon-reload');
});

test('a js app installs a web unit rather than an fpm pool', function () {
    $transport = new FakeSSHTransport;
    $app = managedApp(['type' => ApplicationType::Js, 'js_runtime' => 'bun', 'node_version' => '24.15.0']);

    processManager()->install(processConnection($transport), $app);

    $uploads = $transport->uploads();

    expect($uploads)->toHaveKey('/etc/systemd/system/coresmith-app-my-app-web.service')
        ->and($uploads)->not->toHaveKey('/etc/php-fpm.d/coresmith-app-my-app.conf');
});

test('declared worker processes each get their own unit', function () {
    $transport = new FakeSSHTransport;
    $app = managedApp(['processes' => [['name' => 'worker', 'command' => '/bin/queue-work']]]);

    processManager()->install(processConnection($transport), $app);

    expect($transport->uploads())->toHaveKey('/etc/systemd/system/coresmith-app-my-app-worker.service');
});

test('sleeping stops every unit and records the state', function () {
    $transport = new FakeSSHTransport;
    $app = managedApp();

    processManager()->sleep(processConnection($transport), $app);

    expect(implode("\n", $transport->commands()))->toContain("systemctl stop 'coresmith-app-my-app-fpm'")
        ->and($app->fresh()->power_state)->toBe(PowerState::Sleeping);
});

test('waking starts every unit and records the request time', function () {
    $transport = new FakeSSHTransport;
    $app = managedApp(['power_state' => PowerState::Sleeping]);

    processManager()->wake(processConnection($transport), $app);

    expect(implode("\n", $transport->commands()))->toContain("systemctl enable --now 'coresmith-app-my-app-fpm'")
        ->and($app->fresh()->power_state)->toBe(PowerState::Active)
        ->and($app->fresh()->last_request_at)->not->toBeNull();
});

test('stopping is distinct from sleeping', function () {
    $transport = new FakeSSHTransport;
    $app = managedApp();

    processManager()->stop(processConnection($transport), $app);

    expect($app->fresh()->power_state)->toBe(PowerState::Stopped);
});

test('a static app has no processes to manage', function () {
    $app = managedApp(['type' => ApplicationType::Static, 'php_version' => null]);

    expect(processManager()->unitNames($app))->toBe([]);
});
