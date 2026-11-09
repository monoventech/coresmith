<?php

declare(strict_types=1);

use App\Http\Middleware\ContentSecurityPolicy;
use App\Services\Git\DeployKeyManager;
use App\Services\Install\SetupToken;
use App\Services\Updates\PanelServices;
use App\Services\Updates\Release;
use App\Services\Updates\ReleaseLayout;
use App\Services\VaultEncryptor;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function installScript(): string
{
    return base_path('scripts/install.sh');
}

function installScriptBody(): string
{
    return (string) file_get_contents(installScript());
}

function installSiteTemplate(): string
{
    return (string) file_get_contents(base_path('resources/caddy/panel.caddy'));
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
        ->and($body)->toContain('php_admin_value[open_basedir] = ${RELEASES_DIR}:${SHARED_DIR}:${DATA_DIR}:/tmp')
        ->and($body)->not->toContain('SETUP_TOKEN_FILE="/etc/');
});

test('too little disk stops the install and a little is a warning', function () {
    expect(installScriptBody())
        ->toContain('[ "${disk_gb:-0}" -ge 5 ] || fatal "Only ${disk_gb}GB of disk space is free. Coresmith needs at least 5GB, and 10GB or more is recommended."')
        ->toContain('warn "Only ${disk_gb}GB of disk space is free. Coresmith recommends at least 10GB."')
        ->not->toContain('Coresmith needs at least 10GB');
});

test('the installer turns off public registration, which the holding page depends on', function () {
    expect(installScriptBody())->toContain('update_env CORESMITH_ALLOW_REGISTRATION "false"');
});

test('the installer issues the token through artisan rather than minting its own', function () {
    expect(installScriptBody())->toContain('php artisan coresmith:setup-token --force');
});

test('a token the installer could not issue comes with the link to use it in', function () {
    expect(installScriptBody())
        ->toContain('warn "Could not issue a setup token. Run this on the server, then open ${PANEL_URL}/install?token= followed by the token it prints:"')
        ->not->toContain('open the URL it prints');
});

test('secrets are written with restrictive permissions', function () {
    $body = installScriptBody();

    expect($body)->toContain('chmod 600 "$SHARED_DIR/.env"')
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
        ->and($body)->toContain("awk '/sshd/ {print \$4}'");
});

/**
 * @param  array<string, string>  $fakes
 * @return array{0: list<string>, 1: string}
 */
function installFirewallRun(array $fakes): array
{
    preg_match('/^configure_firewall\(\) \{\n.*?^\}$/ms', installScriptBody(), $function);
    $bin = sys_get_temp_dir() . '/install-firewall-' . bin2hex(random_bytes(4));
    mkdir($bin);
    $log = $bin . '/calls';

    foreach ($fakes as $command => $answer) {
        file_put_contents("{$bin}/{$command}", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\n{$answer}\n");
        chmod("{$bin}/{$command}", 0755);
    }

    $process = new Process(['bash', '-c', $function[0] . "\nrun() { \"\$@\"; }\nsuccess() { printf '%s\\n' \"\$1\"; }\nwarn() { printf '%s\\n' \"\$1\"; }\nSSH_PORT=2222\nconfigure_firewall\n"], null, ['PATH' => $bin . ':/usr/bin:/bin']);
    $process->run();
    $calls = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];

    array_map(unlink(...), glob($bin . '/*') ?: []);
    rmdir($bin);

    return [$calls, trim($process->getOutput() . $process->getErrorOutput())];
}

test('a ufw firewall that is already on keeps its own ssh rules, and only the web ports are opened', function () {
    [$calls, $output] = installFirewallRun(['ufw' => "[ \"\$1\" = status ] && printf 'Status: active\\n\\n22/tcp ALLOW IN 192.168.0.0/24\\n'\nexit 0"]);

    expect($calls)->toBe(['status', 'allow 80/tcp', 'allow 443/tcp'])
        ->and($output)->toContain('left its SSH rules as they were');
});

test('a ufw firewall that was off is turned on with ssh kept open, so the install locks nobody out', function () {
    [$calls] = installFirewallRun(['ufw' => "[ \"\$1\" = status ] && printf 'Status: inactive\\n'\nexit 0"]);

    expect($calls)->toBe(['status', 'allow 2222/tcp', 'allow 80/tcp', 'allow 443/tcp', '--force enable']);
});

test('a running firewalld keeps its own ssh rules, and only the web services are opened', function () {
    [$calls, $output] = installFirewallRun(['firewall-cmd' => 'exit 0']);

    expect($calls)->toBe(['--state', '--permanent --add-service=http', '--permanent --add-service=https', '--reload'])
        ->and($output)->toContain('left its SSH rules as they were');
});

test('a firewalld that is not running is left alone and the installer says what to open', function () {
    [$calls, $output] = installFirewallRun(['firewall-cmd' => '[ "$1" = --state ] && exit 252' . "\nexit 0"]);

    expect($calls)->toBe(['--state'])
        ->and($output)->toContain('Open 80 and 443, and keep 2222 open, before exposing this host.');
});

test('caddy comes from its own repository, even where an ubuntu pro mirror ranks its older build higher', function () {
    $body = installScriptBody();
    $pin = strpos($body, 'Package: caddy\\nPin: origin dl.cloudsmith.io\\nPin-Priority: 1001');
    $install = strpos($body, 'run apt-get install -y -qq caddy');

    expect($pin)->toBeInt()
        ->and($install)->toBeInt()
        ->and($pin)->toBeLessThan($install);
});

