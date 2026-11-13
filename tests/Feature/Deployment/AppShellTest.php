<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\SSHCommandTimedOut;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\AppShell;
use App\Services\Runtime\AsdfManager;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
        . ' -p RuntimeMaxSec=600 -p PrivateTmp=yes -p UMask=0027 -p NoNewPrivileges=yes'
        . " -E 'PATH=/opt/asdf-data/installs/nodejs/24.15.0/bin:/usr/local/bin:/usr/bin:/bin'"
        . " -- /bin/bash -c 'npm ci && echo '\\''done'\\'''",
    );
});

test('everything the app shell runs creates files that others cannot read', function () {
    $shell = new AppShell(new AsdfManager);
    $application = shellApp();

    expect($shell->run($application, 'bundle install', '/tmp'))->toContain(' -p UMask=0027 ')
        ->and($shell->runScript($application, '/etc/coresmith/blueprints/shop.sh', '/tmp'))->toContain(' -p UMask=0027 ')
        ->and($shell->scheduled($application, '/etc/coresmith/cron/shop/0.sh', '/var/coresmith/apps/shop/current'))->toContain(' -p UMask=0027 ');
});

test('dollar signs in a step reach bash rather than systemd, which would expand them itself', function () {
    $command = (new AppShell(new AsdfManager))->run(shellApp(), 'db="${1:-app}"; echo "$db" $$', '/var/coresmith/apps/shop/current');

    expect($command)->toEndWith(' -- /bin/bash -c ' . escapeshellarg('db="$${1:-app}"; echo "$$db" $$$$'));
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

test('the app account owns only its releases and shared folder, and root keeps the folders around them, which the app can list but not change', function () {
    $command = (new AppAccount)->command(shellApp());

    expect($command)->toStartWith('{ id -u coresmith-app-shop >/dev/null 2>&1 || useradd --system --create-home --home-dir /home/coresmith-app-shop --shell /usr/sbin/nologin ')
        ->and($command)->toContain('chmod 751 /var/coresmith/apps')
        ->and($command)->toContain('chown root:$(id -gn coresmith-app-shop) /var/coresmith/apps/shop /var/coresmith/apps/shop/releases')
        ->and($command)->toContain('chmod 751 /var/coresmith/apps/shop /var/coresmith/apps/shop/releases')
        ->and($command)->toContain('chown -h coresmith-app-shop:caddy /var/coresmith/apps/shop/shared')
        ->and($command)->toContain('chmod 2750 /var/coresmith/apps/shop/shared');
});

test('a claimed release belongs to the app user, readable but not writable by caddy and closed to everyone else', function () {
    expect((new AppAccount)->claimRelease(shellApp(), '/var/coresmith/apps/shop/releases/20261018-120000'))
        ->toBe("chmod -R u+rwX,g+rX,g-w,o-rwx '/var/coresmith/apps/shop/releases/20261018-120000'"
            . " && chmod 750 '/var/coresmith/apps/shop/releases/20261018-120000'"
            . " && find '/var/coresmith/apps/shop/releases/20261018-120000' -type d -exec chmod g+s {} +"
            . " && chown -hR coresmith-app-shop:caddy '/var/coresmith/apps/shop/releases/20261018-120000'");
});

test('a release gets every mode while root still owns it, and only then is handed to the app user', function () {
    $steps = explode(' && ', (new AppAccount)->claimRelease(shellApp(), '/var/coresmith/apps/shop/releases/20261018-120000'));

    expect(end($steps))->toStartWith('chown -hR coresmith-app-shop:caddy ')
        ->and(array_filter($steps, fn(string $step): bool => str_starts_with($step, 'chown ')))->toHaveCount(1);
});

test('claiming a release turns the modes git archive leaves into owner write and group read only, with every folder handing new files to its group', function () {
    $release = sys_get_temp_dir() . '/coresmith-claim-' . bin2hex(random_bytes(4));
    $outside = $release . '-outside';
    $directories = ['' => 0775, '/app' => 0775, '/app/controllers' => 0777, '/config' => 0775, '/bin' => 0775];
    $files = ['/app/controllers/application_controller.rb' => 0664, '/config/database.yml' => 0666, '/bin/rails' => 0775];

    foreach ($directories as $path => $mode) {
        mkdir($release . $path);
        chmod($release . $path, $mode);
    }

    foreach ($files as $path => $mode) {
        touch($release . $path);
        chmod($release . $path, $mode);
    }

    mkdir($outside);
    chmod($outside, 0755);
    symlink($outside, $release . '/config/linked');

    $steps = array_filter(explode(' && ', (new AppAccount)->claimRelease(shellApp(), $release)), fn(string $step): bool => !str_starts_with($step, 'chown '));
    $process = new Process(['bash', '-c', implode(' && ', $steps)]);
    $process->run();

    $modes = [];

    foreach ([...array_keys($directories), ...array_keys($files)] as $path) {
        clearstatcache(true, $release . $path);
        $modes[$path] = sprintf('%o', fileperms($release . $path) & 07777);
    }

    clearstatcache(true, $outside);
    $linked = sprintf('%o', fileperms($outside) & 07777);

    (new Process(['rm', '-rf', $release, $outside]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($modes)->toBe([
            '' => '2750',
            '/app' => '2750',
            '/app/controllers' => '2750',
            '/config' => '2750',
            '/bin' => '2750',
            '/app/controllers/application_controller.rb' => '640',
            '/config/database.yml' => '640',
            '/bin/rails' => '750',
        ])
        ->and($linked)->toBe('755');
});

function shareStorageApp(): Application
{
    return Application::factory()->make(['slug' => 'shop', 'system_user' => 'coresmith-app-shop', 'type' => ApplicationType::Php]);
}

test('a php app has its shared storage folders handed to caddy by root, confined to the app shared folder', function () {
    $folders = 'systemd-run --quiet --wait --pipe --collect --service-type=exec'
        . " -p ProtectSystem=strict -p 'ReadWritePaths=/var/coresmith/apps/shop/shared'"
        . ' -p PrivateDevices=yes -p PrivateTmp=yes -p ProtectKernelTunables=yes -p ProtectControlGroups=yes -p NoNewPrivileges=yes'
        . " -- /usr/bin/find '/var/coresmith/apps/shop/shared/storage' -type d";

    expect((new AppAccount)->shareStorage(shareStorageApp()))->toBe(
        "if [ -d '/var/coresmith/apps/shop/shared/storage' ]; then {$folders} '!' -group caddy -exec chgrp caddy '{}' +"
        . " && {$folders} '!' -perm -2000 -exec chmod g+s '{}' +; fi",
    );
});

function shareStorageFinds(string $storage, string $group): array
{
    $command = Str::before((new AppAccount)->shareStorage(shareStorageApp()), '; fi');

    return array_map(
        fn(string $part): string => str_replace(["'/var/coresmith/apps/shop/shared/storage'", ' caddy '], [escapeshellarg($storage), " {$group} "], Str::after($part, ' -- ')),
        explode(' && ', $command),
    );
}

test('handing storage to caddy regroups and marks only the folders that need it, never a file and never what a link points at', function () {
    $root = sys_get_temp_dir() . '/coresmith-share-' . bin2hex(random_bytes(4));
    $storage = $root . '/storage';
    $outside = $root . '-outside';
    $primary = trim((new Process(['id', '-gn']))->mustRun()->getOutput());
    $group = collect(preg_split('/\s+/', trim((new Process(['id', '-Gn']))->mustRun()->getOutput())))->first(fn(string $name): bool => $name !== $primary) ?? $primary;

    mkdir($root);

    foreach (['', '/app', '/app/public', '/app/public/avatars'] as $path) {
        mkdir($storage . $path);
        chmod($storage . $path, 0750);
    }

    file_put_contents($storage . '/app/public/avatars/ada.jpg', 'jpg');
    chmod($storage . '/app/public/avatars/ada.jpg', 0644);
    mkdir($outside);
    chmod($outside, 0755);
    symlink($outside, $storage . '/app/public/linked');

    $finds = shareStorageFinds($storage, $group);
    $share = new Process(['bash', '-c', implode(' && ', $finds)]);
    $share->run();
    $again = new Process(['bash', '-c', implode(' && ', array_map(fn(string $find): string => (string) preg_replace('/ -exec .*$/', ' -print', $find), $finds))]);
    $again->run();

    $modes = [];
    $groups = [];

    foreach (['', '/app', '/app/public', '/app/public/avatars', '/app/public/avatars/ada.jpg'] as $path) {
        clearstatcache(true, $storage . $path);
        $modes[$path] = sprintf('%o', fileperms($storage . $path) & 07777);
        $groups[$path] = posix_getgrgid((int) filegroup($storage . $path))['name'] ?? null;
    }

    clearstatcache(true, $outside);
    $linked = [sprintf('%o', fileperms($outside) & 07777), posix_getgrgid((int) filegroup($outside))['name'] ?? null];

    (new Process(['rm', '-rf', $root, $outside]))->run();

    expect($share->getExitCode())->toBe(0, $share->getErrorOutput())
        ->and($modes)->toBe(['' => '2750', '/app' => '2750', '/app/public' => '2750', '/app/public/avatars' => '2750', '/app/public/avatars/ada.jpg' => '644'])
        ->and($groups)->toBe(['' => $group, '/app' => $group, '/app/public' => $group, '/app/public/avatars' => $group, '/app/public/avatars/ada.jpg' => $primary])
        ->and($linked)->toBe(['755', $primary])
        ->and($again->getExitCode())->toBe(0, $again->getErrorOutput())
        ->and($again->getOutput())->toBe('');
});

test("on Coresmith's own machine every step, script and scheduled command runs in the apps slice, out of sight of Coresmith's files", function () {
    $server = Server::factory()->create(['runs_coresmith' => true, 'meta' => ['systemd' => 255]]);
    $application = Application::factory()->for($server)->make(['slug' => 'shop', 'system_user' => 'coresmith-app-shop']);
    $shell = new AppShell(new AsdfManager);

    foreach ([
        $shell->run($application, 'npm ci', '/tmp'),
        $shell->runScript($application, '/etc/coresmith/blueprints/shop.sh', '/tmp'),
        $shell->scheduled($application, '/etc/coresmith/cron/shop/0.sh', '/var/coresmith/apps/shop/current'),
    ] as $command) {
        expect($command)->toContain(" -p 'Slice=coresmith-apps.slice' -p 'InaccessiblePaths=-/opt/coresmith -/var/lib/coresmith -/var/lib/coresmith-this-machine -/etc/caddy -/run/caddy-admin -/var/log/coresmith-install.log -/var/log/coresmith-horizon.log -/var/log/coresmith-reverb.log -/var/log/coresmith-fpm-error.log' -p 'ProtectProc=invisible' -p UMask=0027 ");
    }
});
