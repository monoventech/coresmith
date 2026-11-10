<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Events\DeploymentFailed;
use App\Events\DeploymentSucceeded;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\ProcessDidNotStart;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\SSHCommandTimedOut;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Process\CrashLoopWatch;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProbeOutput;

const TIMESTAMP = '20260730120000';

function deployableApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'my-app',
        'domain' => 'app.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'https://github.com/monoven/example.git',
        'repository_branch' => 'main',
        'run_migrations' => true,
        'base_port' => 8000,
    ], $attributes));
}

function deploy(Deployment $deployment, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, TIMESTAMP))->handle(
            app(SSHConnectionManager::class),
            new DeploymentCommands(new AsdfManager),
        );
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function healthyDeployTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n", 0);
}

test('a successful deployment swaps the symlink atomically', function () {
    Event::fake([DeploymentSucceeded::class]);
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('ln -sfn /var/coresmith/apps/my-app/releases/' . TIMESTAMP . ' /var/coresmith/apps/my-app/current.tmp')
        ->and($commands)->toContain('mv -Tf /var/coresmith/apps/my-app/current.tmp /var/coresmith/apps/my-app/current')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);

    Event::assertDispatched(DeploymentSucceeded::class);
});

test('a failed migration never reaches the symlink swap', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('artisan migrate', "SQLSTATE[42S01]: duplicate column\n", 1);

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('mv -Tf')
        ->and($commands)->not->toContain('current.tmp')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);

    Event::assertDispatched(DeploymentFailed::class);
});

test('a failed deployment removes the half built release', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('artisan migrate', '', 1);

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(implode("\n", $transport->commands()))
        ->toContain('rm -rf /var/coresmith/apps/my-app/releases/' . TIMESTAMP);
});

test('a migration failure is written to the deployment log', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('artisan migrate', "SQLSTATE[42S01]: duplicate column\n", 1);

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->log)->toContain('SQLSTATE[42S01]');
});

test('migrations are skipped when the app disables them', function () {
    $deployment = Deployment::factory()->for(deployableApp(['run_migrations' => false]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('artisan migrate');
});

test('a critically full disk aborts before anything is built', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "97\n", 0);

    expect(fn() => deploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect(implode("\n", $transport->commands()))->not->toContain('git clone')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->log)->toContain('97% full');
});

test('a second deployment while one is running is cancelled, not queued behind it', function () {
    $application = deployableApp();
    Cache::lock('deploy:' . $application->getKey(), 600)->get();

    $deployment = Deployment::factory()->for($application)->create();

    deploy($deployment, healthyDeployTransport());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->toBe('Another deploy or a rollback is running for this app, so this one did not start.');
});

test('the lock is released so the next deployment can run', function () {
    $application = deployableApp();
    $deployment = Deployment::factory()->for($application)->create();

    deploy($deployment, healthyDeployTransport());

    expect(Cache::lock('deploy:' . $application->getKey(), 600)->get())->toBeTrue();
});

test('the lock is released even when the deployment fails', function () {
    $application = deployableApp();
    $deployment = Deployment::factory()->for($application)->create();

    expect(fn() => deploy($deployment, healthyDeployTransport()->respondTo('artisan migrate', '', 1)))
        ->toThrow(SSHCommandFailedException::class);

    expect(Cache::lock('deploy:' . $application->getKey(), 600)->get())->toBeTrue();
});

test('only the last five releases are kept', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))->toContain('tail -n +6');
});

test('a laravel app caches config, routes and views', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('composer install --no-dev --optimize-autoloader')
        ->and($commands)->toContain('artisan config:cache')
        ->and($commands)->toContain('artisan route:cache')
        ->and($commands)->toContain('artisan view:cache');
});

test('a bun app installs with a frozen lockfile and builds', function () {
    $application = deployableApp(['type' => ApplicationType::Js, 'js_runtime' => 'bun', 'node_version' => '24.15.0']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport()->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['package.json' => '{"scripts":{"build":"vite build"}}'], ['bun.lock']));

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('bun install --frozen-lockfile')
        ->and($commands)->toContain('bun run build')
        ->and($commands)->toContain("systemctl restart 'coresmith-app-my-app-web'");
});

