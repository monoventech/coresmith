<?php

declare(strict_types=1);

use App\Exceptions\InvalidRuntimeException;
use App\Exceptions\PackageSourceFailed;
use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\BuildDependencies;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

function asdfConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

    return (new SSHConnection($server, $transport))->connect();
}

test('binary paths resolve into the shared install directory', function () {
    $asdf = new AsdfManager;

    expect($asdf->binaryPath('php', '8.5.5'))->toBe('/opt/asdf-data/installs/php/8.5.5/bin/php')
        ->and($asdf->binaryPath('ruby', '3.3.6'))->toBe('/opt/asdf-data/installs/ruby/3.3.6/bin/ruby')
        ->and($asdf->fpmBinaryPath('8.5.5'))->toBe('/opt/asdf-data/installs/php/8.5.5/sbin/php-fpm');
});

test('the nodejs runtime resolves to the node binary', function () {
    expect((new AsdfManager)->binaryPath('nodejs', '24.15.0'))
        ->toBe('/opt/asdf-data/installs/nodejs/24.15.0/bin/node');
});

test('an explicit binary name overrides the default', function () {
    expect((new AsdfManager)->binaryPath('nodejs', '24.15.0', 'npm'))
        ->toBe('/opt/asdf-data/installs/nodejs/24.15.0/bin/npm');
});

test('a version path rejects a version that could reach the shell', function () {
    (new AsdfManager)->versionPath('php', '8.5.5; rm -rf /');
})->throws(InvalidRuntimeException::class);

test('a version path allows an empty version for a runtime not yet resolved', function () {
    expect((new AsdfManager)->versionPath('ruby', ''))->toBe('/opt/asdf-data/installs/ruby/');
});

test('detects an installed version', function () {
    $connection = asdfConnection((new FakeSSHTransport)->queueResult("yes\n", 0));

    expect((new AsdfManager)->isInstalled($connection, 'php', '8.5.5'))->toBeTrue();
});

test('detects a missing version', function () {
    $connection = asdfConnection((new FakeSSHTransport)->queueResult("no\n", 0));

    expect((new AsdfManager)->isInstalled($connection, 'php', '8.5.5'))->toBeFalse();
});

test('installing an already present version does no work', function () {
    $transport = (new FakeSSHTransport)->queueResult("yes\n", 0);
    $connection = asdfConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5');

    expect($transport->commands())->toHaveCount(1);
});

function asdfStep(array $commands, string $needle): int
{
    $found = array_keys(array_filter($commands, fn(string $command): bool => str_contains($command, $needle)));

    expect($found)->toHaveCount(1);

    return $found[0];
}

test('installing a missing version installs build dependencies, updates the plugin, then asdf installs it', function () {
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0);
    $connection = asdfConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5');

    $commands = $transport->commands();
    $packages = asdfStep($commands, 'apt-get install -y');
    $plugin = asdfStep($commands, 'asdf plugin update "$CORESMITH_RUNTIME"');
    $install = asdfStep($commands, 'asdf install "$CORESMITH_RUNTIME" "$CORESMITH_VERSION"');

    expect($commands[$packages])->toContain("'libonig-dev'")
        ->and($commands[$plugin])->toContain('sudo -u coresmith')
        ->and($commands[$install])->toContain('sudo -u coresmith')
        ->and($commands[$install])->toContain("CORESMITH_VERSION='8.5.5'")
        ->and($packages)->toBeLessThan($plugin)
        ->and($plugin)->toBeLessThan($install);
});

test('build packages are installed from a fresh index', function (string $os, string $update, string $install) {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => $os]);
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0);

    (new AsdfManager)->install((new SSHConnection($server, $transport))->connect(), $server, 'php', '8.5.5');

    $commands = $transport->commands();
    $refreshed = asdfStep($commands, $update);

    expect($commands[$refreshed + 1])->toContain($install)
        ->and($transport->budgetFor($update))->toBe(1200);
})->with([
    'apt' => ['ubuntu', 'apt-get update -y', 'apt-get install -y --no-install-recommends'],
    'dnf' => ['rocky', 'dnf check-update -y || true', 'dnf install -y'],
    'yum' => ['amzn', 'yum check-update -y || true', 'yum install -y'],
]);

test('a runtime with no build packages refreshes no index', function () {
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0);
    $connection = asdfConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'bun', '1.4.2');

    expect(implode("\n", $transport->commands()))->not->toContain('apt-get update')
        ->and(implode("\n", $transport->commands()))->not->toContain('apt-get install');
});

