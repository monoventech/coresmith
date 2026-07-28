<?php

declare(strict_types=1);

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\Server;
use App\Services\Server\AptPackageManager;
use App\Services\Server\DnfPackageManager;
use App\Services\Server\PackageManagerFactory;
use App\Services\Server\YumPackageManager;

test('apt builds a non-interactive update command', function () {
    expect((new AptPackageManager)->update())
        ->toContain('DEBIAN_FRONTEND=noninteractive')
        ->toContain('apt-get update');
});

test('apt install is non-interactive and assumes yes', function () {
    $command = (new AptPackageManager)->install(['curl', 'git']);

    expect($command)->toContain('apt-get install -y')
        ->and($command)->toContain("'curl'")
        ->and($command)->toContain("'git'");
});

test('a package name carrying shell metacharacters stays a single quoted argument', function () {
    foreach ([new AptPackageManager, new DnfPackageManager, new YumPackageManager] as $manager) {
        expect($manager->install(['curl; rm -rf /']))->toContain("'curl; rm -rf /'");
    }
});

test('dnf builds install and update commands', function () {
    expect((new DnfPackageManager)->update())->toContain('dnf')
        ->and((new DnfPackageManager)->install(['curl']))->toContain('dnf install -y');
});

test('yum builds install and update commands', function () {
    expect((new YumPackageManager)->update())->toContain('yum')
        ->and((new YumPackageManager)->install(['curl']))->toContain('yum install -y');
});

test('each manager can check whether a package is installed', function () {
    expect((new AptPackageManager)->isInstalled('curl'))->toContain('dpkg-query')
        ->and((new DnfPackageManager)->isInstalled('curl'))->toContain('rpm -q')
        ->and((new YumPackageManager)->isInstalled('curl'))->toContain('rpm -q');
});

test('installing nothing yields a harmless command', function () {
    expect((new AptPackageManager)->install([]))->toBe('true');
});

test('the factory resolves the right manager per distribution', function (string $os, string $expected) {
    expect(PackageManagerFactory::for(Server::factory()->make(['os_name' => $os])))->toBeInstanceOf($expected);
})->with([
    ['ubuntu', AptPackageManager::class],
    ['debian', AptPackageManager::class],
    ['rocky', DnfPackageManager::class],
    ['almalinux', DnfPackageManager::class],
    ['fedora', DnfPackageManager::class],
    ['amzn', YumPackageManager::class],
]);

test('the factory rejects an unsupported os', function () {
    PackageManagerFactory::for(Server::factory()->make(['os_name' => 'windows']));
})->throws(UnsupportedOperatingSystemException::class);

test('the factory rejects a server with no detected os', function () {
    PackageManagerFactory::for(Server::factory()->make(['os_name' => null]));
})->throws(UnsupportedOperatingSystemException::class);
