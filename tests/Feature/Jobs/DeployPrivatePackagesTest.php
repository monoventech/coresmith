<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\CheckDomainReach;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\PackageCredential;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeSSHTransport;

beforeEach(fn() => Bus::fake([CheckDomainReach::class]));

const PRIVATE_PACKAGES_RELEASE = '20261114201500';

const PRIVATE_PACKAGES_FOLDER = '/run/coresmith-steps/coresmith-build-pkg-shop-20261114201500';

function privatePackagesApp(array $attributes = []): Application
{
    $application = Application::factory()->create([
        'slug' => 'pkg-shop',
        'domain' => 'shop.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'https://github.com/monoven/example.git',
        'repository_branch' => 'main',
        'run_migrations' => true,
        'base_port' => 8000,
        ...$attributes,
    ]);

    PackageCredential::factory()->for($application->team)->create(['target' => 'nova.laravel.com', 'username' => 'ops@example.com', 'secret' => 'nova-license-key-9f2c']);

    return $application;
}

function privatePackagesDeploy(Deployment $deployment, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, PRIVATE_PACKAGES_RELEASE))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function privatePackagesCommand(FakeSSHTransport $transport, string $needle): ?string
{
    return collect($transport->commands())->first(fn(string $command): bool => str_contains($command, $needle));
}

test('installing and building get the step secrets, migrations never do, and the folder goes when the steps end', function () {
    $deployment = Deployment::factory()->for(privatePackagesApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n", 0);

    privatePackagesDeploy($deployment, $transport);

    $commands = $transport->commands();
    $removal = "rm -rf -- '" . PRIVATE_PACKAGES_FOLDER . "' '" . PRIVATE_PACKAGES_FOLDER . ".env'";
    $lastRemoval = max(array_keys(array_filter($commands, fn(string $command): bool => str_contains($command, $removal))));
    $migrate = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'artisan migrate')));

    expect(privatePackagesCommand($transport, 'composer install'))->toContain("-p 'EnvironmentFile=" . PRIVATE_PACKAGES_FOLDER . ".env'")
        ->and(privatePackagesCommand($transport, 'artisan config:cache'))->toContain("-p 'EnvironmentFile=" . PRIVATE_PACKAGES_FOLDER . ".env'")
        ->and(privatePackagesCommand($transport, 'artisan migrate'))->not->toContain('/run/coresmith-steps')
        ->and($lastRemoval)->toBeLessThan($migrate)
        ->and($deployment->fresh()->log)->not->toContain('nova-license-key');
});

test('the folder is removed when an install fails', function () {
    $deployment = Deployment::factory()->for(privatePackagesApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n", 0)->respondTo('composer install', 'The "https://nova.laravel.com/packages.json" file could not be downloaded (HTTP/2 401 )', 1);

    expect(fn() => privatePackagesDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(privatePackagesCommand($transport, "rm -rf -- '" . PRIVATE_PACKAGES_FOLDER . "'"))->not->toBeNull();
});

test('a custom blueprint runs with the step secrets and has them removed afterwards', function () {
    $deployment = Deployment::factory()->for(privatePackagesApp(['custom_blueprint' => "composer install --no-dev\nphp artisan migrate --force\n"]))->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n", 0);

    privatePackagesDeploy($deployment, $transport);

    $folder = '/run/coresmith-steps/coresmith-blueprint-pkg-shop-20261114201500';

    expect(privatePackagesCommand($transport, '/bin/bash -s <'))->toContain("-p 'EnvironmentFile={$folder}.env'")
        ->and(privatePackagesCommand($transport, "rm -rf -- '{$folder}'"))->not->toBeNull();
});

test('a package secret that a tool prints is redacted from the deploy log', function () {
    $deployment = Deployment::factory()->for(privatePackagesApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n", 0)->respondTo('composer install', "Authenticating to nova.laravel.com with ops@example.com:nova-license-key-9f2c\nInstalled", 0);

    privatePackagesDeploy($deployment, $transport);

    expect($deployment->fresh()->log)->not->toContain('nova-license-key-9f2c')
        ->and($deployment->fresh()->log)->toContain('[REDACTED:nova.laravel.com]');
});
