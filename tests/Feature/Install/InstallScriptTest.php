<?php

declare(strict_types=1);

use App\Services\Git\DeployKeyManager;
use App\Services\Install\SetupToken;
use Symfony\Component\Process\Process;

function installScript(): string
{
    return base_path('scripts/install.sh');
}

function installScriptBody(): string
{
    return (string) file_get_contents(installScript());
}

function runInstaller(array $arguments): Process
{
    $process = new Process(array_merge(['bash', installScript()], $arguments), base_path());
    $process->run();

    return $process;
}

test('the installer exists and is executable', function () {
    expect(is_file(installScript()))->toBeTrue()
        ->and(is_executable(installScript()))->toBeTrue()
        ->and(installScriptBody())->toStartWith('#!/usr/bin/env bash');
});

test('the installer is valid bash', function () {
    $process = new Process(['bash', '-n', installScript()]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

test('the installer passes shellcheck', function () {
    $shellcheck = (new Process(['which', 'shellcheck']));
    $shellcheck->run();

    if ($shellcheck->getExitCode() !== 0) {
        $this->markTestSkipped('shellcheck is not installed on this machine; CI installs it.');
    }

    $process = new Process(['shellcheck', '-S', 'style', installScript()]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput());
});

test('it refuses a database driver it cannot configure', function () {
    $process = runInstaller(['--db', 'oracle']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Invalid database driver');
});

test('it refuses a domain that is not a domain', function (string $domain) {
    $process = runInstaller(['--domain', $domain, '--unattended']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('is not a valid domain name');
})->with([
    'not a domain',
    'panel.example.com/../etc',
    '-flag.example.com',
    'http://panel.example.com',
]);

test('it refuses an option it does not understand rather than ignoring it', function () {
    $process = runInstaller(['--databse', 'sqlite']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Unknown option');
});

test('it stops before doing anything when not run as root', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('This test asserts the non-root path and cannot run as root.');
    }

    $process = runInstaller(['--unattended']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('must be run as root');
});

test('help is printed without touching the machine', function () {
    $process = runInstaller(['--help']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('--domain')
        ->and($process->getOutput())->toContain('--unattended');
});

test('the token path the installer writes is the one the panel reads', function () {
    config(['coresmith.setup_token_path' => '/etc/coresmith/setup-token']);

    expect(installScriptBody())->toContain('SETUP_TOKEN_FILE="/etc/coresmith/setup-token"')
        ->and(installScriptBody())->toContain('update_env CORESMITH_SETUP_TOKEN_PATH "$SETUP_TOKEN_FILE"')
        ->and(app(SetupToken::class)->path())->toBe('/etc/coresmith/setup-token');
});

test('the panel can read the directory its setup token lives in', function () {
    $body = installScriptBody();

    expect($body)->toContain('php_admin_value[open_basedir] = ${INSTALL_DIR}:${DATA_DIR}:${CONFIG_DIR}:/tmp')
        ->and($body)->toContain('chmod 755 "$CONFIG_DIR"');
});

test('the installer turns off public registration, which the holding page depends on', function () {
    expect(installScriptBody())->toContain('update_env CORESMITH_ALLOW_REGISTRATION "false"');
});

test('the installer issues the token through artisan rather than minting its own', function () {
    expect(installScriptBody())->toContain('php artisan coresmith:setup-token --force');
});

test('secrets are written with restrictive permissions', function () {
    $body = installScriptBody();

    expect($body)->toContain('chmod 600 .env')
        ->and($body)->toContain('chmod 600 "$DATA_DIR/database.sqlite"')
        ->and($body)->toContain('chmod 700 "$CONFIG_DIR/env" "$CONFIG_DIR/deploy-keys"');
});

test('the vault key is generated rather than left at a default', function () {
    expect(installScriptBody())->toContain('update_env CORESMITH_VAULT_KEY "$(openssl rand -hex 32)"');
});

test('debug mode is off in the installed environment', function () {
    expect(installScriptBody())->toContain('update_env APP_DEBUG "false"')
        ->and(installScriptBody())->toContain('update_env APP_ENV "production"');
});

test('the firewall keeps the detected ssh port open rather than assuming 22', function () {
    $body = installScriptBody();

    expect($body)->toContain('ufw allow "${SSH_PORT}/tcp"')
        ->and($body)->toContain('firewall-cmd --permanent --add-port="${SSH_PORT}/tcp"')
        ->and($body)->toContain("awk '/sshd/ {print \$4}'");
});

test('the deploy key directory the deployment code expects is created', function () {
    expect(installScriptBody())->toContain('deploy-keys')
        ->and(DeployKeyManager::KEY_DIR)->toBe('/etc/coresmith/deploy-keys');
});

test('the php version the installer installs satisfies what the project requires', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    $required = (string) ($composer['require']['php'] ?? '');

    preg_match('/readonly PHP_VERSION="([0-9.]+)"/', installScriptBody(), $matches);
    $installs = $matches[1] ?? '';

    $minimum = ltrim((string) preg_replace('/^[^0-9]*/', '', $required), 'v');

    expect($installs)->not->toBeEmpty()
        ->and(version_compare($installs.'.0', $minimum.(substr_count($minimum, '.') < 2 ? '.0' : ''), '>='))
        ->toBeTrue("The installer installs PHP {$installs}, which is older than composer.json's \"{$required}\".");
});

test('every phase the installer announces is actually defined', function () {
    $body = installScriptBody();

    preg_match_all('/Phase (\d)\/8/', $body, $matches);

    expect(array_unique($matches[1]))->toHaveCount(8);

    foreach ([
        'install_system_packages', 'install_caddy', 'create_user_and_dirs', 'install_coresmith_app',
        'configure_environment', 'configure_caddy', 'create_systemd_services', 'finalize',
    ] as $function) {
        expect($body)->toContain($function.'() {')
            ->and($body)->toContain("\n    {$function}\n");
    }
});

test('writing the environment survives a password full of shell metacharacters', function () {
    $directory = sys_get_temp_dir().'/coresmith-env-'.getmypid();
    @mkdir($directory, 0700, true);

    file_put_contents($directory.'/.env', "APP_ENV=local\nDB_PASSWORD=\nKEEP=me\n");

    preg_match('/^update_env\(\) \{.*?^\}$/ms', installScriptBody(), $matches);
    $function = $matches[0] ?? '';

    expect($function)->not->toBeEmpty();

    $password = 'p|ss&w"rd$with\\slashes/and|pipes';
    $script = 'INSTALL_DIR='.escapeshellarg($directory)."\n{$function}\n"
        ."update_env APP_ENV 'production'\n"
        .'update_env DB_PASSWORD '.escapeshellarg($password)."\n"
        ."update_env NEW_KEY 'added'\n";

    $process = new Process(['bash', '-c', $script]);
    $process->run();

    $result = (string) file_get_contents($directory.'/.env');

    @unlink($directory.'/.env');
    @rmdir($directory);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toContain('APP_ENV=production')
        ->and($result)->toContain('DB_PASSWORD='.$password)
        ->and($result)->toContain('KEEP=me')
        ->and($result)->toContain('NEW_KEY=added')
        ->and(substr_count($result, 'APP_ENV='))->toBe(1);
});

test('the continuous integration workflows are present and valid', function () {
    foreach (['ci.yml', 'installer.yml'] as $workflow) {
        expect(is_file(base_path('.github/workflows/'.$workflow)))->toBeTrue("{$workflow} is missing");
    }

    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    foreach (['pint --test', 'lint:check', 'lint:sh', 'format:check', 'types:check', 'bun run build', 'php artisan test', 'test:e2e'] as $gate) {
        expect($ci)->toContain($gate);
    }
});

test('the installer workflow proves the instance it built actually works', function () {
    $workflow = (string) file_get_contents(base_path('.github/workflows/installer.yml'));

    expect($workflow)->toContain('bash scripts/install.sh')
        ->and($workflow)->toContain('ubuntu-22.04')
        ->and($workflow)->toContain('ubuntu-24.04')
        ->and($workflow)->toContain('/etc/coresmith/setup-token')
        ->and($workflow)->toContain('expected 403')
        ->and($workflow)->toContain('expected 503');
});