test('an index that cannot be refreshed stops the install before anything is built', function () {
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0)->respondTo('apt-get update -y', "E: Write error - write (28: No space left on device)\n", 100);
    $connection = asdfConnection($transport);

    expect(fn() => (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5'))->toThrow(SSHCommandFailedException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('apt-get install')
        ->and(implode("\n", $transport->commands()))->not->toContain('asdf install');
});

test('a locked package index is refreshed once the lock is free', function () {
    Sleep::fake();
    $lock = "E: Could not get lock /var/lib/apt/lists/lock. It is held by process 2231 (apt.systemd.dai)\nE: Unable to lock directory /var/lib/apt/lists/\n";
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0)->respondToSequence('apt-get update -y', [[$lock, 100], [$lock, 100], ['', 0]]);
    $connection = asdfConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5');

    expect(substr_count(implode("\n", $transport->commands()), 'apt-get update -y'))->toBe(3)
        ->and(implode("\n", $transport->commands()))->toContain('asdf install');
    Sleep::assertSleptTimes(2);
});

test('an index that stays locked for five minutes stops the install', function () {
    Sleep::fake();
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0)->respondTo('apt-get update -y', "E: Could not get lock /var/lib/apt/lists/lock. It is held by process 2231 (apt-get)\n", 100);
    $connection = asdfConnection($transport);

    expect(fn() => (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5'))->toThrow(SSHCommandFailedException::class);

    expect(AsdfManager::INDEX_LOCK_RETRIES)->toBe(30)
        ->and(substr_count(implode("\n", $transport->commands()), 'apt-get update -y'))->toBe(31)
        ->and(implode("\n", $transport->commands()))->not->toContain('apt-get install');
    Sleep::assertSleptTimes(30);
});

test('a network blip while refreshing the index is tried again, twice at most', function (int $blips, int $updates, bool $installed) {
    Sleep::fake();
    $blip = "W: Failed to fetch http://archive.ubuntu.com/ubuntu/dists/noble/InRelease  Temporary failure resolving 'archive.ubuntu.com'\nE: Some index files failed to download. They have been ignored, or old ones used instead.\n";
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0)->respondToSequence('apt-get update -y', [...array_fill(0, $blips, [$blip, 100]), ['', 0]]);
    $connection = asdfConnection($transport);
    $install = fn() => (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5');

    $installed ? $install() : expect($install)->toThrow(PackageSourceFailed::class);

    expect(substr_count(implode("\n", $transport->commands()), 'apt-get update -y'))->toBe($updates)
        ->and(str_contains(implode("\n", $transport->commands()), 'asdf install'))->toBe($installed);
})->with([
    'one blip' => [1, 2, true],
    'two blips' => [2, 3, true],
    'three blips' => [3, 3, false],
]);

test('a package source that fails is named in the reason', function () {
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0)->respondTo('apt-get update -y', "Err:5 https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble Release\n  404  Not Found [IP: 185.125.190.80 443]\nE: The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble Release' does not have a Release file.\n", 100);
    $connection = asdfConnection($transport);
    $connection->server()->forceFill(['name' => 'web-1'])->save();

    try {
        (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5');
        $this->fail('The install went ahead.');
    } catch (PackageSourceFailed $e) {
        expect($e->getMessage())->toBe('Refreshing the package lists on web-1 failed at https://ppa.launchpadcontent.net/ondrej/php/ubuntu. Fix or remove that source, then deploy again.')
            ->and($e->getPrevious())->toBeInstanceOf(SSHCommandFailedException::class)
            ->and($e->getPrevious()->output())->toContain('does not have a Release file');
    }

    expect(substr_count(implode("\n", $transport->commands()), 'apt-get update -y'))->toBe(1)
        ->and(implode("\n", $transport->commands()))->not->toContain('apt-get install');
});

test('a network blip while installing build packages is tried again', function (string $blip) {
    Sleep::fake();
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0)->respondToSequence('apt-get install', [[$blip, 100], ['', 0]]);
    $connection = asdfConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5');

    expect(substr_count(implode("\n", $transport->commands()), 'apt-get install'))->toBe(2)
        ->and($transport->budgetFor('apt-get install'))->toBe(1200);
})->with([
    'a name that does not resolve' => ["E: Failed to fetch http://ports.ubuntu.com/ubuntu-ports/pool/main/o/oniguruma/libonig-dev_6.9.9-1build1_arm64.deb  Temporary failure resolving 'ports.ubuntu.com'\n"],
    'a mirror that does not answer' => ["Err:1 http://ports.ubuntu.com/ubuntu-ports noble/main arm64 libonig5 arm64 6.9.9-1build1\n  Could not connect to ports.ubuntu.com:80 (185.125.190.39), connection timed out\n"],
    'a mirror that refuses' => ["Err:1 http://ports.ubuntu.com/ubuntu-ports noble/main arm64 libonig5 arm64 6.9.9-1build1\n  Unable to connect to ports.ubuntu.com:http:\n"],
]);

