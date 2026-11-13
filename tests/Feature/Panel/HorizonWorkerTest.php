<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Jobs\DeployApplication;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProbeOutput;

function horizonStack(bool $horizon): array
{
    return ['runtimes' => [], 'framework' => ['name' => 'laravel', 'major' => 13], 'tooling' => null, 'gems' => [], 'packages' => ['horizon' => $horizon, 'reverb' => false, 'inertia' => false]];
}

function horizonApp(bool $horizon, array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => null,
        'repository_url' => 'https://github.com/monoven/shop.git',
        'run_migrations' => false,
        'base_port' => 8000,
        'stack' => horizonStack($horizon),
        ...$attributes,
    ]);
}

function horizonTransport(bool $horizon): FakeSSHTransport
{
    $require = ['php' => '^8.4', 'laravel/framework' => '^13.0', ...($horizon ? ['laravel/horizon' => '^5.30'] : [])];

    return (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['composer.json' => (string) json_encode(['require' => $require])]));
}

function horizonWorker(Application $application): ?string
{
    return collect((array) $application->fresh()->processes)->firstWhere('name', 'queue')['command'] ?? null;
}

function horizonOver(FakeSSHTransport $transport, Closure $work): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        $work();
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('turning the worker on runs horizon when the repository requires it', function (bool $horizon, string $command, string $description, string $busy) {
    Queue::fake();
    $application = horizonApp($horizon);

    app(SmartToggles::class)->apply($application, SmartToggles::QUEUE, true);

    $row = collect(app(SmartToggles::class)->describe($application->fresh()))->firstWhere('key', SmartToggles::QUEUE);

    expect(horizonWorker($application))->toBe($command)
        ->and($row['description'])->toBe($description)
        ->and($row['busy_on'])->toBe($busy);
})->with([
    'horizon' => [true, 'php artisan horizon', 'Detected: laravel/horizon', 'Starting Horizon…'],
    'plain queue' => [false, 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'php artisan queue:work', 'Starting the worker…'],
]);

test('the worker command follows what the repository installs', function () {
    expect(app(SmartToggles::class)->queueCommand(horizonApp(true)))->toBe(SmartToggles::HORIZON_COMMAND)
        ->and(app(SmartToggles::class)->queueCommand(horizonApp(false, ['slug' => 'blog'])))->toBe(SmartToggles::QUEUE_COMMAND)
        ->and(app(SmartToggles::class)->packages(horizonApp(false, ['slug' => 'old', 'stack' => null])))->toBe([]);
});

test('a deploy moves an existing worker to horizon and back', function (bool $horizon, string $command) {
    Queue::fake();
    $application = horizonApp(!$horizon, ['processes' => [['name' => 'queue', 'command' => $horizon ? SmartToggles::QUEUE_COMMAND : SmartToggles::HORIZON_COMMAND]]]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = horizonTransport($horizon);

    horizonOver($transport, fn() => (new DeployApplication($deployment, '20261020120000'))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class)));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($transport->uploads()['/etc/systemd/system/coresmith-app-shop-queue.service'] ?? '')->toContain("\nExecStart=/usr/bin/env {$command}\n")
        ->and(horizonWorker($application))->toBe($command)
        ->and($application->fresh()->stack['packages']['horizon'])->toBe($horizon);
})->with([
    'to horizon' => [true, 'php artisan horizon'],
    'back to queue:work' => [false, 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600'],
]);

test('a deploy leaves an app without a worker without one', function () {
    Queue::fake();
    $application = horizonApp(false);
    $deployment = Deployment::factory()->for($application)->create();

    horizonOver(horizonTransport(true), fn() => (new DeployApplication($deployment, '20261020120000'))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class)));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(horizonWorker($application))->toBeNull();
});

test('a rollback runs the worker its release had', function () {
    $application = horizonApp(true, ['current_release' => '20260913-120000', 'processes' => [['name' => 'queue', 'command' => SmartToggles::HORIZON_COMMAND]]]);
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded, 'stack' => horizonStack(false)]);
    $transport = (new FakeSSHTransport)->respondTo('ls -1dt', "/var/coresmith/apps/shop/releases/20260913-120000/\n/var/coresmith/apps/shop/releases/20260912-090000/\n");

    horizonOver($transport, fn() => (new RollbackApplication($application, '20260912-090000'))->handle(app(SSHConnectionManager::class)));

    expect($application->fresh()->current_release)->toBe('20260912-090000')
        ->and(horizonWorker($application))->toBe(SmartToggles::QUEUE_COMMAND)
        ->and($transport->uploads()['/etc/systemd/system/coresmith-app-shop-queue.service'] ?? '')->toContain("\nExecStart=/usr/bin/env php artisan queue:work --sleep=3 --tries=3 --max-time=3600\n");
});
