<?php

declare(strict_types=1);

use App\Http\Middleware\ContentSecurityPolicy;
use App\Services\Git\DeployKeyManager;
use App\Services\Install\SetupToken;
use App\Services\VaultEncryptor;
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
    config(['coresmith.setup_token_path' => '/var/lib/coresmith/setup-token']);

    expect(installScriptBody())->toContain('SETUP_TOKEN_FILE="/var/lib/coresmith/setup-token"')
        ->and(installScriptBody())->toContain('update_env CORESMITH_SETUP_TOKEN_PATH "$SETUP_TOKEN_FILE"')
        ->and(app(SetupToken::class)->path())->toBe('/var/lib/coresmith/setup-token');
});

test('the setup token lives where the sandboxed php-fpm can delete it once used', function () {
    $body = installScriptBody();

    expect($body)->toContain('readonly DATA_DIR="/var/lib/coresmith"')
        ->and($body)->toContain('SETUP_TOKEN_FILE="/var/lib/coresmith/setup-token"')
        ->and($body)->toContain('chown -R coresmith:coresmith "$DATA_DIR" "$APPS_DIR"')
        ->and($body)->toContain('php_admin_value[open_basedir] = ${INSTALL_DIR}:${DATA_DIR}:/tmp')
        ->and($body)->not->toContain('SETUP_TOKEN_FILE="/etc/');
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
    expect(installScriptBody())->toContain('vault_key_is_valid || update_env CORESMITH_VAULT_KEY "$(openssl rand -base64 32)"');
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
        ->and(version_compare($installs . '.0', $minimum . (substr_count($minimum, '.') < 2 ? '.0' : ''), '>='))
        ->toBeTrue("The installer installs PHP {$installs}, which is older than composer.json's \"{$required}\".");
});

test('every phase the installer announces is actually defined', function () {
    $body = installScriptBody();

    preg_match_all('/Phase (\d)\/8/', $body, $matches);

    expect(array_unique($matches[1]))->toHaveCount(8);

    foreach (
        [
            'install_system_packages',
            'install_caddy',
            'create_user_and_dirs',
            'install_coresmith_app',
            'configure_environment',
            'configure_caddy',
            'create_systemd_services',
            'finalize',
        ] as $function
    ) {
        expect($body)->toContain($function . '() {')
            ->and($body)->toContain("\n    {$function}\n");
    }
});

test('writing the environment survives a password full of shell metacharacters', function () {
    $directory = sys_get_temp_dir() . '/coresmith-env-' . getmypid();
    @mkdir($directory, 0700, true);

    file_put_contents($directory . '/.env', "APP_ENV=local\nDB_PASSWORD=\nKEEP=me\n");

    preg_match('/^update_env\(\) \{.*?^\}$/ms', installScriptBody(), $matches);
    $function = $matches[0] ?? '';

    expect($function)->not->toBeEmpty();

    $password = 'p|ss&w"rd$with\\slashes/and|pipes';
    $script = 'INSTALL_DIR=' . escapeshellarg($directory) . "\n{$function}\n"
        . "update_env APP_ENV 'production'\n"
        . 'update_env DB_PASSWORD ' . escapeshellarg($password) . "\n"
        . "update_env NEW_KEY 'added'\n";

    $process = new Process(['bash', '-c', $script]);
    $process->run();

    $result = (string) file_get_contents($directory . '/.env');

    @unlink($directory . '/.env');
    @rmdir($directory);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toContain('APP_ENV=production')
        ->and($result)->toContain('DB_PASSWORD=' . $password)
        ->and($result)->toContain('KEEP=me')
        ->and($result)->toContain('NEW_KEY=added')
        ->and(substr_count($result, 'APP_ENV='))->toBe(1);
});