test('a node app uses npm rather than bun', function () {
    $application = deployableApp(['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport()->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['package.json' => '{"scripts":{"build":"next build"}}'], ['package-lock.json']));

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('npm ci')->and($commands)->not->toContain('bun install');
});

test('commands run with the resolved runtime binary on PATH', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['composer.json' => '{"require":{"php":"8.5.5"}}']))->respondTo("ls -1 '/opt/asdf-data/installs/php'", "8.5.5\n");

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))
        ->toContain("-E 'PATH=/opt/asdf-data/installs/php/8.5.5/bin:/usr/local/bin:/usr/bin:/bin'");
});

test('a php app reloads its own fpm unit, not one named after its linux user', function () {
    $deployment = Deployment::factory()->for(deployableApp(['system_user' => 'coresmith-app-abcd']))->create();
    $transport = healthyDeployTransport()->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['composer.json' => '{"require":{"php":"8.5.5"}}']))->respondTo("ls -1 '/opt/asdf-data/installs/php'", "8.5.5\n");

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))->toContain("systemctl reload-or-restart 'coresmith-app-my-app-fpm'")
        ->not->toContain('php-fpm-coresmith-app-abcd')
        ->not->toContain("systemctl restart 'coresmith-app-my-app-web'");
});

test('a process that does not start fails the deploy with the reason and its journal', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()
        ->respondTo("systemctl is-active 'coresmith-app-my-app-fpm'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-my-app-fpm'", 'ERROR: unable to bind listening socket: Address already in use');

    expect(fn() => deploy($deployment, $transport))->toThrow(ProcessDidNotStart::class);

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Failed)
        ->and($fresh->failure_reason)->toContain('coresmith-app-my-app-fpm did not start')
        ->and($fresh->failure_reason)->toContain('Port 8000 is already taken')
        ->and($fresh->log)->toContain('unable to bind listening socket');
});

test('a release whose web process does not start leaves the app failed, as a crash loop does', function (array $attributes, string $unit) {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(deployableApp(['current_release' => '20260101-000000', ...$attributes]))->create();
    $transport = healthyDeployTransport()
        ->respondTo("systemctl is-active '{$unit}'", "failed\n", 3)
        ->respondTo("journalctl -u '{$unit}'", 'ERROR: unable to bind listening socket: Address already in use');

    expect(fn() => deploy($deployment, $transport))->toThrow(ProcessDidNotStart::class);

    $application = $deployment->application->fresh();

    expect($application->status)->toBe(ApplicationStatus::Failed)
        ->and($application->current_release)->toBe(TIMESTAMP)
        ->and($deployment->fresh()->failure_reason)->toStartWith("{$unit} did not start")
        ->and($deployment->fresh()->start_failed)->toBeTrue()
        ->and($deployment->fresh()->failed_worker)->toBeNull()
        ->and($deployment->fresh()->log)->toContain('deploy failed after the symlink swap');
})->with([
    'php-fpm' => [[], 'coresmith-app-my-app-fpm'],
    'a web unit' => [['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0'], 'coresmith-app-my-app-web'],
]);

test('a worker that does not start fails the deploy with its own reason, but the app stays live because the site serves', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(deployableApp([
        'current_release' => '20260101-000000',
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
    ]))->create();
    $transport = healthyDeployTransport()
        ->respondTo("systemctl is-active 'coresmith-app-my-app-queue'", "activating\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-my-app-queue'", "coresmith-app-my-app-queue.service: Main process exited, code=exited, status=1/FAILURE\n")
        ->respondTo("tail -n 200 '/var/coresmith/logs/my-app/queue.log'", "Could not open input file: artisan\nNo such file or directory\n");

    expect(fn() => deploy($deployment, $transport))->toThrow(ProcessDidNotStart::class);

    $fresh = $deployment->fresh();
    $application = $deployment->application->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Failed)
        ->and($fresh->failure_reason)->toBe('coresmith-app-my-app-queue did not start, systemd reports it as activating. The entry point was not found. Check the build produced the file the process starts, then deploy again.')
        ->and($fresh->log)->toContain('Could not open input file: artisan')
        ->and($fresh->start_failed)->toBeTrue()
        ->and($fresh->failed_worker)->toBe('Queue worker')
        ->and($application->status)->toBe(ApplicationStatus::Active)
        ->and($application->current_release)->toBe(TIMESTAMP);
});

