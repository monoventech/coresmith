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

test('apt install waits up to five minutes for the dpkg lock', function () {
    expect((new AptPackageManager)->install(['curl']))->toEndWith(' -o DPkg::Lock::Timeout=300');
});

test('apt names the source a failed refresh came from', function (string $output, ?string $source) {
    expect(AptPackageManager::failedSource($output))->toBe($source);
})->with([
    'a repository with no release file' => ["Hit:1 http://ports.ubuntu.com/ubuntu-ports noble InRelease\nErr:5 https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble Release\n  404  Not Found [IP: 185.125.190.80 443]\nE: The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble Release' does not have a Release file.\n", 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu'],
    'a repository that is not signed' => ["W: GPG error: https://download.docker.com/linux/ubuntu noble InRelease: The following signatures couldn't be verified because the public key is not available: NO_PUBKEY 7EA0A9C3F273FCD8\nE: The repository 'https://download.docker.com/linux/ubuntu noble InRelease' is not signed.\n", 'https://download.docker.com/linux/ubuntu'],
    'a fetch that was refused' => ["E: Failed to fetch https://packages.example.com/apt/dists/stable/InRelease  403  Forbidden [IP: 203.0.113.5 443]\nE: Some index files failed to download. They have been ignored, or old ones used instead.\n", 'https://packages.example.com/apt'],
    'a release file from the future' => ["E: Release file for http://archive.ubuntu.com/ubuntu/dists/noble-updates/InRelease is not valid yet (invalid for another 2h 3min 4s). Updates for this repository will not be applied.\n", 'http://archive.ubuntu.com/ubuntu'],
    'a held lock' => ["E: Could not get lock /var/lib/apt/lists/lock. It is held by process 2231 (apt-get)\nE: Unable to lock directory /var/lib/apt/lists/\n", null],
    'a full disk' => ["E: Write error - write (28: No space left on device)\n", null],
]);

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
