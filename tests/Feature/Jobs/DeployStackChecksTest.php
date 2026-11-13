<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSSHTransport;

function stackDeployApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'my-app',
        'domain' => 'app.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'https://github.com/monoven/example.git',
        'repository_branch' => 'main',
        'run_migrations' => true,
        'base_port' => 8000,
        'env_variables' => '',
        ...$attributes,
    ]);
}

function stackDeployProbe(string $fixture, string $extra = ''): string
{
    $output = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => base_path('tests/Fixtures/repositories/' . $fixture)])->input(app(RepositoryProbe::class)->script())->run('bash -s')->output();

    return $extra . $output;
}

function stackDeployTransport(string $probe): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n")->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, $probe);
}

function stackDeployRun(Deployment $deployment, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261113120000'))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    } catch (Throwable) {
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('a deploy stops in seconds when the stack cannot run the repository, before installing anything', function () {
    $deployment = Deployment::factory()->for(stackDeployApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'node', 'php_version' => null, 'node_version' => '24.21.0']))->create();
    $transport = stackDeployTransport(stackDeployProbe('sveltekit-auto'));

    stackDeployRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toContain('svelte.config.js uses @sveltejs/adapter-auto')
        ->and($deployment->fresh()->failure_reason)->toContain('Install @sveltejs/adapter-node')
        ->and(implode("\n", $transport->commands()))->not->toContain('npm ci');
});

test('an app whose probe did not finish deploys exactly as before', function () {
    $deployment = Deployment::factory()->for(stackDeployApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");

    stackDeployRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(implode("\n", $transport->commands()))->toContain('composer install')
        ->and((string) $deployment->application->fresh()->env_variables)->toBe('');
});

test('a JavaScript app that starts server.js is not refused for having no start script', function () {
    $probe = '@@file package.json ' . base64_encode('{"dependencies":{"express":"^5.1.0"}}') . "\n@@lock package-lock.json\n@@has server.js\n@@done\n";
    $deployment = Deployment::factory()->for(stackDeployApp(['type' => ApplicationType::Js, 'framework' => null, 'js_runtime' => 'node', 'php_version' => null, 'node_version' => '24.21.0']))->create();

    stackDeployRun($deployment, stackDeployTransport($probe));

    expect((string) $deployment->fresh()->failure_reason)->not->toContain('start script');
});

test('a missing APP_KEY is generated once, saved in the environment and kept', function () {
    $application = stackDeployApp();
    $first = Deployment::factory()->for($application)->create();

    stackDeployRun($first, stackDeployTransport(stackDeployProbe('laravel-13')));

    $key = app(EnvironmentFile::class)->parse((string) $application->fresh()->env_variables)['APP_KEY'] ?? null;

    expect($key)->toMatch('~^base64:[A-Za-z0-9+/]{43}=$~')
        ->and($first->fresh()->log)->toContain("APP_KEY wasn't set, so Coresmith generated one and saved it in the app's environment.");

    stackDeployRun(Deployment::factory()->for($application)->create(), stackDeployTransport(stackDeployProbe('laravel-13')));

    expect(app(EnvironmentFile::class)->parse((string) $application->fresh()->env_variables)['APP_KEY'] ?? null)->toBe($key);
});

test('an APP_KEY the committed .env sets is left alone', function () {
    $application = stackDeployApp();

    stackDeployRun(Deployment::factory()->for($application)->create(), stackDeployTransport(stackDeployProbe('laravel-13', "@@envkey APP_KEY\n")));

    expect((string) $application->fresh()->env_variables)->toBe('');
});

test('an empty APP_KEY line is filled where it stands', function () {
    $application = stackDeployApp(['env_variables' => "APP_NAME=Shop\nAPP_KEY=\nMAIL_FROM=shop@example.com\n"]);

    stackDeployRun(Deployment::factory()->for($application)->create(), stackDeployTransport(stackDeployProbe('laravel-13')));

    $env = (string) $application->fresh()->env_variables;

    expect(substr_count($env, 'APP_KEY='))->toBe(1)
        ->and(app(EnvironmentFile::class)->parse($env))->toMatchArray(['APP_NAME' => 'Shop', 'MAIL_FROM' => 'shop@example.com'])
        ->and(app(EnvironmentFile::class)->parse($env)['APP_KEY'])->toStartWith('base64:');
});

test('the app\'s answer once live is recorded and logged, with the newest error behind a 500', function () {
    $deployment = Deployment::factory()->for(stackDeployApp())->create();
    $transport = stackDeployTransport(stackDeployProbe('laravel-13', "@@envkey APP_KEY\n"))
        ->respondTo('curl -sk', '500')
        ->respondTo('storage/logs', '[2026-11-13 12:00:01] production.ERROR: No application encryption key has been specified. {"exception":"[object] (Illuminate\\\\Encryption\\\\MissingAppKeyException(code: 0)"}');

    stackDeployRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->live_status)->toBe(500)
        ->and($deployment->fresh()->live_detail)->toBe('No application encryption key has been specified.')
        ->and($deployment->fresh()->log)->toContain('check: https://app.example.com/ answered 500');
});

test('an app Coresmith could not ask records nothing, and the deploy still succeeds', function () {
    $deployment = Deployment::factory()->for(stackDeployApp())->create();

    stackDeployRun($deployment, stackDeployTransport(stackDeployProbe('laravel-13', "@@envkey APP_KEY\n"))->respondTo('curl -sk', '000', 35));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->live_status)->toBeNull();
});

test('a Laravel app installs its dependencies with the starter kit setup deferred, and the log shows the plain command', function () {
    $deployment = Deployment::factory()->for(stackDeployApp())->create();
    $transport = stackDeployTransport(stackDeployProbe('laravel-13', "@@envkey APP_KEY\n"));

    stackDeployRun($deployment, $transport);

    $install = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'composer install'));

    expect($install)->toContain("-E 'LARAVEL_INSTALLER_DEFER_HOOKS=1'")
        ->and($deployment->fresh()->log)->toContain('$ composer install --no-dev')
        ->and($deployment->fresh()->log)->not->toContain('LARAVEL_INSTALLER_DEFER_HOOKS');
});