test('the continuous integration workflows are present and valid', function () {
    foreach (['ci.yml', 'installer.yml'] as $workflow) {
        expect(is_file(base_path('.github/workflows/' . $workflow)))->toBeTrue("{$workflow} is missing");
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
        ->and($workflow)->toContain('/var/lib/coresmith/setup-token')
        ->and($workflow)->toContain('expected 403')
        ->and($workflow)->toContain('expected 503');
});

test('caddy only issues certificates for hosts the panel approves, asked over loopback', function () {
    $body = installScriptBody();

    expect($body)->toContain('permission http http://127.0.0.1:8484/api/caddy/ask')
        ->and($body)->toContain("http://127.0.0.1:8484 {\n    bind 127.0.0.1")
        ->and($body)->toContain("https:// {\n    tls {\n        on_demand\n    }");
});

test('team domains get hsts for themselves only, never for the customer subdomains', function () {
    $site = str(installScriptBody())->after("https:// {\n")->before("\n}\nSITECONF")->toString();

    expect($site)->toContain('Strict-Transport-Security "max-age=31536000"')
        ->and($site)->not->toContain('includeSubDomains')
        ->and($site)->not->toContain('preload');
});

test('failed jobs and batches live on a connection the installer migrates', function () {
    $body = installScriptBody();
    $queue = (string) file_get_contents(base_path('config/queue.php'));
    $jobs = (string) file_get_contents(base_path('database/migrations/0001_01_01_000002_create_jobs_table.php'));

    expect($body)->toContain('update_env DB_QUEUE_CONNECTION "$DB_DRIVER"')
        ->and($body)->toContain('update_env DB_CACHE_CONNECTION "$DB_DRIVER"')
        ->and(substr_count($queue, "env('DB_QUEUE_CONNECTION', 'sqlite_queue')"))->toBe(3)
        ->and((string) file_get_contents(base_path('config/cache.php')))->toContain("env('DB_CACHE_CONNECTION', 'sqlite_cache')")
        ->and($jobs)->toContain("Schema::create('failed_jobs'")
        ->and($jobs)->toContain("Schema::create('job_batches'");
});

test('the application is installed without the composer scripts meant for a development checkout', function () {
    $body = installScriptBody();

    expect($body)->toContain('composer install --no-dev --optimize-autoloader --no-interaction --no-scripts')
        ->and($body)->toContain('run sudo -u coresmith php artisan package:discover --ansi')
        ->and($body)->toContain('run sudo -u coresmith php artisan vendor:publish --tag=laravel-assets --ansi --force');
});

test('the installer can install from a local checkout as well as a bare repository', function () {
    expect(installScriptBody())->toContain('git config --system --add safe.directory "$CORESMITH_REPO/.git"');
});

test('caddy is validated as itself and restarted so the panel config is actually served', function () {
    $body = installScriptBody();

    expect($body)->toContain('{ sudo -u caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile; }')
        ->and($body)->toContain('chown -R caddy:caddy /var/log/caddy')
        ->and($body)->toContain("run systemctl enable caddy\n    run systemctl restart caddy")
        ->and($body)->not->toContain('systemctl enable --now caddy');
});

test('caddy can read what it serves without being able to read or change the rest of the panel', function () {
    $body = installScriptBody();

    expect($body)->toContain('setfacl -m u:caddy:x "$(dirname "$INSTALL_DIR")" "$INSTALL_DIR"')
        ->and($body)->toContain('setfacl -R -m u:caddy:rX "$INSTALL_DIR/public"')
        ->and($body)->toContain('setfacl -R -d -m u:caddy:rX "$INSTALL_DIR/public"')
        ->and($body)->not->toMatch('/usermod\s+-aG\s+coresmith\s+caddy/');
});

test('an empty saved route config never replaces the panel config when caddy starts', function () {
    $body = installScriptBody();

    expect($body)->toContain('grep -qs "\"apps\"" /etc/caddy/caddy-config.json && curl -fsS -X POST http://localhost:2019/load')
        ->and($body)->not->toContain("echo '{}' >/etc/caddy/caddy-config.json");
});

test('the setup token is written by the panel user into a file it owns', function () {
    $body = installScriptBody();

    expect($body)->toContain('install -o coresmith -g coresmith -m 600 /dev/null "$SETUP_TOKEN_FILE"')
        ->and($body)->toContain('coresmith:setup-token --force 2>&1)" || true')
        ->and($body)->toContain("grep -q 'already has an account'")
        ->and($body)->toContain('The panel is already set up. Sign in at');
});

test('a failed step is named on the way out rather than ending the install silently', function () {
    $body = installScriptBody();

    expect($body)->toContain('set -Eeuo pipefail')
        ->and($body)->toContain('trap on_failure ERR')
        ->and($body)->toContain('error "The installer stopped (exit ${status}) at: ${step}"');
});

test('the content security policy comes from the panel, so caddy never sends a second one', function () {
    expect(installScriptBody())->not->toContain('Content-Security-Policy')
        ->and(class_exists(ContentSecurityPolicy::class))->toBeTrue();
});

function installScriptFunctions(string ...$names): string
{
    return collect($names)->map(function (string $name): string {
        preg_match('/^' . preg_quote($name, '/') . '\(\) \{.*?^\}$/ms', installScriptBody(), $matches);

        return $matches[0] ?? '';
    })->implode("\n");
}

function runInstallScriptFunctions(string $directory, string $body): Process
{
    $script = 'set -euo pipefail' . "\n" . 'INSTALL_DIR=' . escapeshellarg($directory) . "\nlog() { :; }\n"
        . installScriptFunctions('env_value', 'vault_key_is_valid', 'ensure_env_secret', 'update_env', 'adopt_existing_install', 'ensure_database_password')
        . "\n" . $body;

    $process = new Process(['bash', '-c', $script]);
    $process->run();

    return $process;
}

test('a re-run keeps the database, password and domain the panel was installed with', function () {
    $directory = sys_get_temp_dir() . '/coresmith-rerun-' . getmypid();
    @mkdir($directory, 0700, true);
    file_put_contents($directory . '/.env', "APP_URL=https://panel.example.com\nACME_EMAIL=ops@example.com\nDB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=coresmith\nDB_USERNAME=coresmith\nDB_PASSWORD=\"kept-secret\"\n");

    $process = runInstallScriptFunctions($directory, 'DOMAIN=""; ACME_EMAIL=""; DB_EXPLICITLY_SET=false; DB_DRIVER=sqlite; DB_HOST=""; DB_PORT=""; DB_NAME=""; DB_USER=""; DB_PASSWORD=""' . "\n"
        . 'adopt_existing_install; ensure_database_password' . "\n"
        . 'printf "%s|%s|%s|%s|%s|%s" "$DOMAIN" "$ACME_EMAIL" "$DB_DRIVER" "$DB_NAME" "$DB_USER" "$DB_PASSWORD"');

    @unlink($directory . '/.env');
    @rmdir($directory);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe('panel.example.com|ops@example.com|pgsql|coresmith|coresmith|kept-secret');
});

test('an unattended install on postgresql or mysql still gets a database password', function () {
    $directory = sys_get_temp_dir() . '/coresmith-fresh-' . getmypid();
    @mkdir($directory, 0700, true);

    $process = runInstallScriptFunctions($directory, 'DOMAIN=""; ACME_EMAIL=""; DB_EXPLICITLY_SET=true; DB_DRIVER=pgsql; DB_PASSWORD=""' . "\n"
        . 'adopt_existing_install; ensure_database_password; printf "%s" "$DB_PASSWORD"');

    @rmdir($directory);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toMatch('/^[0-9a-f]{32}$/');
});

test('an existing secret is never replaced and a missing one is created', function () {
    $directory = sys_get_temp_dir() . '/coresmith-secrets-' . getmypid();
    @mkdir($directory, 0700, true);
    file_put_contents($directory . '/.env', "CORESMITH_VAULT_KEY=original\nREVERB_APP_KEY=\n");

    $process = runInstallScriptFunctions($directory, "ensure_env_secret CORESMITH_VAULT_KEY replaced\nensure_env_secret REVERB_APP_KEY created\nensure_env_secret REVERB_APP_SECRET added\n");
    $result = (string) file_get_contents($directory . '/.env');

    @unlink($directory . '/.env');
    @rmdir($directory);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toContain('CORESMITH_VAULT_KEY=original')
        ->and($result)->not->toContain('replaced')
        ->and($result)->toContain('REVERB_APP_KEY=created')
        ->and($result)->toContain('REVERB_APP_SECRET=added');
});

test('re-running the installer keeps the keys that existing data was encrypted with', function () {
    $body = installScriptBody();

    expect($body)->toContain('[ -n "$(env_value APP_KEY)" ] || sudo -u coresmith php artisan key:generate')
        ->and($body)->toContain('ensure_env_secret REVERB_APP_KEY')
        ->and($body)->toContain('ensure_env_secret REVERB_APP_SECRET')
        ->and($body)->not->toContain('    update_env CORESMITH_VAULT_KEY "$(openssl rand')
        ->and($body)->toContain("preflight\n    adopt_existing_install\n    interactive_prompts\n    ensure_database_password");
});

test('the browser is given the reverb key so live updates use the socket', function () {
    expect(installScriptBody())->toContain('update_env VITE_REVERB_APP_KEY "$(env_value REVERB_APP_KEY)"');
});

test('the vault key the installer generates is one the vault accepts', function () {
    $process = new Process(['openssl', 'rand', '-base64', '32']);
    $process->run();
    $key = trim($process->getOutput());

    expect($process->getExitCode())->toBe(0)
        ->and(fn() => new VaultEncryptor($key))->not->toThrow(InvalidArgumentException::class)
        ->and(installScriptBody())->toContain('update_env CORESMITH_VAULT_KEY "$(openssl rand -base64 32)"');
});

test('a vault key that the vault would refuse is replaced, and a good one is kept', function (string $existing, bool $kept) {
    $directory = sys_get_temp_dir() . '/coresmith-vault-' . getmypid();
    @mkdir($directory, 0700, true);
    file_put_contents($directory . '/.env', "CORESMITH_VAULT_KEY={$existing}\n");

    $process = runInstallScriptFunctions($directory, 'vault_key_is_valid || update_env CORESMITH_VAULT_KEY "$(openssl rand -base64 32)"');
    preg_match('/^CORESMITH_VAULT_KEY=(.*)$/m', (string) file_get_contents($directory . '/.env'), $matches);

    @unlink($directory . '/.env');
    @rmdir($directory);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(($matches[1] ?? '') === $existing)->toBe($kept)
        ->and(fn() => new VaultEncryptor($matches[1] ?? ''))->not->toThrow(InvalidArgumentException::class);
})->with([
    'a valid key' => ['kwaPNVUnWP5zWso3XuetpQuqerZlJ5jX9rpFguOAepg=', true],
    'a hex key from older installers' => [str_repeat('ab', 32), false],
    'no key at all' => ['', false],
]);

test('a re-run restarts the queue workers and websockets so they run the new code', function () {
    $body = installScriptBody();

    expect($body)->toContain('horizon_pid="$(systemctl show -p MainPID --value coresmith-horizon)"')
        ->and($body)->toContain('run sudo -u coresmith php "$INSTALL_DIR/artisan" horizon:terminate')
        ->and($body)->toContain('run systemctl restart coresmith-reverb')
        ->and($body)->not->toContain('systemctl enable --now coresmith-horizon')
        ->and($body)->toContain('wait_for_service caddy || healthy=false')
        ->and($body)->toContain('wait_for_service coresmith-horizon "$horizon_pid" || healthy=false')
        ->and($body)->toContain('wait_for_service coresmith-reverb || healthy=false');
});

function installWaitForService(string $systemctl, string $arguments): array
{
    $steps = (string) tempnam(sys_get_temp_dir(), 'coresmith-wait-');
    file_put_contents($steps, '0');

    $stubs = <<<'BASH'
        set -euo pipefail
        log() { printf 'log: %s\n' "$1"; }
        success() { printf 'ok: %s\n' "$1"; }
        error() { printf 'error: %s\n' "$1"; }
        step() { cat "$STEPS"; }
        sleep() { echo $(($(step) + 1)) > "$STEPS"; }
        BASH;

    $process = new Process(['bash', '-c', $stubs . "\n" . $systemctl . "\n" . installScriptFunctions('wait_for_service') . "\nwait_for_service " . $arguments], null, ['STEPS' => $steps]);
    $process->run();
    $waited = (int) file_get_contents($steps);
    @unlink($steps);

    return [$process, $waited];
}

test('a re-run waits for the new horizon process before calling it running', function () {
    [$process, $waited] = installWaitForService(<<<'BASH'
        systemctl() {
            case "$1" in
                is-active) [ "$(step)" -lt 1 ] || [ "$(step)" -ge 3 ] ;;
                show) if [ "$(step)" -lt 3 ]; then echo 4242; else echo 5151; fi ;;
            esac
        }
        BASH, 'coresmith-horizon 4242');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("ok: coresmith-horizon is running\n")
        ->and($waited)->toBe(3);
});

