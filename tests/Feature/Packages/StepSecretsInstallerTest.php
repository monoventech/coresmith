<?php

declare(strict_types=1);

use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Models\PackageCredential;
use App\Models\Server;
use App\Services\Packages\StepSecretsInstaller;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

const STEP_UNIT = 'coresmith-build-shop-20261114-201500';

function stepInstallerApp(array $attributes = []): Application
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'ssh_user' => 'coresmith']);

    return Application::factory()->for($server)->for($server->team)->create(['slug' => 'shop', 'system_user' => 'cs-shop', ...$attributes]);
}

function stepInstallerPayload(FakeSSHTransport $transport): array
{
    $files = [];

    foreach (preg_split('/\n/', trim((string) $transport->inputFor('/run/coresmith-steps'))) as $line) {
        [$name, $data] = explode(' ', $line, 2);
        $files[$name] = base64_decode($data, true);
    }

    return $files;
}

test('an app without package credentials gets no step secrets and no commands', function () {
    $transport = new FakeSSHTransport;
    $application = stepInstallerApp();

    $properties = app(StepSecretsInstaller::class)->prepare((new SSHConnection($application->server, $transport))->connect(), $application, STEP_UNIT, '/var/www/shop/releases/20261114-201500');

    expect($properties)->toBe([])->and($transport->commands())->toBe([]);
});

test('composer credentials reach the step through a root-only environment file sent over stdin', function () {
    $transport = new FakeSSHTransport;
    $application = stepInstallerApp();
    PackageCredential::factory()->for($application->team)->create(['target' => 'nova.laravel.com', 'username' => 'ops@example.com', 'secret' => 'nova-license-key-9f2c']);

    $properties = app(StepSecretsInstaller::class)->prepare((new SSHConnection($application->server, $transport))->connect(), $application, STEP_UNIT, '/var/www/shop/releases/20261114-201500');
    $payload = stepInstallerPayload($transport);

    expect($properties)->toBe(['EnvironmentFile=/run/coresmith-steps/' . STEP_UNIT . '.env'])
        ->and(implode("\n", $transport->commands()))->not->toContain('nova-license-key')
        ->and(array_keys($payload))->toBe(['environment'])
        ->and($payload['environment'])->toBe('COMPOSER_AUTH="{\"http-basic\":{\"nova.laravel.com\":{\"username\":\"ops@example.com\",\"password\":\"nova-license-key-9f2c\"}}}"' . "\n");
});

test('config files are owned by the app user, mounted over home placeholders the app user creates', function () {
    $transport = new FakeSSHTransport;
    $application = stepInstallerApp();
    PackageCredential::factory()->for($application->team)->maven()->create();
    PackageCredential::factory()->for($application->team)->python()->create();

    $properties = app(StepSecretsInstaller::class)->prepare((new SSHConnection($application->server, $transport))->connect(), $application, STEP_UNIT, '/var/www/shop/releases/20261114-201500');
    $script = str_replace("'\\''", "'", (string) collect($transport->commands())->first(fn(string $command): bool => str_contains($command, '/run/coresmith-steps')));

    expect($properties)->toBe([
        'BindReadOnlyPaths=/run/coresmith-steps/' . STEP_UNIT . '/netrc:/home/cs-shop/.netrc',
        'BindReadOnlyPaths=/run/coresmith-steps/' . STEP_UNIT . '/settings.xml:/home/cs-shop/.m2/settings.xml',
        'BindReadOnlyPaths=/run/coresmith-steps/' . STEP_UNIT . '/gradle.properties:/home/cs-shop/.gradle/gradle.properties',
    ])->and(array_keys(stepInstallerPayload($transport)))->toBe(['netrc', 'settings.xml', 'gradle.properties'])
        ->and($script)->toContain('chown cs-shop:cs-shop')
        ->and($script)->toContain('chmod 400')
        ->and($script)->toContain('runuser -u cs-shop -- /bin/sh -c')
        ->and($script)->toContain('mkdir -p .m2 .gradle')
        ->and($script)->toContain('[ -e .m2/settings.xml ] || printf')
        ->and($script)->toContain('<settings/>')
        ->and($script)->toContain('[ -e .netrc ] || : > .netrc')
        ->and($script)->toContain("-regex '.*/coresmith-(build|blueprint)-shop-[0-9]{8}-?[0-9]{6}(\\.env)?'");
});

test("an npm token the repository's npmrc reads from a variable is set from the release, read as the app user", function () {
    $transport = (new FakeSSHTransport)->respondTo('--coresmith-project-file:', "\n--coresmith-project-file:.npmrc\n@acme:registry=https://npm.pkg.github.com\n//npm.pkg.github.com/:_authToken=\${NODE_AUTH_TOKEN}\n");
    $application = stepInstallerApp();
    PackageCredential::factory()->for($application->team)->npm()->create();

    $properties = app(StepSecretsInstaller::class)->prepare((new SSHConnection($application->server, $transport))->connect(), $application, STEP_UNIT, '/var/www/shop/releases/20261114-201500');
    $read = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, '--coresmith-project-file:'));

    expect($read)->toContain('runuser -u cs-shop --')
        ->and($read)->toContain('/var/www/shop/releases/20261114-201500')
        ->and(stepInstallerPayload($transport)['environment'])->toBe('NODE_AUTH_TOKEN="ghp_npmtoken0000"' . "\n")
        ->and($properties)->toContain('EnvironmentFile=/run/coresmith-steps/' . STEP_UNIT . '.env', 'BindReadOnlyPaths=/run/coresmith-steps/' . STEP_UNIT . '/npmrc:/home/cs-shop/.npmrc', 'BindReadOnlyPaths=/run/coresmith-steps/' . STEP_UNIT . '/yarnrc.yml:/home/cs-shop/.yarnrc.yml');
});

test('removing a step deletes its folder and environment file', function () {
    $transport = new FakeSSHTransport;
    $application = stepInstallerApp();

    app(StepSecretsInstaller::class)->remove((new SSHConnection($application->server, $transport))->connect(), STEP_UNIT);

    expect($transport->commands())->toContain("sudo rm -rf -- '/run/coresmith-steps/" . STEP_UNIT . "' '/run/coresmith-steps/" . STEP_UNIT . ".env'");
});

test('a unit name outside the build pattern is refused', function () {
    $transport = new FakeSSHTransport;
    $application = stepInstallerApp();

    app(StepSecretsInstaller::class)->remove((new SSHConnection($application->server, $transport))->connect(), '../../etc');
})->throws(UnsafeNameException::class);
