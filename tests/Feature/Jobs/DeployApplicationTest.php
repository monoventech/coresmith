<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Events\DeploymentSucceeded;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeSSHTransport;

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
    SSHConnectionManager::createTransportUsing(fn (): FakeSSHTransport => $transport);

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

    expect($commands)->toContain('ln -sfn /var/coresmith/apps/my-app/releases/'.TIMESTAMP.' /var/coresmith/apps/my-app/current.tmp')
        ->and($commands)->toContain('mv -Tf /var/coresmith/apps/my-app/current.tmp /var/coresmith/apps/my-app/current')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);

    Event::assertDispatched(DeploymentSucceeded::class);
});

test('a failed migration never reaches the symlink swap', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('artisan migrate', "SQLSTATE[42S01]: duplicate column\n", 1);

    expect(fn () => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('mv -Tf')
        ->and($commands)->not->toContain('current.tmp')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);

    Event::assertDispatched(DeploymentFailed::class);
});

test('a failed deployment removes the half built release', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('artisan migrate', '', 1);

    expect(fn () => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(implode("\n", $transport->commands()))
        ->toContain('rm -rf /var/coresmith/apps/my-app/releases/'.TIMESTAMP);
});

test('a migration failure is written to the deployment log', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport()->respondTo('artisan migrate', "SQLSTATE[42S01]: duplicate column\n", 1);

    expect(fn () => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

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

    expect(fn () => deploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect(implode("\n", $transport->commands()))->not->toContain('git clone')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->log)->toContain('97% full');
});

test('a second deployment while one is running is cancelled, not queued behind it', function () {
    $application = deployableApp();
    Cache::lock('deploy:'.$application->getKey(), 600)->get();

    $deployment = Deployment::factory()->for($application)->create();

    deploy($deployment, healthyDeployTransport());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->toContain('Another deployment is already in progress');
});

test('the lock is released so the next deployment can run', function () {
    $application = deployableApp();
    $deployment = Deployment::factory()->for($application)->create();

    deploy($deployment, healthyDeployTransport());

    expect(Cache::lock('deploy:'.$application->getKey(), 600)->get())->toBeTrue();
});

test('the lock is released even when the deployment fails', function () {
    $application = deployableApp();
    $deployment = Deployment::factory()->for($application)->create();

    expect(fn () => deploy($deployment, healthyDeployTransport()->respondTo('artisan migrate', '', 1)))
        ->toThrow(SSHCommandFailedException::class);

    expect(Cache::lock('deploy:'.$application->getKey(), 600)->get())->toBeTrue();
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
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('bun install --frozen-lockfile')
        ->and($commands)->toContain('bun run build')
        ->and($commands)->toContain('systemctl restart coresmith-app-my-app-web');
});

test('a node app uses npm rather than bun', function () {
    $application = deployableApp(['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('npm ci')->and($commands)->not->toContain('bun install');
});

test('commands run with the resolved runtime binary on PATH', function () {
    $deployment = Deployment::factory()->for(deployableApp())->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))
        ->toContain('PATH=/opt/asdf-data/installs/php/8.5.5/bin:$PATH');
});

test('a php app reloads fpm rather than restarting a web unit', function () {
    $application = deployableApp(['system_user' => 'coresmith-app-abcd']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('systemctl reload php-fpm-coresmith-app-abcd')
        ->and($commands)->not->toContain('systemctl restart coresmith-app-my-app-web');
});

test('a custom blueprint replaces the built in dependency, build and migrate phases', function () {
    $deployment = Deployment::factory()->for(deployableApp([
        'custom_blueprint' => "set -e\necho building\n",
    ]))->create();
    $transport = healthyDeployTransport();

    deploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('bash /var/coresmith/apps/my-app/releases/'.TIMESTAMP.'/.coresmith-blueprint.sh')
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
    $transport = healthyDeployTransport()->respondTo('.coresmith-blueprint.sh', "build failed\n", 1);

    expect(fn () => deploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('current.tmp');
});