test('the processes are started under the watch marker, after the swap and before traffic is routed', function () {
    $deployment = Deployment::factory()->for(deployableApp(['processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]))->create();

    deploy($deployment, healthyDeployTransport());

    $log = (string) $deployment->fresh()->log;
    $swap = strpos($log, 'symlink: current → releases/' . TIMESTAMP);
    $starting = strpos($log, ' watch: starting coresmith-app-my-app-fpm, coresmith-app-my-app-queue');
    $route = strpos($log, 'caddy: route for app.example.com is in place');

    expect($log)->toMatch('/^\[\d{2}:\d{2}:\d{2}\] watch: starting coresmith-app-my-app-fpm, coresmith-app-my-app-queue$/m')
        ->and($swap)->toBeLessThan($starting)
        ->and($starting)->toBeLessThan($route)
        ->and($deployment->fresh()->start_failed)->toBeFalse();
});

test('a release whose processes do not start is recorded as a start failure, with every phase through the swap done', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()
        ->respondTo("systemctl is-active 'coresmith-app-my-app-fpm'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-my-app-fpm'", 'ERROR: unable to bind listening socket: Address already in use');

    expect(fn() => deploy($deployment, $transport))->toThrow(ProcessDidNotStart::class);

    $fresh = $deployment->fresh();

    expect($fresh->start_failed)->toBeTrue()
        ->and(array_unique(array_column(app(DeploymentPhases::class)->states($fresh), 'state')))->toBe(['done']);
});

test('a stopped app gets the new release without its processes being started', function () {
    $deployment = Deployment::factory()->for(deployableApp(['power_state' => PowerState::Stopped]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))->toContain('mv -Tf')
        ->not->toContain('systemctl reload-or-restart')
        ->not->toContain('systemctl restart')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->toContain('starts when the app does');
});

test('a custom blueprint replaces the built in dependency, build and migrate phases', function () {
    $deployment = Deployment::factory()->for(deployableApp([
        'custom_blueprint' => "set -e\necho building\n",
    ]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("-- /bin/bash -s < '/etc/coresmith/blueprints/my-app.sh'")
        ->and($transport->uploads()['/etc/coresmith/blueprints/my-app.sh'])->toBe("set -e\necho building\n")
        ->and($commands)->toContain("rm -f '/etc/coresmith/blueprints/my-app.sh'")
        ->and($commands)->not->toContain('composer install')
        ->and($commands)->not->toContain('artisan config:cache')
        ->and($commands)->not->toContain('artisan migrate');
});

test('a custom blueprint still gets the atomic symlink swap', function () {
    $deployment = Deployment::factory()->for(deployableApp(['custom_blueprint' => "echo hi\n"]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))->toContain('mv -Tf');
});

test('a failing custom blueprint never reaches the symlink swap', function () {
    $deployment = Deployment::factory()->for(deployableApp(['custom_blueprint' => "exit 1\n"]))->create();
    $transport = healthyDeployTransport()->respondTo("/bin/bash -s < '/etc/coresmith/blueprints/my-app.sh'", "build failed\n", 1);

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('current.tmp');
});

test('a private repository is cloned with its own deploy key', function () {
    $deployment = Deployment::factory()->for(deployableApp([
        'repository_url' => 'git@github.com:monoven/example.git',
    ]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $clone = collect($transport->commands())->first(fn(string $c) => str_contains($c, 'git clone --bare'));

    expect($clone)->toContain('GIT_SSH_COMMAND=')
        ->and($clone)->toContain('/etc/coresmith/deploy-keys/my-app')
        ->and($clone)->toContain('IdentitiesOnly=yes');
});

test('the deploy key is uploaded to the server and then removed', function () {
    $deployment = Deployment::factory()->for(deployableApp([
        'repository_url' => 'git@github.com:monoven/example.git',
    ]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($transport->uploads())->toHaveKey('/etc/coresmith/deploy-keys/my-app')
        ->and($transport->uploads()['/etc/coresmith/deploy-keys/my-app'])->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($commands)->toContain("install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app'")
        ->and($commands)->toContain("rm -f '/etc/coresmith/deploy-keys/my-app'");
});

test('the key file exists with tight permissions before its contents are written', function () {
    $deployment = Deployment::factory()->for(deployableApp([
        'repository_url' => 'git@github.com:monoven/example.git',
    ]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = $transport->commands();
    $reserve = array_search("install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app'", $commands, true);
    $harden = array_search("mkdir -p '/etc/coresmith/deploy-keys' && chown root:root '/etc/coresmith/deploy-keys' && chmod 700 '/etc/coresmith/deploy-keys'", $commands, true);

    expect($harden)->not->toBeFalse()
        ->and($reserve)->not->toBeFalse()
        ->and($harden)->toBeLessThan($reserve);
});

test('the deploy key does not outlive a failed clone', function () {
    $deployment = Deployment::factory()->for(deployableApp([
        'repository_url' => 'git@github.com:monoven/example.git',
    ]))->create();
    $transport = healthyDeployTransport()->respondTo('git clone --bare', 'Permission denied (publickey).', 128);

    expect(fn() => deploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect(implode("\n", $transport->commands()))->toContain("rm -f '/etc/coresmith/deploy-keys/my-app'")
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
});

test('a public repository is cloned without ever writing a key to the server', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('GIT_SSH_COMMAND')
        ->and($commands)->not->toContain('/etc/coresmith/deploy-keys')
        ->and(array_keys($transport->uploads()))->not->toContain('/etc/coresmith/deploy-keys/my-app');
});

test('a deploy puts the route in place so the domain reaches the new release', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo("-X GET 'http://localhost:2019/id/", 'curl: (22) The requested URL returned error: 404', 22);

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))->toContain("-X PUT 'http://localhost:2019/config/apps/http/servers/coresmith/routes/0'")
        ->and($deployment->fresh()->log)->toContain('caddy: route for app.example.com is in place');
});

test('an app with no domain is deployed without touching caddy', function () {
    $deployment = Deployment::factory()->for(deployableApp(['domain' => null, 'additional_domains' => null]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('localhost:2019/id/');
});

test('the private deploy key never reaches the deployment log', function () {
    $application = deployableApp(['repository_url' => 'git@github.com:monoven/example.git']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport()->respondTo('composer install', 'failed', 1);

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect((string) $deployment->fresh()->log)->not->toContain('BEGIN OPENSSH PRIVATE KEY');
});

test('a repository that does not exist fails at the clone with what to check', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('git clone --bare', "remote: Repository not found.\nfatal: repository 'https://github.com/monoven/example.git/' not found", 128);

    expect(fn() => deploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    $fresh = $deployment->fresh();

    expect($fresh->failure_reason)->toBe('Repository not found at https://github.com/monoven/example.git. Check the URL is right and the repository still exists, then deploy again.')
        ->and($fresh->log)->toContain('remote: Repository not found.')
        ->and(count(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'git clone --bare'))))->toBe(1);
});

test('a clone that times out is tried again and the deploy carries on', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondToSequence('git clone --bare', [
        ['fatal: unable to access: Failed to connect to github.com port 443: Connection timed out', 128],
        ['', 0],
    ]);

    deploy($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->toContain('clone failed (attempt 1 of 3), retrying in 5 seconds');

    Sleep::assertSlept(fn($duration): bool => (int) $duration->totalSeconds === DeployApplication::RETRY_SECONDS);
});

test('a failed clone is removed before it is retried, so the retry actually re-clones', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondToSequence('git clone --bare', [
        ['fatal: unable to access: Failed to connect to github.com port 443: Connection timed out', 128],
        ['', 0],
    ]);

    deploy($deployment, $transport);

    $commands = $transport->commands();
    $firstClone = array_search(true, array_map(fn(string $c): bool => str_contains($c, 'git clone --bare'), $commands), true);
    $cleanup = array_search('rm -rf /var/coresmith/apps/my-app/repo', $commands, true);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($cleanup)->not->toBeFalse()
        ->and($cleanup)->toBeGreaterThan($firstClone);
});

test('a clone that keeps timing out stops after three attempts with the network advice', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('git clone --bare', 'Failed to connect to github.com port 443: Connection timed out', 128);

    expect(fn() => deploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect(count(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'git clone --bare'))))->toBe(3)
        ->and($deployment->fresh()->failure_reason)->toContain('The server could not reach github.com.');
});

test('a branch that is not in the repository is named', function () {
    $deployment = Deployment::factory()->for(deployableApp(['repository_branch' => 'release/2.0']))->create();
    $transport = healthyDeployTransport()->respondTo('archive', 'fatal: not a valid object name: release/2.0', 128);

    expect(fn() => deploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->failure_reason)->toContain('Branch release/2.0 is not in the repository.');
});

test('dependencies that hit a registry timeout are installed once more', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondToSequence('composer install', [
        ['curl error 28 while downloading https://repo.packagist.org/p2/laravel/framework.json: Operation timed out', 1],
        ['Generating optimized autoload files', 0],
    ]);

    deploy($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->toContain('deps hit a network error (attempt 1 of 2)');
});

test('a build error is not retried', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('artisan config:cache', 'In Repository.php line 12: syntax error', 1);

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(count(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'artisan config:cache'))))->toBe(1);
});

test('an ssh connection that drops before the swap is reopened and the phase runs once more', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->dropConnectionOn('artisan view:cache');

    deploy($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->toContain('the SSH connection dropped during build, reconnecting');
});

test('a release that crash loops after the swap fails the deploy and says to roll back', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(deployableApp(['current_release' => '20260101-000000']))->create();
    $transport = healthyDeployTransport()
        ->respondToSequence("systemctl is-active 'coresmith-app-my-app-fpm'", [["active\n", 0], ["failed\n", 3]])
        ->respondTo("journalctl -u 'coresmith-app-my-app-fpm'", "PHP Fatal error: Uncaught Error: Class \"App\\Billing\\Gateway\" not found\n");

    expect(fn() => deploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Failed)
        ->and($fresh->failure_reason)->toContain('Crash loop: coresmith-app-my-app-fpm kept stopping')
        ->and($fresh->failure_reason)->toContain('roll back')
        ->and($fresh->log)->toContain('Class "App\\Billing\\Gateway" not found')
        ->and($fresh->log)->toContain('deploy failed after the symlink swap')
        ->and($fresh->start_failed)->toBeFalse()
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Failed);
});

