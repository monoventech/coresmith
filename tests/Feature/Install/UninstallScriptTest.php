<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function uninstallerPath(): string
{
    return base_path('scripts/uninstall.sh');
}

function uninstallerBody(): string
{
    return (string) file_get_contents(uninstallerPath());
}

function uninstallerTree(): string
{
    $base = realpath(sys_get_temp_dir()) . '/csx-' . bin2hex(random_bytes(4));
    $files = [
        'opt/coresmith/shared/.env' => "APP_URL=https://panel.example.test\nDB_CONNECTION=sqlite\nDB_DATABASE={$base}/var/lib/coresmith/database.sqlite\nCORESMITH_REPOSITORY=\"https://git.example.test/coresmith.git\"\n",
        'opt/coresmith/shared/storage/app/private/backups/panel-20261101.sql.gz' => 'backup',
        'opt/coresmith/releases/1.0.0-abcdef0/VERSION' => "1.0.0\n",
        'etc/systemd/system/caddy.service.d/coresmith.conf' => "[Service]\n",
        'etc/systemd/system/coresmith-app-shop-web.service' => "[Service]\n",
        'etc/caddy/Caddyfile' => "{\n    admin localhost:2019\n}\n\nimport {$base}/etc/caddy/coresmith/panel.caddy\n",
        'etc/caddy/coresmith/panel.caddy' => "panel.example.test {\n}\n",
        'etc/caddy/caddy-config.json' => '{"apps":{}}',
        'etc/tmpfiles.d/coresmith-caddy.conf' => "d /run/caddy-admin 0750 caddy coresmith -\n",
        'run/caddy-admin/admin.sock' => '',
        'etc/php/8.5/fpm/pool.d/coresmith-panel.conf' => "[coresmith-panel]\n",
        'etc/php/8.5/fpm/pool.d/www.conf' => "[www]\n",
        'etc/fail2ban/jail.d/coresmith.conf' => "[coresmith]\n",
        'etc/fail2ban/filter.d/coresmith-login.conf' => "[Definition]\n",
        'etc/coresmith/env/.keep' => '',
        'var/log/coresmith-horizon.log' => 'log',
        'var/log/coresmith-install.log' => 'log',
        'var/log/syslog' => 'log',
        'var/coresmith/apps/.keep' => '',
        'root/.keep' => '',
    ];

    foreach (['coresmith-horizon.service', 'coresmith-reverb.service', 'coresmith-scheduler.service', 'coresmith-scheduler.timer', 'coresmith-update.service', 'coresmith-update.path', 'coresmith-address.service', 'coresmith-address.path'] as $unit) {
        $files["etc/systemd/system/{$unit}"] = "[Unit]\n";
    }

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname("{$base}/{$path}"));
        file_put_contents("{$base}/{$path}", $contents);
    }

    unlink("{$base}/var/coresmith/apps/.keep");
    File::ensureDirectoryExists("{$base}/var/lib/coresmith");
    (new Process(['sqlite3', "{$base}/var/lib/coresmith/database.sqlite", "create table users (email text); insert into users values ('owner@example.test');"]))->mustRun();

    return $base;
}

function runUninstaller(string $base, array $arguments, ?string $input = null, string $stubs = '', ?Closure $rewrite = null): Process
{
    preg_match('/^set -Eeuo pipefail\n(.*?)^if \[ -t 1 \]/ms', uninstallerBody(), $settings);
    preg_match_all('/^[a-z0-9_]+\(\) \{\n.*?^\}$/ms', uninstallerBody(), $functions);

    $variables = preg_replace(['#="/#', '# /(?=[a-z])#'], ['="' . $base . '/', ' ' . $base . '/'], $settings[1]);
    $calls = escapeshellarg("{$base}/calls.log");
    $script = "set -Eeuo pipefail\n{$variables}\n"
        . "RED=''; GREEN=''; YELLOW=''; CYAN=''; NC=''\n"
        . "log() { printf '%s\\n' \"\$1\"; }\nsuccess() { printf '%s\\n' \"\$1\"; }\nwarn() { printf '%s\\n' \"\$1\"; }\n"
        . "systemctl() { printf 'systemctl %s\\n' \"\$*\" >>{$calls}; }\n"
        . "git() { printf 'git %s\\n' \"\$*\" >>{$calls}; }\n"
        . "userdel() { printf 'userdel %s\\n' \"\$*\" >>{$calls}; }\n"
        . "id() { if [ \"\$1\" = \"-u\" ]; then printf '0\\n'; fi; }\n"
        . $stubs . "\n"
        . implode("\n", $functions[0]) . "\nmain \"\$@\"\n";

    $process = new Process(['bash', '-c', $rewrite === null ? $script : $rewrite($script), 'uninstall.sh', ...$arguments], null, null, $input);
    $process->run();

    return $process;
}

