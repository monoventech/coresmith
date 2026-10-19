<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Exceptions\UnsafeNameException;
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

function rubyApp(array $attributes = []): Application
{
    return managedApp(['type' => ApplicationType::Ruby, 'framework' => 'rails', 'php_version' => null, 'ruby_version' => '3.3.6', ...$attributes]);
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
        ->toContain('pm.max_children = ' . $children);
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

test('the js web unit tells next.js which host to bind, since it reads HOSTNAME not HOST', function () {
    $app = managedApp(['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0']);
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build($app, 'web', '/opt/asdf-data/installs/nodejs/24.15.0/bin/node server.js');

    expect($unit)->toContain('Environment="HOSTNAME=127.0.0.1"');
});

test('a php web unit gets no HOSTNAME environment line', function () {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(), 'web', '/bin/true');

    expect($unit)->not->toContain('HOSTNAME');
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

test('memory limits follow the app size', function (string $size, int $max) {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(['app_size' => $size]), 'web', '/bin/true');

    expect($unit)->toContain("MemoryMax={$max}M");
})->with([['small', 256], ['medium', 512], ['large', 1024], ['custom', 256]]);

test('a unit over its size is killed and restarted rather than throttled', function (string $process, string $command) {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(), $process, $command);

    expect($unit)->not->toContain('MemoryHigh')
        ->and($unit)->toContain("\nMemoryMax=256M\n")
        ->and($unit)->toContain("\nRestart=always\n");
})->with([
    'web' => ['web', '/bin/true'],
    'worker' => ['queue', 'php artisan queue:work'],
]);

test('no php-fpm unit sets a soft memory limit either', function () {
    expect((new FpmPoolBuilder(new AsdfManager))->unit(managedApp()))->not->toContain('MemoryHigh');
});

test('every unit of a ruby app runs in production unless the app sets its own environment', function (string $process, string $command) {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(rubyApp(), $process, $command);
    $file = strpos($unit, "\nEnvironmentFile=/etc/coresmith/env/my-app.env\n");

    expect($unit)->toContain("Environment=\"RAILS_ENV=production\"\nEnvironment=\"RACK_ENV=production\"\n")
        ->and($file)->not->toBeFalse()
        ->and(strpos($unit, 'Environment="RAILS_ENV=production"'))->toBeLessThan($file)
        ->and(strpos($unit, 'Environment="RACK_ENV=production"'))->toBeLessThan($file);
})->with([
    'web' => ['web', '/opt/asdf-data/installs/ruby/3.3.6/bin/bundle exec puma -b tcp://127.0.0.1:8000'],
    'worker' => ['sidekiq', 'bundle exec sidekiq'],
]);

test('other app types get no rails or rack environment', function (array $attributes, string $process) {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp($attributes), $process, '/bin/true');

    expect($unit)->not->toContain('RAILS_ENV')
        ->and($unit)->not->toContain('RACK_ENV');
})->with([
    'php worker' => [[], 'queue'],
    'js web' => [['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0', 'php_version' => null], 'web'],
]);

test('puma listens on the loopback address only, so the app is reached through caddy', function () {
    expect((new SystemdUnitBuilder(new AsdfManager))->webCommand(rubyApp(['base_port' => 8030])))
        ->toBe('/opt/asdf-data/installs/ruby/3.3.6/bin/bundle exec puma -b tcp://127.0.0.1:8030');
});

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
    $app = managedApp(['type' => ApplicationType::Js, 'js_runtime' => 'bun', 'bun_version' => '1.4.2']);

    processManager()->install(processConnection($transport), $app);

    $uploads = $transport->uploads();

    expect($uploads)->toHaveKey('/etc/systemd/system/coresmith-app-my-app-web.service')
        ->and($uploads)->not->toHaveKey('/etc/php-fpm.d/coresmith-app-my-app.conf');
});

test('a worker command finds its binary through the unit PATH, so it follows the live release', function (string $stored, string $execStart) {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(), 'queue', $stored);

    expect($unit)->toContain("\nExecStart={$execStart}\n");
})->with([
    'a bare command' => ['php artisan horizon', '/usr/bin/env php artisan horizon'],
    'the system php of an older toggle' => ['/usr/bin/php artisan queue:work --sleep=3', '/usr/bin/env php artisan queue:work --sleep=3'],
    'a php binary pinned by an older toggle' => ['/opt/asdf-data/installs/php/8.4.20/bin/php artisan queue:work --tries=3', '/usr/bin/env php artisan queue:work --tries=3'],
    'a bundler pinned by an older toggle' => ['/opt/asdf-data/installs/ruby/3.4.1/bin/bundle exec sidekiq', '/usr/bin/env bundle exec sidekiq'],
    'any other absolute path' => ['/bin/queue-work', '/bin/queue-work'],
    'a php path that is not the system php' => ['/usr/bin/php8.3 artisan queue:work', '/usr/bin/php8.3 artisan queue:work'],
]);

test('a blank worker command is refused, since it would print the unit environment to the log', function (string $command) {
    (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(), 'queue', $command);
})->with([
    'empty' => [''],
    'whitespace only' => ["  \t "],
])->throws(UnsafeNameException::class);

test('the web command keeps the absolute binary it was built with', function () {
    $app = managedApp(['type' => ApplicationType::Js, 'js_runtime' => 'bun', 'bun_version' => '1.4.2']);

    expect((new SystemdUnitBuilder(new AsdfManager))->build($app, 'web', '/opt/asdf-data/installs/bun/1.4.2/bin/bun server.js'))
        ->toContain("\nExecStart=/opt/asdf-data/installs/bun/1.4.2/bin/bun server.js\n");
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

test('a unit can write to its own home and nobody else\'s', function () {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(), 'web', '/bin/true');

    expect($unit)->toContain('ProtectHome=tmpfs')
        ->and($unit)->toContain('BindPaths=-/home/coresmith-app-abcd')
        ->and($unit)->toContain('Environment="HOME=/home/coresmith-app-abcd"');
});

test('a unit for an app in a folder of a monorepo starts in that folder', function () {
    $unit = (new SystemdUnitBuilder(new AsdfManager))->build(managedApp(['directory' => 'apps/web']), 'web', '/bin/true');

    expect($unit)->toContain('WorkingDirectory=/var/coresmith/apps/my-app/current/apps/web');
});

test('php gets the app environment, because the fpm unit reads the file and the pool keeps it', function () {
    $fpm = new FpmPoolBuilder(new AsdfManager);
    $application = managedApp();

    expect($fpm->unit($application))->toContain('EnvironmentFile=/etc/coresmith/env/my-app.env')
        ->and($fpm->pool($application))->toContain("\nclear_env = no\n");
});

test('a restart that fails is left for the start check to explain, instead of stopping at its exit code', function () {
    $transport = (new FakeSSHTransport)->respondTo("systemctl restart 'coresmith-app-my-app-web'", 'Job failed', 1);
    $application = managedApp(['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0']);

    processManager()->refresh(processConnection($transport), $application);

    expect($transport->commands())->toContain("systemctl restart 'coresmith-app-my-app-web'");
});

test('php-fpm logs to the journal, because the runtime folder is read only to its unit', function () {
    $pool = (new FpmPoolBuilder(new AsdfManager))->pool(managedApp());

    expect($pool)->toStartWith("[global]\nerror_log = syslog\nsyslog.ident = coresmith-app-my-app-fpm\n\n[coresmith-app-my-app]\n");
});
