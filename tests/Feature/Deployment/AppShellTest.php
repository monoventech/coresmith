<?php

declare(strict_types=1);

use App\Exceptions\SSHCommandTimedOut;
use App\Models\Application;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\AppShell;
use App\Services\Runtime\AsdfManager;

function shellApp(): Application
{
    return Application::factory()->make(['slug' => 'shop', 'system_user' => 'coresmith-app-shop']);
}

test('a step runs as the app in a transient unit with its environment file and runtimes on PATH', function () {
    $command = (new AppShell(new AsdfManager))->run(shellApp(), "npm ci && echo 'done'", '/var/coresmith/apps/shop/releases/20261018-120000', ['nodejs' => '24.15.0']);

    expect($command)->toBe(
        'systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-shop --gid=coresmith-app-shop'
        . " -p 'WorkingDirectory=/var/coresmith/apps/shop/releases/20261018-120000'"
        . " -p 'EnvironmentFile=/etc/coresmith/env/shop.env'"
        . ' -p RuntimeMaxSec=600 -p PrivateTmp=yes -p NoNewPrivileges=yes'
        . " -E 'PATH=/opt/asdf-data/installs/nodejs/24.15.0/bin:/usr/local/bin:/usr/bin:/bin'"
        . " -- /bin/bash -c 'npm ci && echo '\\''done'\\'''",
    );
});

test('a script is fed to the app on stdin, so its contents never reach a command line', function () {
    $command = (new AppShell(new AsdfManager))->runScript(shellApp(), '/etc/coresmith/blueprints/shop.sh', '/var/coresmith/apps/shop/releases/20261018-120000');

    expect($command)->toEndWith(" -- /bin/bash -s < '/etc/coresmith/blueprints/shop.sh'")
        ->and($command)->toContain("-E 'PATH=/usr/local/bin:/usr/bin:/bin'");
});

test('a runtime version that could inject into PATH is refused', function () {
    (new AppShell(new AsdfManager))->run(shellApp(), 'true', '/tmp', ['nodejs' => '24; rm -rf /']);
})->throws(InvalidArgumentException::class);

test('a step that times out is named by what the app ran, not by its wrapper', function () {
    $command = (new AppShell(new AsdfManager))->run(shellApp(), 'composer install --no-dev', '/tmp');

    expect(SSHCommandTimedOut::display($command))->toBe('composer install --no-dev')
        ->and(SSHCommandTimedOut::display('sudo bash -c ' . escapeshellarg($command)))->toBe('composer install --no-dev');
});

test('the app account owns only its releases and shared folder, and root keeps the folders around them', function () {
    $command = (new AppAccount)->command(shellApp());

    expect($command)->toStartWith('{ id -u coresmith-app-shop >/dev/null 2>&1 || useradd --system --create-home --home-dir /home/coresmith-app-shop --shell /usr/sbin/nologin ')
        ->and($command)->toContain('chmod 751 /var/coresmith/apps')
        ->and($command)->toContain('chown root:root /var/coresmith/apps/shop /var/coresmith/apps/shop/releases')
        ->and($command)->toContain('chmod 711 /var/coresmith/apps/shop /var/coresmith/apps/shop/releases')
        ->and($command)->toContain('chown -h coresmith-app-shop:caddy /var/coresmith/apps/shop/shared')
        ->and($command)->toContain('chmod 750 /var/coresmith/apps/shop/shared')
        ->and((new AppAccount)->claimRelease(shellApp(), '/var/coresmith/apps/shop/releases/20261018-120000'))
        ->toBe("chown -hR coresmith-app-shop:caddy '/var/coresmith/apps/shop/releases/20261018-120000' && chmod 750 '/var/coresmith/apps/shop/releases/20261018-120000'");
});