function uninstallerCalls(string $base): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents("{$base}/calls.log")), fn(string $line) => $line !== ''));
}

function uninstallerSaved(string $base): ?string
{
    return glob("{$base}/root/coresmith-uninstalled-*")[0] ?? null;
}

afterEach(function () {
    foreach (glob(realpath(sys_get_temp_dir()) . '/csx-*') ?: [] as $base) {
        File::deleteDirectory($base);
    }
});

test('the uninstaller exists and is executable', function () {
    expect(is_executable(uninstallerPath()))->toBeTrue()
        ->and(uninstallerBody())->toStartWith("#!/usr/bin/env bash\n# Copyright 2026 Monoven Ltd. All rights reserved.\n");
});

test('the uninstaller is valid bash', function () {
    $process = new Process(['/bin/bash', '-n', uninstallerPath()]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

test('the uninstaller passes shellcheck', function () {
    if ((new ExecutableFinder)->find('shellcheck') === null) {
        $this->markTestSkipped('shellcheck is not installed on this machine; CI installs it.');
    }

    $process = new Process(['shellcheck', '-S', 'style', uninstallerPath()]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput());
});

test('help is printed without touching the machine', function () {
    $process = new Process(['bash', uninstallerPath(), '--help']);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('--yes')
        ->and($process->getOutput())->toContain('--purge');
});

test('it refuses an option it does not understand', function () {
    $process = new Process(['bash', uninstallerPath(), '--everything']);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Unknown option: --everything.');
});

test('it stops before doing anything when not run as root', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('This test asserts the non-root path and cannot run as root.');
    }

    $process = new Process(['bash', uninstallerPath(), '--yes']);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('This uninstaller must be run as root. Use: sudo bash uninstall.sh');
});

test('everything the installer creates, the uninstaller removes', function () {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $pairs = [
        'coresmith-horizon.service' => 'coresmith-horizon.service',
        'coresmith-reverb.service' => 'coresmith-reverb.service',
        'coresmith-scheduler.service' => 'coresmith-scheduler.service',
        'coresmith-scheduler.timer' => 'coresmith-scheduler.timer',
        'coresmith-update.service' => 'coresmith-update.service',
        'coresmith-update.path' => 'coresmith-update.path',
        'coresmith-address.service' => 'coresmith-address.service',
        'coresmith-address.path' => 'coresmith-address.path',
        'caddy.service.d/coresmith.conf' => 'caddy.service.d/coresmith.conf',
        '/etc/caddy/coresmith' => '/etc/caddy/coresmith',
        '/etc/caddy/panel.caddy' => '/etc/caddy/panel.caddy',
        '/etc/tmpfiles.d/coresmith-caddy.conf' => '/etc/tmpfiles.d/coresmith-caddy.conf',
        '/run/caddy-admin' => '/run/caddy-admin',
        'coresmith-panel.conf' => 'coresmith-panel.conf',
        '/etc/fail2ban/jail.d/coresmith.conf' => '/etc/fail2ban/jail.d/coresmith.conf',
        '/etc/fail2ban/filter.d/coresmith-login.conf' => '/etc/fail2ban/filter.d/coresmith-login.conf',
        'safe.directory' => 'safe.directory',
        '/opt/coresmith' => '/opt/coresmith',
        '/var/lib/coresmith' => '/var/lib/coresmith',
        '/etc/coresmith' => '/etc/coresmith',
        '/var/coresmith' => '/var/coresmith',
        '/var/log/coresmith-' => '/var/log/coresmith-',
        'useradd' => 'userdel',
    ];

    foreach ($pairs as $created => $removed) {
        expect($installer)->toContain($created)
            ->and(uninstallerBody())->toContain($removed);
    }
});

test('every unit file the installer writes is one the uninstaller stops', function () {
    preg_match_all('#/etc/systemd/system/(coresmith-[a-z-]+\.(?:service|timer|path))#', (string) file_get_contents(base_path('scripts/install.sh')), $written);
    preg_match('/^UNITS="([^"]+)"$/m', uninstallerBody(), $stopped);

    expect(array_unique($written[1]))->not->toBeEmpty()
        ->and(array_diff(array_unique($written[1]), explode(' ', $stopped[1] ?? '')))->toBe([]);
});

test('the uninstaller never touches the units of deployed apps', function () {
    foreach (['coresmith-*.service', 'coresmith-*.timer', 'coresmith-*.path', 'coresmith-app'] as $glob) {
        expect(uninstallerBody())->not->toContain($glob);
    }
});

test('the uninstaller removes the panel and keeps a copy of its data', function () {
    $base = uninstallerTree();

    $process = runUninstaller($base, ['--yes']);
    $saved = uninstallerSaved($base);
    $calls = uninstallerCalls($base);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and($saved)->not->toBeNull()
        ->and(substr(sprintf('%o', fileperms((string) $saved)), -3))->toBe('700')
        ->and(file_get_contents("{$saved}/.env"))->toContain('CORESMITH_REPOSITORY=')
        ->and(file_get_contents("{$saved}/backups/panel-20261101.sql.gz"))->toBe('backup')
        ->and(trim((new Process(['sqlite3', "{$saved}/database.sqlite", 'select email from users']))->mustRun()->getOutput()))->toBe('owner@example.test')
        ->and(file_get_contents("{$saved}/Caddyfile"))->toContain('import ')
        ->and(file_get_contents("{$base}/etc/caddy/Caddyfile"))->toBe("{\n    admin localhost:2019\n}\n")
        ->and(is_file("{$base}/etc/php/8.5/fpm/pool.d/www.conf"))->toBeTrue()
        ->and(is_file("{$base}/var/log/syslog"))->toBeTrue()
        ->and(is_file("{$base}/etc/systemd/system/coresmith-app-shop-web.service"))->toBeTrue();

    foreach ([
        'opt/coresmith', 'var/lib/coresmith', 'var/coresmith', 'etc/coresmith', 'etc/caddy/coresmith', 'etc/caddy/caddy-config.json',
        'etc/tmpfiles.d/coresmith-caddy.conf', 'run/caddy-admin',
        'etc/systemd/system/caddy.service.d', 'etc/php/8.5/fpm/pool.d/coresmith-panel.conf', 'etc/fail2ban/jail.d/coresmith.conf',
        'etc/fail2ban/filter.d/coresmith-login.conf', 'var/log/coresmith-horizon.log', 'var/log/coresmith-install.log',
        'etc/systemd/system/coresmith-horizon.service', 'etc/systemd/system/coresmith-reverb.service', 'etc/systemd/system/coresmith-scheduler.service',
        'etc/systemd/system/coresmith-scheduler.timer', 'etc/systemd/system/coresmith-update.service', 'etc/systemd/system/coresmith-update.path',
        'etc/systemd/system/coresmith-address.service', 'etc/systemd/system/coresmith-address.path',
    ] as $path) {
        expect(file_exists("{$base}/{$path}"))->toBeFalse("{$path} is still there");
    }

    expect(array_slice($calls, 0, 2))->toBe(['systemctl stop coresmith-update.path', 'systemctl disable coresmith-update.path'])
        ->and($calls)->toContain('systemctl stop coresmith-horizon.service')
        ->and($calls)->toContain('systemctl disable coresmith-scheduler.timer')
        ->and($calls)->toContain('systemctl daemon-reload')
        ->and($calls)->toContain('systemctl reload caddy')
        ->and($calls)->toContain('systemctl restart php8.5-fpm')
        ->and($calls)->toContain('systemctl restart fail2ban')
        ->and($calls)->toContain('git config --system --fixed-value --unset-all safe.directory https://git.example.test/coresmith.git')
        ->and($calls)->toContain('git config --system --fixed-value --unset-all safe.directory https://git.example.test/coresmith.git/.git')
        ->and($calls)->toContain("git config --system --fixed-value --unset-all safe.directory {$base}/opt/coresmith/app")
        ->and(end($calls))->toBe('userdel coresmith')
        ->and($process->getOutput())->toContain('Coresmith is removed from this server.')
        ->and($process->getOutput())->toContain("A copy of its .env, backups and database is in {$saved}.")
        ->and($process->getOutput())->toContain('Caddy, Redis, PHP, Node.js, Bun, Composer and any database server are still installed, as other software may use them.')
        ->and($process->getOutput())->toContain('Firewall rules for SSH, 80 and 443 are unchanged.')
        ->and($process->getOutput())->toContain('Servers Coresmith managed keep running. Its public key stays in their authorized_keys until you remove it.')
        ->and($process->getOutput())->not->toContain('—');
});

test('an install from before the site moved into its own directory is cleaned up too', function () {
    $base = uninstallerTree();
    File::deleteDirectory("{$base}/etc/caddy/coresmith");
    file_put_contents("{$base}/etc/caddy/panel.caddy", "panel.example.test {\n}\n");
    file_put_contents("{$base}/etc/caddy/Caddyfile", "{\n    admin localhost:2019\n}\n\nimport {$base}/etc/caddy/panel.caddy\n");

    $process = runUninstaller($base, ['--yes']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and(file_exists("{$base}/etc/caddy/panel.caddy"))->toBeFalse()
        ->and(file_get_contents("{$base}/etc/caddy/Caddyfile"))->toBe("{\n    admin localhost:2019\n}\n");
});

test('apps deployed on this machine keep running', function () {
    $base = uninstallerTree();
    mkdir("{$base}/var/coresmith/apps/shop");

    $process = runUninstaller($base, ['--yes']);
    $calls = uninstallerCalls($base);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and(is_dir("{$base}/var/coresmith/apps/shop"))->toBeTrue()
        ->and(is_dir("{$base}/etc/coresmith/env"))->toBeTrue()
        ->and(is_file("{$base}/etc/caddy/caddy-config.json"))->toBeTrue()
        ->and(is_file("{$base}/etc/systemd/system/caddy.service.d/coresmith.conf"))->toBeTrue()
        ->and(is_file("{$base}/etc/tmpfiles.d/coresmith-caddy.conf"))->toBeTrue()
        ->and(file_get_contents("{$base}/etc/caddy/Caddyfile"))->toBe("{\n    admin unix/{$base}/run/caddy-admin/admin.sock|0222\n}\n")
        ->and(file_exists("{$base}/opt/coresmith"))->toBeFalse()
        ->and(file_exists("{$base}/etc/caddy/coresmith"))->toBeFalse()
        ->and($calls)->toContain('systemctl restart caddy')
        ->and($calls)->not->toContain('systemctl reload caddy')
        ->and($calls)->not->toContain('userdel coresmith')
        ->and($process->getOutput())->toContain("Apps are still deployed on this machine under {$base}/var/coresmith/apps (shop). They are left running; remove them yourself if you no longer need them.")
        ->and($process->getOutput())->toContain('Kept for the apps still deployed here:');
});

test('--purge keeps no copy', function () {
    $base = uninstallerTree();

    $process = runUninstaller($base, ['--yes', '--purge']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and(uninstallerSaved($base))->toBeNull()
        ->and(file_exists("{$base}/opt/coresmith"))->toBeFalse()
        ->and($process->getOutput())->not->toContain('A copy of its');
});

test('a Caddyfile that does not import the panel is left alone', function () {
    $base = uninstallerTree();
    file_put_contents("{$base}/etc/caddy/Caddyfile", "shop.example.test {\n}\n");

    $process = runUninstaller($base, ['--yes']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and(file_get_contents("{$base}/etc/caddy/Caddyfile"))->toBe("shop.example.test {\n}\n");
});

test('nothing is removed until uninstall is typed', function () {
    $base = uninstallerTree();

    $process = runUninstaller($base, [], "yes\n");

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain("This removes Coresmith from this server: its services, its Caddy site, PHP-FPM pool and fail2ban jail, {$base}/opt/coresmith, {$base}/var/lib/coresmith, its logs and the coresmith user.\nA copy of its .env, backups and database is saved under {$base}/root first.\n")
        ->and($process->getOutput())->toContain('Type uninstall to remove Coresmith from this server:')
        ->and($process->getErrorOutput())->toContain('Nothing was removed.')
        ->and(is_dir("{$base}/opt/coresmith"))->toBeTrue()
        ->and(uninstallerCalls($base))->toBe([]);

    $confirmed = runUninstaller($base, [], "uninstall\n");

    expect($confirmed->getExitCode())->toBe(0, $confirmed->getErrorOutput() . $confirmed->getOutput())
        ->and(file_exists("{$base}/opt/coresmith"))->toBeFalse();
});

test('a panel on PostgreSQL is told its database stays on its server', function () {
    $base = uninstallerTree();
    file_put_contents("{$base}/opt/coresmith/shared/.env", "DB_CONNECTION=pgsql\nDB_DATABASE=coresmith\n");

    $process = runUninstaller($base, ['--yes']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and($process->getOutput())->toContain('Its PostgreSQL database coresmith is still on its database server. Drop it if you no longer need it.')
        ->and(uninstallerCalls($base))->toContain('git config --system --fixed-value --unset-all safe.directory https://github.com/monoventech/coresmith.git');
});

test('a coresmith user that cannot be removed is reported, not fatal', function () {
    preg_match('/^remove_user\(\) \{\n.*?^\}$/ms', uninstallerBody(), $function);
    $script = "set -Eeuo pipefail\nAPPS=''\nid() { return 0; }\n"
        . "userdel() { printf 'userdel: user coresmith is currently used by process 42' >&2; return 1; }\n"
        . "success() { printf '%s\\n' \"\$1\"; }\nwarn() { printf '%s\\n' \"\$1\"; }\n"
        . "{$function[0]}\nremove_user\nprintf 'carried on\\n'\n";
    $process = new Process(['bash', '-c', $script]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("Couldn't remove the coresmith user: userdel: user coresmith is currently used by process 42.\ncarried on\n");
});

test('the question says the coresmith user stays while apps deployed here use it', function () {
    $base = uninstallerTree();
    mkdir("{$base}/var/coresmith/apps/shop");

    $process = runUninstaller($base, [], "no\n");

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain("This removes Coresmith from this server: its services, its Caddy site, PHP-FPM pool and fail2ban jail, {$base}/opt/coresmith, {$base}/var/lib/coresmith and its logs.\n")
        ->and($process->getOutput())->toContain("Apps deployed here (shop) keep running, with {$base}/var/coresmith, {$base}/etc/coresmith and the coresmith user.\n")
        ->and(is_dir("{$base}/opt/coresmith"))->toBeTrue();
});

test('with --purge the question promises no copy', function () {
    $base = uninstallerTree();

    $process = runUninstaller($base, ['--purge'], "no\n");

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('its logs and the coresmith user.')
        ->and($process->getOutput())->not->toContain('A copy of its');
});

test('saying no gives the panel back anything a root run left in its storage', function () {
    $base = uninstallerTree();
    $calls = escapeshellarg("{$base}/calls.log");
    $uid = posix_geteuid();

    $process = runUninstaller($base, [], "no\n", "chown() { printf 'chown %s\\n' \"\$*\" >>{$calls}; }", fn(string $script): string => str_replace(' -uid 0 ', " -uid {$uid} ", $script));
    $storage = "{$base}/opt/coresmith/shared/storage";

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Nothing was removed.')
        ->and(uninstallerCalls($base))->toBe([
            "chown -h coresmith:coresmith {$storage}",
            "chown -h coresmith:coresmith {$storage}/app",
            "chown -h coresmith:coresmith {$storage}/app/private",
            "chown -h coresmith:coresmith {$storage}/app/private/backups",
            "chown -h coresmith:coresmith {$storage}/app/private/backups/panel-20261101.sql.gz",
        ])
        ->and(is_dir("{$base}/opt/coresmith"))->toBeTrue();
});

test('only files root owns are given back to the panel', function () {
    $base = uninstallerTree();
    $calls = escapeshellarg("{$base}/calls.log");

    $process = runUninstaller($base, [], "no\n", "chown() { printf 'chown %s\\n' \"\$*\" >>{$calls}; }");

    expect($process->getExitCode())->toBe(1)
        ->and(uninstallerCalls($base))->toBe([]);
});