test('a horizon still finishing its jobs is reported as moving over, not as failed', function () {
    [$process, $waited] = installWaitForService('systemctl() { case "$1" in is-active) return 0 ;; show) echo 4242 ;; esac; }', 'coresmith-horizon 4242');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("log: coresmith-horizon is finishing its current jobs and moves to the new version once they are done\n")
        ->and($waited)->toBe(60);
});

test('a service that never comes up fails the run and says where to look', function () {
    [$process, $waited] = installWaitForService('systemctl() { case "$1" in is-active) return 3 ;; show) echo 0 ;; esac; }', 'caddy');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe("error: caddy did not start. Inspect it with: journalctl -u caddy -n 50\n")
        ->and($waited)->toBe(60);
});

test('a service that is already up is reported without waiting', function () {
    [$process, $waited] = installWaitForService('systemctl() { case "$1" in is-active) return 0 ;; show) echo 777 ;; esac; }', 'caddy');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("ok: caddy is running\n")
        ->and($waited)->toBe(0);
});

test('without a domain the panel answers on the address the server owns, not one a router owns', function () {
    $body = installScriptBody();

    expect($body)->toContain('SERVER_IP="$(ip -4 route get 1.1.1.1 2>/dev/null')
        ->and($body)->not->toContain('default_sni')
        ->and($body)->toContain('through NAT. Without --domain the panel answers on');
});