test('php is built with the postgresql drivers and phpredis, which php-build leaves out', function () {
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0);
    $connection = asdfConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5');

    $commands = $transport->commands();
    $install = $commands[asdfStep($commands, 'asdf install "$CORESMITH_RUNTIME" "$CORESMITH_VERSION"')];

    expect($install)->toContain("PHP_BUILD_CONFIGURE_OPTS='--with-pgsql --with-pdo-pgsql'")
        ->and($install)->toContain("PHP_BUILD_INSTALL_EXTENSION='redis'");
});

test('other runtimes are built without the php build settings', function () {
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0);
    $connection = asdfConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'nodejs', '24.15.0');

    expect(implode("\n", $transport->commands()))->not->toContain('PHP_BUILD_');
});

test('runtime installs run as the coresmith user, never root directly', function () {
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0);
    $connection = asdfConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'nodejs', '24.15.0');

    $commands = $transport->commands();

    expect(end($commands))->toContain('sudo -u coresmith env ');
});

test('installed versions are listed and sorted', function () {
    $connection = asdfConnection((new FakeSSHTransport)->queueResult("8.5.5\n8.4.17\n\n", 0));

    expect((new AsdfManager)->installedVersions($connection, 'php'))->toBe(['8.4.17', '8.5.5']);
});

test('an empty runtime directory yields no versions', function () {
    $connection = asdfConnection((new FakeSSHTransport)->queueResult('', 0));

    expect((new AsdfManager)->installedVersions($connection, 'php'))->toBe([]);
});

test('orphaned versions exclude those still in use', function () {
    $connection = asdfConnection((new FakeSSHTransport)->queueResult("8.5.5\n8.4.17\n", 0));

    expect((new AsdfManager)->orphanedVersions($connection, 'php', ['8.5.5']))->toBe(['8.4.17']);
});

test('build dependencies are distro specific', function () {
    expect(BuildDependencies::for('php', 'ubuntu'))->toContain('libonig-dev')
        ->and(BuildDependencies::for('php', 'rocky'))->toContain('oniguruma-devel')
        ->and(BuildDependencies::for('ruby', 'debian'))->toContain('zlib1g-dev')
        ->and(BuildDependencies::for('ruby', 'fedora'))->toContain('zlib-devel');
});

test('a runtime with no build dependencies yields an empty list', function () {
    expect(BuildDependencies::for('bun', 'ubuntu'))->toBe([])
        ->and(BuildDependencies::for('php', null))->toBe([]);
});

test('compiling a runtime gets thirty minutes', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0);

    app(AsdfManager::class)->install((new SSHConnection($server, $transport))->connect(), $server, 'php', '8.5.10');

    expect($transport->budgetFor('asdf install'))->toBe(AsdfManager::INSTALL_SECONDS)
        ->and(AsdfManager::INSTALL_SECONDS)->toBe(1800);
});

test('an npm-style network error during install is retried', function () {
    Sleep::fake();
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $transport = (new FakeSSHTransport)
        ->queueResult("no\n", 0)
        ->respondToSequence('asdf install', [["npm error code ETIMEDOUT\nnpm error network request failed\n", 1], ['', 0]]);

    app(AsdfManager::class)->install((new SSHConnection($server, $transport))->connect(), $server, 'bun', '1.4.2');

    expect(substr_count(implode("\n", $transport->commands()), 'asdf install "$CORESMITH_RUNTIME"'))->toBe(2);
});

test('an installed version list keeps only names that look like versions', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->respondTo("ls -1 '/opt/asdf-data/installs/php'", "8.5.10\nsystem\nbad;name\n8.5.10+x\n8.4.20\n");

    expect(app(AsdfManager::class)->installedVersions((new SSHConnection($server, $transport))->connect(), 'php'))->toBe(['8.4.20', '8.5.10']);
});

test('asdf commands carry their own environment and start in their own home, not the caller directory', function () {
    $command = AsdfManager::asSystemUser('asdf --version', ['CORESMITH_RUNTIME' => 'php']);

    expect($command)->toStartWith('sudo -u coresmith env ')
        ->and($command)->toContain("ASDF_DIR='/opt/asdf'")
        ->and($command)->toContain("ASDF_DATA_DIR='/opt/asdf-data'")
        ->and($command)->toContain("PATH='/opt/asdf/bin:/opt/asdf-data/shims:")
        ->and($command)->toContain("CORESMITH_RUNTIME='php'")
        ->and($command)->toEndWith("bash -c 'cd \"\$HOME\" && asdf --version'")
        ->and($command)->not->toContain('bash -lc');
});