test('only a caddy new enough for the panel config counts as already installed', function (string $version, bool $enough) {
    preg_match('/^caddy_new_enough\(\) \{\n.*?^\}$/ms', installScriptBody(), $function);
    $bin = sys_get_temp_dir() . '/install-caddy-' . bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents($bin . '/caddy', "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($version) . "\n");
    chmod($bin . '/caddy', 0755);

    $process = new Process(['bash', '-c', $function[0] . "\ncaddy_new_enough && echo enough || echo old\n"], null, ['PATH' => $bin . ':/usr/bin:/bin']);
    $process->run();

    unlink($bin . '/caddy');
    rmdir($bin);

    expect(trim($process->getOutput()))->toBe($enough ? 'enough' : 'old');
})->with([
    'the ubuntu pro build' => ['2.6.2', false],
    'one release short' => ['v2.7.6 h1:Bzqu0f5uTGAnCGn6YQOzzK6MdyNtF4F3KR5y1DmamUs=', false],
    'the first with permission' => ['v2.8.0 h1:abc=', true],
    'a current release' => ['v2.11.9 h1:abc=', true],
    'a future major' => ['v3.0.0', true],
]);

test('a caddy still too old after installing stops the install with the version it found', function () {
    expect(installScriptBody())->toContain('is too old: Coresmith needs 2.8 or newer');
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

    $function = installScriptFunctions('refuse_link', 'update_env');

    expect($function)->not->toBeEmpty();

    $password = 'p|ss&w"rd$with\\slashes/and|pipes';
    $script = 'SHARED_DIR=' . escapeshellarg($directory) . "\n{$function}\n"
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

test('continuous integration runs the feature tests on every database the panel supports', function (string $job, string $image, string $driver) {
    $definition = Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs'][$job] ?? null;

    expect($definition)->toBeArray()
        ->and($definition)->not->toHaveKey('if')
        ->and(array_column($definition['services'] ?? [], 'image'))->toBe([$image])
        ->and($definition['env']['DB_CONNECTION'] ?? null)->toBe($driver)
        ->and(array_map(trim(...), array_column($definition['steps'] ?? [], 'run')))->toContain('php artisan test');
})->with([
    'PostgreSQL' => ['tests-pgsql', 'postgres:17', 'pgsql'],
    'MySQL' => ['tests-mysql', 'mysql:8.0', 'mysql'],
]);

test('the installer clones from a directory the coresmith user can enter', function () {
    $body = installScriptBody();
    $clone = strpos($body, 'sudo -u coresmith git clone');
    $leaves = strrpos(substr($body, 0, (int) $clone), "\n    cd /\n");

    expect($clone)->not->toBeFalse()
        ->and($leaves)->not->toBeFalse()
        ->and(substr_count(substr($body, (int) $leaves, (int) $clone - (int) $leaves), "\n    cd "))->toBe(1);
});

test('the local run checks everything continuous integration does', function () {
    $script = (string) file_get_contents(base_path('scripts/ci-local.sh'));
    $package = json_decode((string) file_get_contents(base_path('package.json')), true);

    foreach (['pint', '--test', 'lint:check', 'lint:sh', 'format:check', 'lint:tailwind', 'types:check', 'run build', 'pest --parallel', 'DB_CONNECTION=pgsql', 'DB_CONNECTION=mysql', 'e2e:refresh', 'e2e:install-refresh', 'e2e:cloud-refresh', 'playwright test', '--shard='] as $step) {
        expect($script)->toContain($step);
    }

    expect($package['scripts']['ci'] ?? null)->toBe('bash scripts/ci-local.sh');
});

test('browser shards can run side by side, each server on its own address', function () {
    $config = (string) file_get_contents(base_path('playwright.config.ts'));

    expect($config)
        ->toContain('command: `php artisan serve --no-reload --port=${PORT} --env=e2e`,' . "\n            env: { APP_URL: baseURL },")
        ->toContain('command: `php artisan serve --no-reload --port=${INSTALL_PORT} --env=e2e-install`,' . "\n            env: { APP_URL: baseURL },")
        ->toContain('command: `php artisan serve --no-reload --port=${CLOUD_PORT} --env=e2e-cloud`,' . "\n" . '            env: { APP_URL: cloudBaseURL, CORESMITH_STRIPE_API_BASE: `http://127.0.0.1:${STRIPE_PORT}` },');
});

test('continuous integration splits the browser suite across parallel shards that cover all of it', function () {
    $jobs = Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs'];
    $shards = $jobs['browser']['strategy']['matrix']['shard'] ?? [];
    $count = count($shards);
    $steps = collect($jobs['browser']['steps'] ?? []);

    expect($count)->toBeGreaterThan(1)
        ->and($shards)->toBe(range(1, $count))
        ->and($jobs['browser']['strategy']['fail-fast'] ?? null)->toBeFalse()
        ->and($steps->firstWhere('name', 'Run Playwright')['run'] ?? null)->toBe('bun run test:e2e --shard=${{ matrix.shard }}/' . $count)
        ->and($steps->firstWhere('name', 'Upload failure report')['with']['name'] ?? null)->toBe('playwright-report-${{ matrix.shard }}');
});

test('continuous integration reports one browser result, whatever the shards did', function () {
    $result = Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs']['browser-result'] ?? null;

    expect($result['name'] ?? null)->toBe('Browser tests')
        ->and($result['needs'] ?? null)->toBe('browser')
        ->and($result['if'] ?? null)->toBe('always()')
        ->and(array_column($result['steps'] ?? [], 'run'))->toBe(['test "${{ needs.browser.result }}" = "success"']);
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

test('the installer workflow updates, rolls back and uninstalls the instance it built', function () {
    $workflow = (string) file_get_contents(base_path('.github/workflows/installer.yml'));

    expect($workflow)->toContain('sudo bash tests/Machine/updates.sh /srv/coresmith-under-test.git')
        ->and($workflow)->toContain('timeout-minutes: 90')
        ->and($workflow)->toContain("'app/Services/Updates/**'")
        ->and($workflow)->toContain("'tests/Machine/**'")
        ->and($workflow)->toContain("'scripts/**'")
        ->and($workflow)->toContain('journalctl -u coresmith-update -n 100')
        ->and($workflow)->toContain('/var/lib/coresmith/updates/status.json');
});

test('the machine test checks each scenario it promises', function () {
    $script = (string) @file_get_contents(base_path('tests/Machine/updates.sh'));

    foreach ([
        'the machine test is set up',
        'a signed update is installed',
        'a broken release is rolled back',
        'an unsigned release is refused before anything changes',
        "the panel's request starts the update service",
        'an interrupted update recovers on its own',
        'an update interrupted past the switch is rolled back from the scheduler',
        'the uninstaller leaves the machine clean',
    ] as $scenario) {
        expect($script)->toContain("ok \"{$scenario}\"");
    }

    expect($script)->toContain("printf 'ok %s\\n'");
});

test('the machine test is valid bash that the shell lint covers', function () {
    $process = new Process(['bash', '-n', base_path('tests/Machine/updates.sh')]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(is_executable(base_path('tests/Machine/updates.sh')))->toBeTrue()
        ->and((string) file_get_contents(base_path('package.json')))->toContain('"lint:sh": "shellcheck -S style scripts/*.sh tests/Machine/*.sh"');
});

test('caddy only issues certificates for hosts the panel approves, asked over loopback', function () {
    expect(installScriptBody())->toContain('permission http http://127.0.0.1:8484/api/caddy/ask')
        ->and(installSiteTemplate())->toContain("http://127.0.0.1:8484 {\n    bind 127.0.0.1")
        ->and(installSiteTemplate())->toContain("https:// {\n    tls {\n        on_demand\n    }");
});

test('the updater checks the new release over the loopback site, never through tls', function () {
    $site = str(installSiteTemplate())->after("http://127.0.0.1:8484 {\n")->before("\n}\n")->toString();

    expect($site)->toContain("@release path /api/up/release\n    handle @release {\n        php_fastcgi unix//run/php/coresmith-panel-fpm.sock {\n            resolve_root_symlink\n        }\n    }")
        ->and(config('coresmith.updates.probe_url'))->toBe('http://127.0.0.1:8484/api/up/release');
});

test('team domains get hsts for themselves only, never for the customer subdomains', function () {
    $site = str(installSiteTemplate())->after("https:// {\n")->before("\n}\n__REDIRECTS__")->toString();

    expect($site)->toContain('Strict-Transport-Security "max-age=31536000"')
        ->and($site)->not->toContain('includeSubDomains')
        ->and($site)->not->toContain('preload');
});

test('the site Caddy serves comes from the template Coresmith also writes, which only coresmith may change', function () {
    $body = installScriptBody();

    expect($body)->toContain('readonly CADDY_SITE="$CADDY_SITE_DIR/panel.caddy"')
        ->and($body)->toContain("printf 'import %s\\n' \"\$CADDY_SITE\"")
        ->and($body)->toContain("chown coresmith:caddy \"\$CADDY_SITE_DIR\"\n    chmod 2750 \"\$CADDY_SITE_DIR\"")
        ->and($body)->toContain('"$RELEASE_DIR/resources/caddy/panel.caddy" >"$CADDY_SITE"')
        ->and($body)->toContain("chown coresmith:caddy \"\$CADDY_SITE\"\n    chmod 0640 \"\$CADDY_SITE\"")
        ->and($body)->toContain('rm -f "$LEGACY_CADDY_SITE"')
        ->and($body)->toContain('update_env CORESMITH_CADDY_SITE "$CADDY_SITE"')
        ->and($body)->not->toContain('SITECONF');
});

test('the site template serves the release the installer installs, through the pool it configures', function () {
    $body = installScriptBody();

    expect($body)->toContain('readonly CORESMITH_ROOT="/opt/coresmith"')
        ->and($body)->toContain('readonly INSTALL_DIR="$CORESMITH_ROOT/app"')
        ->and($body)->toContain('local fpm_sock="/run/php/coresmith-panel-fpm.sock"')
        ->and($body)->toContain('listen = ${fpm_sock}')
        ->and(substr_count(installSiteTemplate(), 'root * /opt/coresmith/app/public'))->toBe(3)
        ->and(substr_count(installSiteTemplate(), 'php_fastcgi unix//run/php/coresmith-panel-fpm.sock {'))->toBe(4);
});

test('the installer fills the template with the address and no other site', function () {
    $line = trim((string) collect(explode("\n", installScriptBody()))->first(fn(string $line): bool => str_contains($line, 'resources/caddy/panel.caddy')));
    $script = 'RELEASE_DIR=' . escapeshellarg(base_path()) . '; PANEL_HOST=coresmith.example.com; CADDY_SITE=/dev/stdout; ' . $line;

    $process = new Process(['bash', '-c', $script]);
    $process->mustRun();
    $site = $process->getOutput();

    expect($site)->toStartWith("coresmith.example.com {\n    root * /opt/coresmith/app/public\n")
        ->and($site)->not->toContain('__')
        ->and($site)->toEndWith("    }\n}\n");
});

test('an address change runs in its own service that may write only the caddy site', function () {
    $body = installScriptBody();
    $unit = str($body)->after("cat >/etc/systemd/system/coresmith-address.service <<UNIT\n")->before("\nUNIT\n")->toString();

    expect($unit)->toContain("Type=oneshot\nUser=coresmith\nGroup=coresmith\n")
        ->and($unit)->toContain('ExecStartPre=-/bin/mv -f ${ADDRESS_DIR}/request.json ${ADDRESS_DIR}/request.taken.json')
        ->and($unit)->toContain('ExecStart=/usr/bin/php artisan coresmith:address --no-interaction')
        ->and($unit)->toContain("NoNewPrivileges=true\nPrivateTmp=true\nProtectSystem=full\nReadWritePaths=\${CADDY_SITE_DIR}")
        ->and($body)->toContain("[Path]\nPathExists=\${ADDRESS_DIR}/request.json\nUnit=coresmith-address.service")
        ->and($body)->toContain('run systemctl enable --now coresmith-address.path')
        ->and($body)->toContain('chmod 700 "$UPDATES_DIR" "$ADDRESS_DIR"');
});

test('the summary names the address coresmith answers on, lined up with the rest', function () {
    $body = installScriptBody();

    expect($body)->toContain("printf '  %sAddress:%s      %s\\n' \"\$BOLD\" \"\$NC\" \"\$PANEL_URL\"")
        ->and($body)->toContain("printf '  %sDatabase:%s     %s\\n'")
        ->and($body)->not->toContain('Panel:');
});

test('the address requests the installer watches are where coresmith writes them', function () {
    $body = installScriptBody();

    expect($body)->toContain('readonly DATA_DIR="/var/lib/coresmith"')
        ->and($body)->toContain('readonly ADDRESS_DIR="$DATA_DIR/address"')
        ->and((require config_path('coresmith.php'))['address']['state_path'])->toBe('/var/lib/coresmith/address');
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

    expect($body)->toContain('setfacl -P -m u:caddy:x "$CORESMITH_ROOT" "$RELEASES_DIR" "$RELEASE_DIR"')
        ->and($body)->toContain('setfacl -R -P -m u:caddy:rX "$RELEASE_DIR/public"')
        ->and(substr_count($body, 'setfacl '))->toBe(2)
        ->and($body)->not->toContain('setfacl -R -d')
        ->and($body)->not->toMatch('/usermod\s+-aG\s+coresmith\s+caddy/');
});

test('an empty saved route config never replaces the panel config when caddy starts', function () {
    $body = installScriptBody();

    expect($body)->toContain('grep -qs "\"apps\"" /etc/caddy/caddy-config.json && curl -fsS --unix-socket /run/caddy-admin/admin.sock -X POST http://localhost/load')
        ->and($body)->not->toContain("echo '{}' >/etc/caddy/caddy-config.json");
});

test("Coresmith's Caddy admin API is a socket in a folder only Caddy, root and the coresmith group can enter", function () {
    $body = installScriptBody();

    expect($body)->toContain('readonly CADDY_ADMIN_DIR="/run/caddy-admin"')
        ->and($body)->toContain('readonly CADDY_ADMIN_SOCKET="$CADDY_ADMIN_DIR/admin.sock"')
        ->and($body)->toContain('readonly CADDY_ADMIN_TMPFILES="/etc/tmpfiles.d/coresmith-caddy.conf"')
        ->and($body)->toContain("printf 'd %s 0750 caddy coresmith -\\n' \"\$CADDY_ADMIN_DIR\" >\"\$CADDY_ADMIN_TMPFILES\"")
        ->and($body)->toContain('run systemd-tmpfiles --create "$CADDY_ADMIN_TMPFILES"')
        ->and($body)->toContain("printf '    admin unix/%s|0222\\n' \"\$CADDY_ADMIN_SOCKET\"")
        ->and($body)->toContain('update_env CORESMITH_CADDY_ADMIN_SOCKET "$CADDY_ADMIN_SOCKET"')
        ->and($body)->not->toContain('localhost:2019');
});

test('the setup token is written by the panel user into a file it owns', function () {
    $body = installScriptBody();

    expect($body)->toContain('install -o coresmith -g coresmith -m 600 /dev/null "$SETUP_TOKEN_FILE"')
        ->and($body)->toContain('coresmith:setup-token --force 2>&1)" || true')
        ->and($body)->toContain("grep -q 'already has an account'")
        ->and($body)->toContain('Coresmith is already set up. Sign in at');
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
    $script = 'set -euo pipefail' . "\n" . 'SHARED_DIR=' . escapeshellarg($directory) . "\nlog() { :; }\n"
        . installScriptFunctions('env_value', 'vault_key_is_valid', 'refuse_link', 'ensure_env_secret', 'update_env', 'adopt_existing_install', 'ensure_database_password')
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

test('a secret Laravel reads as empty, like the null the example environment ships, is generated', function (string $placeholder) {
    $directory = installScriptScratch();
    file_put_contents($directory . '/.env', "REDIS_PASSWORD={$placeholder}\nKEEP=me\n");

    $process = runInstallScriptFunctions($directory, "ensure_env_secret REDIS_PASSWORD generated\n");
    $result = (string) file_get_contents($directory . '/.env');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toContain('REDIS_PASSWORD=generated')
        ->and($result)->toContain('KEEP=me');
})->with(['null', '(null)', 'empty', '(empty)', '""']);

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
        ->and($body)->toContain("preflight\n    refuse_linked_layout\n    adopt_legacy_layout\n    adopt_existing_install\n    interactive_prompts\n    ensure_database_password");
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
        ->and($body)->toContain('through NAT. Without --domain Coresmith answers on');
});

test('the installer announces the version in the VERSION file', function () {
    expect(installScriptBody())->toContain('readonly CORESMITH_VERSION="' . trim((string) file_get_contents(base_path('VERSION'))) . '"');
});

function installScriptUnits(): string
{
    preg_match('/^create_systemd_services\(\) \{.*?^\}$/ms', installScriptBody(), $matches);

    return $matches[0] ?? '';
}

test('php-fpm never reaches the release through the symlink', function () {
    $body = installScriptBody();

    expect($body)->toContain('php_admin_value[open_basedir] = ${RELEASES_DIR}:${SHARED_DIR}:${DATA_DIR}:/tmp')
        ->and(preg_match('/open_basedir.*\$\{INSTALL_DIR\}/', $body))->toBe(0);
});

test('the installer lays out the paths the updater reads', function () {
    $body = installScriptBody();
    $config = (string) file_get_contents(config_path('coresmith.php'));

    expect($body)->toContain('readonly CORESMITH_ROOT="/opt/coresmith"')
        ->and($body)->toContain('readonly RELEASES_DIR="$CORESMITH_ROOT/releases"')
        ->and($body)->toContain('readonly SHARED_DIR="$CORESMITH_ROOT/shared"')
        ->and($body)->toContain('readonly INSTALL_DIR="$CORESMITH_ROOT/app"')
        ->and($body)->toContain('readonly DATA_DIR="/var/lib/coresmith"')
        ->and($body)->toContain('readonly UPDATES_DIR="$DATA_DIR/updates"')
        ->and($body)->toContain('update_env CORESMITH_UPDATES_ENABLED "true"')
        ->and($body)->toContain('update_env CORESMITH_REPOSITORY "$CORESMITH_REPO"')
        ->and($body)->toContain(Release::SENTINEL)
        ->and($body)->toContain('name="${version:-0.0.0}-${commit:0:7}"')
        ->and($body)->toContain('chmod 700 "$UPDATES_DIR"')
        ->and($config)->toContain("env('CORESMITH_ROOT', '/opt/coresmith')")
        ->and($config)->toContain("env('CORESMITH_UPDATE_STATE_PATH', '/var/lib/coresmith/updates')");
});

test('the services the updater restarts are the ones the installer creates', function () {
    $units = installScriptUnits();

    expect($units)->toContain('/etc/systemd/system/' . PanelServices::HORIZON . '.service')
        ->and($units)->toContain('/etc/systemd/system/' . PanelServices::REVERB . '.service');
});

test('the update unit runs as the panel user and takes its request before it starts', function () {
    $units = installScriptUnits();
    preg_match('/coresmith-update\.service <<UNIT\n(.*?)\nUNIT/s', $units, $service);
    preg_match('/coresmith-update\.path <<UNIT\n(.*?)\nUNIT/s', $units, $path);
    $service = $service[1] ?? '';

    expect($service)->toContain('User=coresmith')
        ->and($service)->toContain('Type=oneshot')
        ->and($service)->toContain('WorkingDirectory=${INSTALL_DIR}')
        ->and($service)->toContain('ExecStartPre=-/bin/mv -f ${UPDATES_DIR}/request.json ${UPDATES_DIR}/request.taken.json')
        ->and($service)->toContain('ExecStart=/usr/bin/php artisan coresmith:update --requested --no-interaction')
        ->and($service)->toContain('TimeoutStartSec=2h')
        ->and($service)->toContain('NoNewPrivileges=true')
        ->and($service)->toContain('ProtectSystem=full')
        ->and($service)->not->toContain('ProtectSystem=strict')
        ->and($path[1] ?? '')->toContain('PathExists=${UPDATES_DIR}/request.json')
        ->and($path[1] ?? '')->toContain('Unit=coresmith-update.service')
        ->and(installScriptBody())->toContain('run systemctl enable --now coresmith-update.path');
});

test('horizon and reverb write to the shared storage every release links to', function () {
    $units = installScriptUnits();

    expect(substr_count($units, 'ReadWritePaths=${SHARED_DIR}/storage'))->toBe(2)
        ->and($units)->not->toContain('ReadWritePaths=${INSTALL_DIR}/storage');
});

test('--version and --branch choose what is installed, and not both', function () {
    $both = runInstaller(['--version', '1.0.0', '--branch', 'main']);
    $junk = runInstaller(['--version', 'latest']);
    $help = runInstaller(['--help']);

    expect($both->getExitCode())->toBe(1)
        ->and($both->getErrorOutput() . $both->getOutput())->toContain('Pass --version or --branch, not both.')
        ->and($junk->getExitCode())->toBe(1)
        ->and($junk->getErrorOutput() . $junk->getOutput())->toContain('"latest" is not a version like 1.2.3.')
        ->and($help->getOutput())->toContain('--version <x.y.z>')
        ->and($help->getOutput())->toContain('v' . trim((string) file_get_contents(base_path('VERSION'))))
        ->and($help->getOutput())->not->toContain('$CORESMITH_VERSION');
});

test('by default the installer installs the release it belongs to', function () {
    expect(installScriptBody())->toContain('"${CORESMITH_BRANCH:-v${INSTALL_VERSION:-$CORESMITH_VERSION}}"')
        ->and(installScriptBody())->toContain('CORESMITH_BRANCH=""');
});

function installScriptLegacyTree(string $version = "0.1.0\n"): array
{
    $base = realpath(sys_get_temp_dir()) . '/csl-' . bin2hex(random_bytes(4));
    $app = "{$base}/root/app";
    mkdir("{$app}/storage/app", 0755, true);
    file_put_contents("{$base}/gitconfig", '');
    file_put_contents("{$app}/VERSION", $version);
    file_put_contents("{$app}/.env", "APP_KEY=base64:keep\n");
    file_put_contents("{$app}/storage/app/kept.txt", 'kept');
    $env = ['GIT_CONFIG_GLOBAL' => "{$base}/gitconfig", 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_NAME' => 'Coresmith', 'GIT_AUTHOR_EMAIL' => 'test@coresmith.test', 'GIT_COMMITTER_NAME' => 'Coresmith', 'GIT_COMMITTER_EMAIL' => 'test@coresmith.test'];

    foreach ([['git', 'init', '--quiet'], ['git', 'add', 'VERSION'], ['git', 'commit', '--quiet', '-m', 'Release'], ['git', 'tag', 'v0.1.0']] as $command) {
        (new Process($command, $app, $env))->mustRun();
    }

    return [$base, $env, trim((new Process(['git', 'rev-parse', 'HEAD'], $app, $env))->mustRun()->getOutput())];
}

function installScriptLegacyConvert(string $base, array $env, string $before = ''): Process
{
    $functions = installScriptFunctions('refuse_link', 'require_release_ref', 'valid_release_name', 'name_release', 'write_release_sentinel', 'switch_release', 'adopt_legacy_layout');
    $script = 'set -euo pipefail' . "\n"
        . 'CORESMITH_ROOT=' . escapeshellarg("{$base}/root") . "\n"
        . 'RELEASES_DIR="$CORESMITH_ROOT/releases"; SHARED_DIR="$CORESMITH_ROOT/shared"; INSTALL_DIR="$CORESMITH_ROOT/app"; RELEASE_DIR=""; PREVIOUS_RELEASE=""' . "\n"
        . 'CORESMITH_REPO="$INSTALL_DIR"; CORESMITH_BRANCH=""; INSTALL_VERSION=""; CORESMITH_VERSION="0.1.0"' . "\n"
        . "log() { printf '%s\\n' \"\$1\"; }\nsuccess() { :; }\nchown() { :; }\ntrust_repository() { :; }\n"
        . "fatal() { printf '%s\\n' \"\$1\" >&2; exit 1; }\n"
        . 'sudo() { if [ "$1" = "-u" ]; then shift 2; fi; "$@"; }' . "\n"
        . 'mv() { if [ "$1" = "-Tf" ]; then shift; command mv -f "$@"; else command mv "$@"; fi; }' . "\n"
        . 'git() { if [ "${3:-}" = rev-parse ] && [ "${4:-}" = --short=7 ]; then command git -C "$2" rev-parse HEAD | cut -c1-9; else command git "$@"; fi; }' . "\n"
        . "{$functions}\n{$before}\nadopt_legacy_layout\n";

    $process = new Process(['bash', '-c', $script], null, $env);
    $process->run();

    return $process;
}

function installScriptLegacyKept(string $base): bool
{
    return is_dir("{$base}/root/app") && !is_link("{$base}/root/app")
        && file_get_contents("{$base}/root/app/.env") === "APP_KEY=base64:keep\n"
        && file_get_contents("{$base}/root/app/storage/app/kept.txt") === 'kept'
        && !file_exists("{$base}/root/releases");
}

test('an install from before release directories is moved into them with its settings', function () {
    [$base, $env, $commit] = installScriptLegacyTree();

    $process = installScriptLegacyConvert($base, $env);

    $release = "{$base}/root/releases/0.1.0-" . substr($commit, 0, 7);
    $sentinel = json_decode((string) @file_get_contents("{$release}/.coresmith-release.json"), true);
    $link = @readlink("{$base}/root/app");

    try {
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($link)->toBe($release)
            ->and(file_get_contents("{$base}/root/shared/.env"))->toBe("APP_KEY=base64:keep\n")
            ->and(file_get_contents("{$base}/root/shared/storage/app/kept.txt"))->toBe('kept')
            ->and(readlink("{$release}/storage"))->toBe("{$base}/root/shared/storage")
            ->and(readlink("{$release}/.env"))->toBe("{$base}/root/shared/.env")
            ->and($sentinel['built_by'] ?? null)->toBe('installer')
            ->and($sentinel['version'] ?? null)->toBe('0.1.0')
            ->and(is_array($sentinel) && array_key_exists('tag', $sentinel) && $sentinel['tag'] === null)->toBeTrue()
            ->and(Release::fromDirectory($release)?->name)->toBe('0.1.0-' . substr($commit, 0, 7))
            ->and($process->getOutput())->toContain("Moved the existing install into {$release}; its settings and data are kept.");
    } finally {
        (new Process(['rm', '-rf', $base]))->run();
    }
});

test('an old install is not converted while the release it would move to cannot be fetched', function (string $before, string $error) {
    [$base, $env] = installScriptLegacyTree();

    $process = installScriptLegacyConvert($base, $env, $before);

    try {
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe(str_replace('{base}', $base, $error) . "\n")
            ->and(installScriptLegacyKept($base))->toBeTrue()
            ->and(file_exists("{$base}/root/shared"))->toBeFalse();
    } finally {
        (new Process(['rm', '-rf', $base]))->run();
    }
})->with([
    'a release the repository does not have' => ['CORESMITH_VERSION="9.9.9"', '{base}/root/app has no v9.9.9, so nothing was changed. Pass --version <x.y.z> for a release it has, or --branch <name> for a branch.'],
    'a branch the repository does not have' => ['CORESMITH_BRANCH="nightly"', '{base}/root/app has no nightly, so nothing was changed. Pass --version <x.y.z> for a release it has, or --branch <name> for a branch.'],
    'a repository that cannot be reached' => ['CORESMITH_REPO=/nonexistent/coresmith.git', 'Could not reach /nonexistent/coresmith.git, so nothing was changed. Check the repository is reachable, then run the installer again.'],
]);

test('an old install whose VERSION cannot name a release is left where it is', function () {
    [$base, $env] = installScriptLegacyTree("../../x\n");

    $process = installScriptLegacyConvert($base, $env);

    try {
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe("The install in {$base}/root/app has a VERSION Coresmith can't name a release after. Put a version like 1.2.3 in {$base}/root/app/VERSION, then run the installer again.\n")
            ->and(installScriptLegacyKept($base))->toBeTrue()
            ->and(glob("{$base}/x-*"))->toBe([]);
    } finally {
        (new Process(['rm', '-rf', $base]))->run();
    }
});

test('an old install is not converted into a linked shared directory', function () {
    [$base, $env] = installScriptLegacyTree();
    mkdir("{$base}/victim");
    symlink("{$base}/victim", "{$base}/root/shared");

    $process = installScriptLegacyConvert($base, $env);

    try {
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe("{$base}/root/shared is a symbolic link, so the installer stopped rather than follow it as root. Put the real file or directory back, then run the installer again.\n")
            ->and(installScriptLegacyKept($base))->toBeTrue()
            ->and(scandir("{$base}/victim"))->toBe(['.', '..']);
    } finally {
        (new Process(['rm', '-rf', $base]))->run();
    }
});

test('an install that already uses release directories is left as it is', function () {
    $functions = installScriptFunctions('adopt_legacy_layout');
    $script = 'set -euo pipefail' . "\n" . 'INSTALL_DIR=/nonexistent/coresmith/app' . "\n{$functions}\nadopt_legacy_layout && echo untouched\n";
    $process = new Process(['bash', '-c', $script]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("untouched\n");
});

function installScriptScratch(): string
{
    $base = realpath(sys_get_temp_dir()) . '/csk-' . bin2hex(random_bytes(4));
    mkdir($base, 0700, true);

    return $base;
}

function installScriptRun(string $script, ?string $input = null, ?array $env = null): Process
{
    $process = new Process(['bash', '-c', "set -euo pipefail\nfatal() { printf '%s\\n' \"\$1\" >&2; exit 1; }\nlog() { printf '%s\\n' \"\$1\"; }\nsuccess() { :; }\n{$script}"], null, $env, $input);
    $process->run();

    return $process;
}

test('a release is named after its version and the first seven characters of its commit', function (string $version, ?string $name) {
    $base = installScriptScratch();
    file_put_contents("{$base}/VERSION", $version);
    $env = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_NAME' => 'Coresmith', 'GIT_AUTHOR_EMAIL' => 'test@coresmith.test', 'GIT_COMMITTER_NAME' => 'Coresmith', 'GIT_COMMITTER_EMAIL' => 'test@coresmith.test'];

    foreach ([['git', 'init', '--quiet'], ['git', 'add', 'VERSION'], ['git', 'commit', '--quiet', '-m', 'Release']] as $command) {
        (new Process($command, $base, $env))->mustRun();
    }

    $commit = trim((new Process(['git', 'rev-parse', 'HEAD'], $base, $env))->mustRun()->getOutput());
    $script = 'RELEASES_DIR=/releases; RELEASE_DIR=""' . "\n"
        . 'sudo() { if [ "$1" = "-u" ]; then shift 2; fi; "$@"; }' . "\n"
        . installScriptFunctions('valid_release_name', 'name_release') . "\n"
        . 'if name_release ' . escapeshellarg($base) . '; then printf "%s" "$RELEASE_DIR"; else printf "refused"; fi';
    $process = new Process(['bash', '-c', $script], null, $env);
    $process->run();

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getOutput())->toBe($name === null ? 'refused' : '/releases/' . str_replace('{commit}', substr($commit, 0, 7), $name));
})->with([
    'a release' => ['1.2.3', '1.2.3-{commit}'],
    'a pre-release' => ['1.2.3-beta.1', '1.2.3-beta.1-{commit}'],
    'a trailing newline' => ["1.2.3\n", '1.2.3-{commit}'],
    'no version at all' => ['', '0.0.0-{commit}'],
    'a path' => ['../../etc', null],
    'two numbers' => ['1.2', null],
    'a leading v' => ['v1.2.3', null],
]);

test('the installer accepts exactly the release names the updater can find', function () {
    $names = ['1.2.3-abcdef0', '1.2.3-beta.1-abcdef0', '0.0.0-0000000', '10.20.30-rc.1.x-0123456', '1.2.3-abcdef', '1.2.3-abcdef01', '1.2.3-ABCDEF0', '01.2.3-abcdef0', '1.2-abcdef0', '1.2.3--abcdef0', '1.2.3-..-abcdef0', '../1.2.3-abcdef0', '1.2.3-abcdef0/..', "1.2.3-abcdef0\nx", '1.2.3-a_b-abcdef0', ' 1.2.3-abcdef0'];
    $script = installScriptFunctions('valid_release_name') . "\n" . 'for name in "$@"; do if valid_release_name "$name"; then printf 1; else printf 0; fi; done';
    $process = new Process(['bash', '-c', $script, 'bash', ...$names]);
    $process->mustRun();

    expect(str_split($process->getOutput()))->toBe(array_map(fn(string $name): string => preg_match(ReleaseLayout::NAME, $name) === 1 ? '1' : '0', $names));
});

test('a fetched release is named and checked before anything is removed or moved for it', function () {
    $code = installScriptFunctions('install_coresmith_app');
    $named = strpos($code, 'name_release "$incoming" || {');

    expect($named)->not->toBeFalse()
        ->and($named)->toBeLessThan(strpos($code, 'rm -rf "$RELEASE_DIR"'))
        ->and($code)->not->toContain('--short=7')
        ->and(installScriptBody())->not->toContain('--short=7');
});

test('a fetched release whose VERSION cannot name it is removed again before the install stops', function () {
    $base = installScriptScratch();
    $env = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_NAME' => 'Coresmith', 'GIT_AUTHOR_EMAIL' => 'test@coresmith.test', 'GIT_COMMITTER_NAME' => 'Coresmith', 'GIT_COMMITTER_EMAIL' => 'test@coresmith.test'];
    mkdir("{$base}/origin");
    mkdir("{$base}/releases");
    file_put_contents("{$base}/origin/VERSION", "next\n");

    foreach ([['git', 'init', '--quiet', '--initial-branch=main'], ['git', 'add', 'VERSION'], ['git', 'commit', '--quiet', '-m', 'Next']] as $command) {
        (new Process($command, "{$base}/origin", $env))->mustRun();
    }

    $script = 'CORESMITH_REPO=' . escapeshellarg("file://{$base}/origin") . '; CORESMITH_BRANCH=main; INSTALL_VERSION=""; CORESMITH_VERSION=0.1.0; RELEASES_DIR=' . escapeshellarg("{$base}/releases") . '; SHARED_DIR=' . escapeshellarg("{$base}/shared") . '; RELEASE_DIR=""' . "\n"
        . "trust_repository() { :; }\n" . 'sudo() { if [ "$1" = "-u" ]; then shift 2; fi; "$@"; }' . "\n"
        . installScriptFunctions('valid_release_name', 'name_release', 'install_coresmith_app') . "\ninstall_coresmith_app\n";

    $process = installScriptRun($script, env: $env);
    $left = scandir("{$base}/releases");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain("The VERSION file in main isn't a version like 1.2.3, so Coresmith can't name the release. Install a release with --version <x.y.z>, or fix VERSION on that branch, then run the installer again.\n")
        ->and($left)->toBe(['.', '..']);
});

function installScriptLinkedLayout(string $base): string
{
    return 'CORESMITH_ROOT=' . escapeshellarg("{$base}/opt/coresmith") . '; DATA_DIR=' . escapeshellarg("{$base}/var/lib/coresmith") . '; APPS_DIR=' . escapeshellarg("{$base}/var/coresmith") . '; CONFIG_DIR=' . escapeshellarg("{$base}/etc/coresmith") . "\n"
        . 'RELEASES_DIR="$CORESMITH_ROOT/releases"; SHARED_DIR="$CORESMITH_ROOT/shared"; UPDATES_DIR="$DATA_DIR/updates"; ADDRESS_DIR="$DATA_DIR/address"; SETUP_TOKEN_FILE="$DATA_DIR/setup-token"' . "\n";
}

test('a link where the installer changes things as root stops it before anything changes', function (string $path) {
    $base = installScriptScratch();
    mkdir("{$base}/victim");
    @mkdir(dirname("{$base}/{$path}"), 0755, true);
    symlink("{$base}/victim", "{$base}/{$path}");

    $process = installScriptRun(installScriptLinkedLayout($base) . installScriptFunctions('refuse_link', 'refuse_linked_layout') . "\nrefuse_linked_layout\necho carried on\n");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toBe("{$base}/{$path} is a symbolic link, so the installer stopped rather than follow it as root. Put the real file or directory back, then run the installer again.\n")
        ->and($process->getOutput())->toBe('');
})->with([
    'releases' => ['opt/coresmith/releases'],
    'shared' => ['opt/coresmith/shared'],
    'the environment file' => ['opt/coresmith/shared/.env'],
    'updates' => ['var/lib/coresmith/updates'],
    'address requests' => ['var/lib/coresmith/address'],
    'the database' => ['var/lib/coresmith/database.sqlite'],
    'the setup token' => ['var/lib/coresmith/setup-token'],
]);

test('a layout without links carries on', function () {
    $base = installScriptScratch();
    mkdir("{$base}/opt/coresmith/shared", 0755, true);
    mkdir("{$base}/var/lib/coresmith/updates", 0755, true);
    touch("{$base}/opt/coresmith/shared/.env");

    $process = installScriptRun(installScriptLinkedLayout($base) . installScriptFunctions('refuse_link', 'refuse_linked_layout') . "\nrefuse_linked_layout\necho carried on\n");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("carried on\n");
});

test('creating the directories changes no owner or mode through a link', function () {
    $base = installScriptScratch();
    mkdir("{$base}/victim");
    mkdir("{$base}/var/lib/coresmith", 0755, true);
    symlink("{$base}/victim", "{$base}/var/lib/coresmith/updates");
    $calls = escapeshellarg("{$base}/calls.log");

    $process = installScriptRun(installScriptLinkedLayout($base)
        . "id() { return 0; }\nuseradd() { :; }\n"
        . "mkdir() { printf 'mkdir %s\\n' \"\$*\" >>{$calls}; }\nchown() { printf 'chown %s\\n' \"\$*\" >>{$calls}; }\nchmod() { printf 'chmod %s\\n' \"\$*\" >>{$calls}; }\n"
        . installScriptFunctions('refuse_link', 'create_user_and_dirs') . "\ncreate_user_and_dirs\n");
    $made = @file_get_contents("{$base}/calls.log");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain("{$base}/var/lib/coresmith/updates is a symbolic link")
        ->and($made)->toBeFalse();
});

test('the environment is never written through a link', function () {
    $base = installScriptScratch();
    mkdir("{$base}/shared");
    file_put_contents("{$base}/victim", "SECRET=keep\n");
    symlink("{$base}/victim", "{$base}/shared/.env");

    $process = installScriptRun('SHARED_DIR=' . escapeshellarg("{$base}/shared") . "\n" . installScriptFunctions('refuse_link', 'update_env') . "\nupdate_env APP_ENV production\n");
    $victim = file_get_contents("{$base}/victim");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain("{$base}/shared/.env is a symbolic link")
        ->and($victim)->toBe("SECRET=keep\n");
});

test('the environment is replaced by a file written beside it, never rewritten in place', function () {
    $base = installScriptScratch();
    mkdir("{$base}/shared");
    file_put_contents("{$base}/shared/.env", "APP_ENV=local\nKEEP=me\n");
    link("{$base}/shared/.env", "{$base}/before");

    $moves = escapeshellarg("{$base}/moves.log");
    $process = installScriptRun('SHARED_DIR=' . escapeshellarg("{$base}/shared") . "\n" . "mv() { printf '%s\\n' \"\$*\" >>{$moves}; command mv \"\$@\"; }\n" . installScriptFunctions('refuse_link', 'update_env') . "\nupdate_env APP_ENV production\n");
    $env = file_get_contents("{$base}/shared/.env");
    $before = file_get_contents("{$base}/before");
    $left = scandir("{$base}/shared");
    $moved = file_get_contents("{$base}/moves.log");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($env)->toBe("APP_ENV=production\nKEEP=me\n")
        ->and($before)->toBe("APP_ENV=local\nKEEP=me\n")
        ->and($left)->toBe(['.', '..', '.env'])
        ->and($moved)->toMatch('#^-f ' . preg_quote("{$base}/shared/.env.", '#') . '[A-Za-z0-9]{6} ' . preg_quote("{$base}/shared/.env", '#') . '\n$#');
});

test('root changes owners and modes only on paths it has just checked are not links', function () {
    foreach ([
        ['adopt_legacy_layout', 'refuse_link "$RELEASES_DIR" "$SHARED_DIR"', 'chown coresmith:coresmith "$RELEASES_DIR" "$SHARED_DIR"'],
        ['create_user_and_dirs', 'refuse_link "$RELEASES_DIR" "$SHARED_DIR" "$UPDATES_DIR" "$ADDRESS_DIR"', 'chown coresmith:coresmith "$CORESMITH_ROOT" "$RELEASES_DIR" "$SHARED_DIR" "$UPDATES_DIR" "$ADDRESS_DIR"'],
        ['create_user_and_dirs', 'refuse_link "$RELEASES_DIR" "$SHARED_DIR" "$UPDATES_DIR" "$ADDRESS_DIR"', 'chmod 700 "$UPDATES_DIR" "$ADDRESS_DIR"'],
        ['configure_environment', 'refuse_link "$DATA_DIR/database.sqlite"', 'chown coresmith:coresmith "$DATA_DIR/database.sqlite"'],
        ['configure_environment', 'refuse_link "$SHARED_DIR/.env"', 'chown coresmith:coresmith "$SHARED_DIR/.env"'],
        ['finalize', 'refuse_link "$SETUP_TOKEN_FILE"', 'install -o coresmith -g coresmith -m 600 /dev/null "$SETUP_TOKEN_FILE"'],
    ] as [$function, $check, $change]) {
        $code = installScriptFunctions($function);

        expect($code)->toContain($check)
            ->and($code)->toContain($change)
            ->and(strpos($code, $check))->toBeLessThan(strpos($code, $change));
    }
});

function installScriptReleases(string $base): string
{
    foreach (['0.0.9-ddddddd', '0.1.0-aaaaaaa', '0.2.0-bbbbbbb', '0.3.0-ccccccc', '.incoming-4242'] as $name) {
        mkdir("{$base}/releases/{$name}/public", 0755, true);
    }

    mkdir("{$base}/outside");
    file_put_contents("{$base}/outside/kept.txt", 'kept');
    file_put_contents("{$base}/releases/notes.txt", 'notes');
    symlink("{$base}/outside", "{$base}/releases/outside");

    return 'RELEASES_DIR=' . escapeshellarg("{$base}/releases") . "\n";
}

test('a run that switched releases removes every other release but the one it replaced', function () {
    $base = installScriptScratch();
    $script = installScriptReleases($base) . 'RELEASE_DIR="$RELEASES_DIR/0.3.0-ccccccc"; PREVIOUS_RELEASE="$RELEASES_DIR/0.2.0-bbbbbbb"' . "\n" . installScriptFunctions('prune_releases') . "\nprune_releases\n";

    $process = installScriptRun($script);
    $left = scandir("{$base}/releases");
    $outside = file_get_contents("{$base}/outside/kept.txt");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($left)->toBe(['.', '..', '0.2.0-bbbbbbb', '0.3.0-ccccccc', 'notes.txt', 'outside'])
        ->and($outside)->toBe('kept')
        ->and($process->getOutput())->toBe("Removed old release 0.0.9-ddddddd\nRemoved old release 0.1.0-aaaaaaa\nRemoved old release .incoming-4242\n");
});

test('a run that did not switch releases removes nothing', function (string $previous) {
    $base = installScriptScratch();
    $script = installScriptReleases($base) . 'RELEASE_DIR="$RELEASES_DIR/0.3.0-ccccccc"; PREVIOUS_RELEASE=' . $previous . "\n" . installScriptFunctions('prune_releases') . "\nprune_releases\n";

    $process = installScriptRun($script);
    $left = scandir("{$base}/releases");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($left)->toBe(['.', '..', '.incoming-4242', '0.0.9-ddddddd', '0.1.0-aaaaaaa', '0.2.0-bbbbbbb', '0.3.0-ccccccc', 'notes.txt', 'outside']);
})->with([
    'the same release' => ['"$RELEASE_DIR"'],
    'a first install' => ['""'],
]);

test('switching releases remembers the one it replaced', function () {
    $base = installScriptScratch();
    mkdir("{$base}/releases/0.1.0-aaaaaaa", 0755, true);
    mkdir("{$base}/releases/0.2.0-bbbbbbb", 0755, true);
    $script = 'CORESMITH_ROOT=' . escapeshellarg($base) . '; INSTALL_DIR="$CORESMITH_ROOT/app"; RELEASES_DIR="$CORESMITH_ROOT/releases"; PREVIOUS_RELEASE=""' . "\n"
        . "chown() { :; }\n" . 'mv() { if [ "$1" != "-Tf" ]; then command mv "$@"; elif shift && command mv --version >/dev/null 2>&1; then command mv -Tf "$@"; else command mv -hf "$@"; fi; }' . "\n"
        . installScriptFunctions('switch_release') . "\n"
        . 'RELEASE_DIR="$RELEASES_DIR/0.1.0-aaaaaaa"; switch_release; printf "[%s]" "$PREVIOUS_RELEASE"' . "\n"
        . 'RELEASE_DIR="$RELEASES_DIR/0.2.0-bbbbbbb"; switch_release; printf "[%s]" "$PREVIOUS_RELEASE"' . "\n";

    $process = installScriptRun($script);
    $link = readlink("{$base}/app");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("[][{$base}/releases/0.1.0-aaaaaaa]")
        ->and($link)->toBe("{$base}/releases/0.2.0-bbbbbbb");
});

test('old releases are removed only once the services are up on the new one', function () {
    expect(installScriptFunctions('finalize'))->toContain("    wait_for_service coresmith-reverb || healthy=false\n\n    if \$healthy; then\n        prune_releases\n    fi\n");
});

test('the installer workflow runs whenever a file the machine test depends on changes', function () {
    $on = Yaml::parseFile(base_path('.github/workflows/installer.yml'))['on'];

    foreach (['config/coresmith.php', 'bootstrap/app.php', 'routes/api.php', 'routes/console.php', 'app/Http/Controllers/ReleaseProbeController.php'] as $path) {
        expect(is_file(base_path($path)))->toBeTrue("{$path} does not exist")
            ->and($on['push']['paths'])->toContain($path)
            ->and($on['pull_request']['paths'])->toContain($path);
    }

    expect($on['push']['paths'])->toBe($on['pull_request']['paths']);
});

function installScriptGitRelease(string $base, string $version): void
{
    $env = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_NAME' => 'Coresmith', 'GIT_AUTHOR_EMAIL' => 'test@coresmith.test', 'GIT_COMMITTER_NAME' => 'Coresmith', 'GIT_COMMITTER_EMAIL' => 'test@coresmith.test'];
    file_put_contents("{$base}/VERSION", $version);

    foreach ([['git', 'init', '--quiet'], ['git', 'add', 'VERSION'], ['git', 'commit', '--quiet', '-m', 'Release']] as $command) {
        (new Process($command, $base, $env))->mustRun();
    }
}

test('a sealed release records the version its name was checked with, never a second read', function () {
    $base = installScriptScratch();
    mkdir("{$base}/release");
    installScriptGitRelease("{$base}/release", "1.2.3\n");
    file_put_contents("{$base}/secret", 'root-only-secret');

    $script = 'RELEASES_DIR=' . escapeshellarg($base) . '; RELEASE_DIR=""' . "\n"
        . 'sudo() { if [ "$1" = "-u" ]; then shift 2; fi; "$@"; }' . "\n"
        . installScriptFunctions('valid_release_name', 'name_release', 'write_release_sentinel') . "\n"
        . 'name_release ' . escapeshellarg("{$base}/release") . "\n"
        . 'rm ' . escapeshellarg("{$base}/release/VERSION") . '; ln -s ' . escapeshellarg("{$base}/secret") . ' ' . escapeshellarg("{$base}/release/VERSION") . "\n"
        . 'write_release_sentinel ' . escapeshellarg("{$base}/release") . ' ""' . "\n";

    $process = installScriptRun($script, env: ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']);
    $sentinel = (string) @file_get_contents("{$base}/release/.coresmith-release.json");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(json_decode($sentinel, true)['version'] ?? null)->toBe('1.2.3')
        ->and($sentinel)->not->toContain('root-only-secret');
});

test('the installer reads a release VERSION as the panel user, never as root', function () {
    $base = installScriptScratch();
    installScriptGitRelease($base, "1.2.3\n");
    $calls = escapeshellarg("{$base}/sudo.log");

    $script = 'RELEASES_DIR=/releases; RELEASE_DIR=""' . "\n"
        . "sudo() { printf '%s\\n' \"\$*\" >>{$calls}; if [ \"\$1\" = \"-u\" ]; then shift 2; fi; \"\$@\"; }\n"
        . installScriptFunctions('valid_release_name', 'name_release') . "\n"
        . 'name_release ' . escapeshellarg($base) . "\n";

    $process = installScriptRun($script, env: ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']);
    $sudo = (string) @file_get_contents("{$base}/sudo.log");

    (new Process(['rm', '-rf', $base]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($sudo)->toContain("-u coresmith cat {$base}/VERSION\n")
        ->and(preg_match('#<\s*"\$[A-Za-z0-9_{}]*/VERSION"#', installScriptBody()))->toBe(0);
});

function installScriptRedisRun(string $directory, string $config, string $answer): Process
{
    $script = "set -euo pipefail\n"
        . 'SHARED_DIR=' . escapeshellarg($directory) . "\n"
        . 'REDIS_CONFIGS=' . escapeshellarg("{$directory}/missing.conf {$config}") . "\n"
        . "log() { :; }\nrun() { \"\$@\"; }\nfatal() { echo \"fatal: \$*\"; exit 1; }\nsleep() { :; }\n"
        . 'systemctl() { echo "systemctl $*" >>' . escapeshellarg("{$directory}/calls") . "; }\n"
        . 'redis-cli() { if [ "${REDISCLI_AUTH:-}" = ' . escapeshellarg($answer) . " ]; then echo PONG; fi; }\n"
        . installScriptFunctions('env_value', 'refuse_link', 'secure_redis')
        . "\nsecure_redis\nsecure_redis\n";

    $process = new Process(['bash', '-c', $script]);
    $process->run();

    return $process;
}

test("Coresmith's own Redis gets a password before the installer's commands use it", function () {
    $body = installScriptBody();

    expect($body)->toContain('readonly REDIS_CONFIGS="/etc/redis/redis.conf /etc/redis.conf"')
        ->and($body)->toContain('ensure_env_secret REDIS_PASSWORD "$(openssl rand -hex 32)"')
        ->and(strpos($body, 'ensure_env_secret REDIS_PASSWORD'))->toBeLessThan(strpos($body, "\n    secure_redis\n"))
        ->and(strpos($body, "\n    secure_redis\n"))->toBeLessThan(strpos($body, 'php artisan migrate --force'));
});

test('setting Redis password replaces any requirepass line, keeps the rest of its config, and waits for Redis to accept it', function () {
    $directory = installScriptScratch();
    $config = "{$directory}/redis.conf";
    file_put_contents($config, "bind 127.0.0.1 -::1\n# requirepass foobared\n  requirepass an-old-one\nport 6379\n");
    file_put_contents("{$directory}/.env", "APP_ENV=production\nREDIS_PASSWORD=Gener4tedRedisPassw0rd\n");

    $process = installScriptRedisRun($directory, $config, 'Gener4tedRedisPassw0rd');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and(file_get_contents($config))->toBe("bind 127.0.0.1 -::1\nport 6379\nrequirepass Gener4tedRedisPassw0rd\n")
        ->and(file_get_contents("{$directory}/calls"))->toContain('systemctl restart redis-server');
});

test('a Redis that never accepts the new password stops the install and says where to look', function () {
    $directory = installScriptScratch();
    $config = "{$directory}/redis.conf";
    file_put_contents($config, "port 6379\n");
    file_put_contents("{$directory}/.env", "REDIS_PASSWORD=Gener4tedRedisPassw0rd\n");

    $process = installScriptRedisRun($directory, $config, 'something-else');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain("fatal: Redis didn't accept Coresmith's new password. See journalctl -u redis-server or -u redis.");
});