test('a healthy release is watched for a minute before it counts as deployed', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();

    deploy($deployment, healthyDeployTransport());

    expect($deployment->fresh()->log)->toContain('watch: every process stayed up');

    Sleep::assertSlept(fn($duration): bool => (int) $duration->totalSeconds === CrashLoopWatch::INTERVAL_SECONDS, CrashLoopWatch::CHECKS);
});

test('a static site is never told it was watched, because it has nothing to watch', function () {
    $deployment = Deployment::factory()->for(deployableApp([
        'type' => ApplicationType::Static,
        'php_version' => null,
        'processes' => null,
    ]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->not->toContain('watch:')
        ->and(implode("\n", $transport->commands()))->not->toContain('is-active');
});

test('a first deploy moves off a port block another process already holds', function () {
    $application = deployableApp(['current_release' => null, 'base_port' => 8000, 'type' => ApplicationType::Js, 'framework' => 'sveltekit', 'php_version' => null, 'node_version' => '22.1.0', 'js_runtime' => 'node']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport()->respondTo('ss -tlnpH', 'LISTEN 0 511 0.0.0.0:8000 0.0.0.0:* users:(("java",pid=1,fd=3))');

    deploy($deployment, $transport);

    expect($application->fresh()->base_port)->toBe(8010)
        ->and($deployment->fresh()->log)->toContain('ports: java already listens on 8000, moved to 8010–8019');
});

test('a first deploy whose port scan produced nothing says the scan could not be read', function () {
    $application = deployableApp(['current_release' => null, 'base_port' => 8000, 'type' => ApplicationType::Js, 'framework' => 'sveltekit', 'php_version' => null, 'node_version' => '22.1.0', 'js_runtime' => 'node']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport()->respondTo('ss -tlnpH', '');

    deploy($deployment, $transport);

    expect($application->fresh()->base_port)->toBe(8000)
        ->and($deployment->fresh()->log)->toContain('ports: could not read the listening ports, going ahead');
});

test('a later deploy never scans or moves the port block', function () {
    $application = deployableApp(['current_release' => '20260101-000000']);
    $transport = healthyDeployTransport();

    deploy(Deployment::factory()->for($application)->create(), $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('ss -tlnpH');
});

test('a dependency install that hangs fails the deploy, names the command and removes the half built release', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->timeOutOn('composer install');

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandTimedOut::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toStartWith('`composer install --no-dev --optimize-autoloader --no-interaction` ran for 10 minutes without finishing')
        ->and(implode("\n", $transport->commands()))->toContain('rm -rf /var/coresmith/apps/my-app/releases/' . TIMESTAMP);
});

test('the app gets its own linux account and a root controlled folder before anything is cloned', function () {
    $deployment = Deployment::factory()->for(deployableApp(['system_user' => 'coresmith-app-my-app']))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = $transport->commands();
    $account = collect($commands)->search(fn(string $c) => str_contains($c, 'useradd --system'));
    $clone = collect($commands)->search(fn(string $c) => str_contains($c, 'git clone --bare'));

    expect($account)->not->toBeFalse()
        ->and($account)->toBeLessThan($clone)
        ->and($commands[$account])->toContain('--shell /usr/sbin/nologin')
        ->and($commands[$account])->toContain('chown root:root /var/coresmith/apps/my-app /var/coresmith/apps/my-app/releases')
        ->and($commands[$account])->toContain('chown -h coresmith-app-my-app:caddy /var/coresmith/apps/my-app/shared');
});

test('the environment file is written root only before anything runs as the app', function () {
    $deployment = Deployment::factory()->for(deployableApp(['env_variables' => "APP_ENV=production\nAPP_NAME=\"Ada's \$hop\"\nMAIL_FROM_NAME=\"\${APP_NAME}\""]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = $transport->commands();
    $reserve = array_search("mkdir -p '/etc/coresmith/env' && chmod 700 '/etc/coresmith/env' && install -m 600 -o root -g root /dev/null '/etc/coresmith/env/my-app.env'", $commands, true);
    $firstAsApp = collect($commands)->search(fn(string $c) => str_starts_with($c, 'systemd-run '));

    expect($reserve)->not->toBeFalse()
        ->and($reserve)->toBeLessThan($firstAsApp)
        ->and($transport->uploads()['/etc/coresmith/env/my-app.env'])->toBe("APP_ENV=\"production\"\nAPP_NAME=\"Ada's \\\$hop\"\nMAIL_FROM_NAME=\"Ada's \\\$hop\"\n")
        ->and($commands)->toContain("chown root:root '/etc/coresmith/env/my-app.env' && chmod 600 '/etc/coresmith/env/my-app.env'");
});

test('dependencies, builds and migrations run as the app with its environment, never as root', function () {
    $deployment = Deployment::factory()->for(deployableApp(['system_user' => 'coresmith-app-my-app']))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    foreach (['composer install', 'artisan config:cache', 'artisan migrate'] as $step) {
        $command = collect($transport->commands())->first(fn(string $c) => str_contains($c, $step));

        expect($command)->toStartWith('systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-my-app --gid=coresmith-app-my-app ')
            ->and($command)->toContain("-p 'EnvironmentFile=/etc/coresmith/env/my-app.env'")
            ->and($command)->toContain("-p 'WorkingDirectory=/var/coresmith/apps/my-app/releases/" . TIMESTAMP . "'");
    }
});

test('the release is handed to the app before anything runs in it', function () {
    $deployment = Deployment::factory()->for(deployableApp(['system_user' => 'coresmith-app-my-app']))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = $transport->commands();
    $claim = array_search((new AppAccount)->claimRelease($deployment->application, '/var/coresmith/apps/my-app/releases/' . TIMESTAMP), $commands, true);
    $archive = collect($commands)->search(fn(string $c) => str_contains($c, ' archive '));
    $firstAsApp = collect($commands)->search(fn(string $c) => str_starts_with($c, 'systemd-run '));

    expect($claim)->not->toBeFalse()
        ->and($archive)->toBeLessThan($claim)
        ->and($claim)->toBeLessThan($firstAsApp);
});

test('a laravel app keeps its storage folder across releases, a node app does not get one made for it', function (ApplicationType $type, bool $seeded) {
    $deployment = Deployment::factory()->for(deployableApp(['type' => $type, 'js_runtime' => 'node', 'node_version' => '24.15.0']))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $shared = collect($transport->commands())->first(fn(string $c) => str_contains($c, 'shared/logs'));

    expect($shared)->toStartWith('systemd-run ')
        ->and(str_contains($shared, 'cp -a'))->toBe($seeded)
        ->and($shared)->toContain('ln -sfn');
})->with([
    'php' => [ApplicationType::Php, true],
    'js' => [ApplicationType::Js, false],
]);

test('an app in a folder of a monorepo is probed, built and started from that folder', function () {
    $application = deployableApp(['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0', 'directory' => 'apps/web']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport()->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['package.json' => '{"scripts":{"build":"vite build","start":"node server.js"}}'], ['package-lock.json']));

    deploy($deployment, $transport);

    $install = collect($transport->commands())->first(fn(string $c) => str_contains($c, 'npm ci'));

    expect($install)->toContain("-p 'WorkingDirectory=/var/coresmith/apps/my-app/releases/" . TIMESTAMP . "/apps/web'")
        ->and($transport->uploads()['/etc/systemd/system/coresmith-app-my-app-web.service'])->toContain('WorkingDirectory=/var/coresmith/apps/my-app/current/apps/web');
});

test('a process that fails to restart is reported with its journal and advice, not a bare exit code', function () {
    $application = deployableApp(['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport()
        ->respondTo("systemctl restart 'coresmith-app-my-app-web'", "Job for coresmith-app-my-app-web.service failed.\n", 1)
        ->respondTo("systemctl is-active 'coresmith-app-my-app-web'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-my-app-web'", 'coresmith-app-my-app-web.service: Failed to load environment files: No such file or directory');

    expect(fn() => deploy($deployment, $transport))->toThrow(ProcessDidNotStart::class);

    expect($deployment->fresh()->failure_reason)->toContain("The app's environment file is missing from the server. Deploy again to write it.")
        ->and($deployment->fresh()->log)->toContain('Failed to load environment files');
});

test('a server command that fails puts what the server said in the log', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('chmod 700 /var/coresmith/apps/my-app/repo', "chmod: cannot access '/var/coresmith/apps/my-app/repo': No such file or directory\n", 1);

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(substr_count((string) $deployment->fresh()->log, "chmod: cannot access '/var/coresmith/apps/my-app/repo'"))->toBe(1);
});

test('output already in the log is not written twice when its command fails', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('artisan migrate', "SQLSTATE[42S01]: duplicate column\n", 1);

    expect(fn() => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(substr_count((string) $deployment->fresh()->log, 'SQLSTATE[42S01]'))->toBe(1);
});

test('a redeploy fetches every branch head into the cached repository', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $fetch = collect($transport->commands())->first(fn(string $c) => str_contains($c, ' fetch '));

    expect($fetch)->toContain("fetch --prune --tags origin '+refs/heads/*:refs/heads/*'")
        ->and(implode("\n", $transport->commands()))->not->toContain('fetch --all');
});

test('the cached repository is pointed at the current url before it fetches', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = $transport->commands();
    $setUrl = array_search("git --git-dir=/var/coresmith/apps/my-app/repo remote set-url origin -- 'https://github.com/monoven/example.git'", $commands, true);
    $fetch = collect($commands)->search(fn(string $c) => str_contains($c, ' fetch '));

    expect($setUrl)->not->toBeFalse()
        ->and($setUrl)->toBeLessThan($fetch);
});

test('a private repository still fetches through its deploy key', function () {
    $deployment = Deployment::factory()->for(deployableApp(['repository_url' => 'git@github.com:monoven/example.git']))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $fetch = collect($transport->commands())->first(fn(string $c) => str_contains($c, ' fetch '));

    expect($fetch)->toContain('GIT_SSH_COMMAND=')
        ->and($fetch)->toContain('fetch --prune --tags origin');
});

test('a sudo user deploys with every step run as root and uploads staged first', function () {
    $server = Server::factory()->create(['ssh_user' => 'deploy']);
    $deployment = Deployment::factory()->for(deployableApp(['server_id' => $server->id, 'system_user' => 'coresmith-app-my-app']))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $unprivileged = collect($transport->commands())->reject(fn(string $c) => str_starts_with($c, 'sudo ')
        || str_starts_with($c, 'df /')
        || str_starts_with($c, 'mktemp ')
        || str_starts_with($c, "rm -f '/tmp/coresmith-upload.")
        || str_starts_with($c, 'systemctl is-active ')
        || str_starts_with($c, 'systemctl show ')
        || str_starts_with($c, 'curl ')
        || str_starts_with($c, "test -x '/opt/asdf-data/")
        || str_starts_with($c, "ls -1 '/opt/asdf-data/"));

    expect($unprivileged->values()->all())->toBe([])
        ->and(array_keys($transport->uploads()))->toBe([FakeSSHTransport::STAGED_UPLOAD]);
});
