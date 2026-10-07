<?php

declare(strict_types=1);

use App\Exceptions\RuntimeUnavailable;
use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\Runtime\RuntimeBinaries;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\FakeSSHTransport;

function binariesConnection(FakeSSHTransport $transport): SSHConnection
{
    return (new SSHConnection(Server::factory()->create(['host_key_fingerprint' => null, 'ssh_user' => 'root']), $transport))->connect();
}

test('php is checked for php, php-fpm and composer, and its extensions are listed', function () {
    $transport = (new FakeSSHTransport)->respondTo('-m 2>/dev/null', "[PHP Modules]\nCore\nmbstring\nopenssl\n\n[Zend Modules]\n");

    $notes = app(RuntimeBinaries::class)->ensure(binariesConnection($transport), 'php', '8.5.10');

    expect($notes)->toBe(['php extensions: Core mbstring openssl'])
        ->and(implode("\n", $transport->commands()))->not->toContain('composer-setup.php');
});

test('composer is installed beside php when asdf left it out, after checking the installer signature', function () {
    $transport = (new FakeSSHTransport)->respondTo("test -x '/opt/asdf-data/installs/php/8.5.10/bin/composer'", '', 1);

    $notes = app(RuntimeBinaries::class)->ensure(binariesConnection($transport), 'php', '8.5.10');
    $install = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'composer-setup.php'));

    expect($install)->toContain('sudo -u coresmith')
        ->and($install)->toContain('https://composer.github.io/installer.sig')
        ->and($install)->toContain('sha384')
        ->and($install)->toContain('--install-dir=')
        ->and($notes[0])->toBe('composer: installed beside PHP 8.5.10, installer checksum verified');
});

test('a missing binary fails the install by name', function (string $runtime, string $version, string $binary, string $message) {
    $transport = (new FakeSSHTransport)->respondTo("test -x '/opt/asdf-data/installs/{$runtime}/{$version}/{$binary}'", '', 1);

    expect(fn() => app(RuntimeBinaries::class)->ensure(binariesConnection($transport), $runtime, $version))
        ->toThrow(RuntimeUnavailable::class, $message);
})->with([
    'php-fpm' => ['php', '8.5.10', 'sbin/php-fpm', 'PHP 8.5.10 finished installing, but php-fpm is missing. Read the install log above, then deploy again.'],
    'npm' => ['nodejs', '24.21.0', 'bin/npm', 'Node.js 24.21.0 finished installing, but npm is missing. Read the install log above, then deploy again.'],
    'bundle' => ['ruby', '3.4.10', 'bin/bundle', 'Ruby 3.4.10 finished installing, but bundle is missing. Read the install log above, then deploy again.'],
]);

test('a node version without corepack gets it, so pnpm and yarn projects still install', function () {
    $transport = (new FakeSSHTransport)->respondTo("test -x '/opt/asdf-data/installs/nodejs/26.9.0/bin/corepack'", '', 1);

    $notes = app(RuntimeBinaries::class)->ensure(binariesConnection($transport), 'nodejs', '26.9.0');

    expect(implode("\n", $transport->commands()))->toContain('npm install -g corepack')
        ->and($notes)->toBe(['corepack: installed, because this Node.js version no longer ships it']);
});

test('a composer download that fails on the network is tried again', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo("test -x '/opt/asdf-data/installs/php/8.5.10/bin/composer'", '', 1)
        ->respondToSequence('composer-setup.php', [["curl: (6) Could not resolve host: getcomposer.org\n", 6], ['', 0]]);

    $notes = app(RuntimeBinaries::class)->ensure(binariesConnection($transport), 'php', '8.5.10');

    expect(array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'composer-setup.php'))))->toHaveCount(2)
        ->and($transport->budgetFor('composer-setup.php'))->toBe(SSHTransport::COMMAND_SECONDS)
        ->and($notes[0])->toBe('composer: installed beside PHP 8.5.10, installer checksum verified');
});

test('a composer installer that does not match its checksum is not tried again', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo("test -x '/opt/asdf-data/installs/php/8.5.10/bin/composer'", '', 1)
        ->respondTo('composer-setup.php', "The Composer installer did not match its published checksum, so it was not run.\n", 1);

    expect(fn() => app(RuntimeBinaries::class)->ensure(binariesConnection($transport), 'php', '8.5.10'))->toThrow(SSHCommandFailedException::class);

    expect(array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'composer-setup.php'))))->toHaveCount(1);
});

test('a corepack download that fails on the network is tried again', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo("test -x '/opt/asdf-data/installs/nodejs/26.9.0/bin/corepack'", '', 1)
        ->respondToSequence('npm install -g corepack', [["npm error code ECONNRESET\nnpm error network aborted\n", 1], ['', 0]]);

    $notes = app(RuntimeBinaries::class)->ensure(binariesConnection($transport), 'nodejs', '26.9.0');

    expect(array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'npm install -g corepack'))))->toHaveCount(2)
        ->and($notes)->toBe(['corepack: installed, because this Node.js version no longer ships it']);
});

test('the composer installer removes its temporary directory whether or not the checksum matches', function (bool $matches, int $status) {
    $scratch = sys_get_temp_dir() . '/coresmith-composer-' . Str::random(8);
    $setup = "<?php foreach (\$argv as \$arg) { if (str_starts_with(\$arg, '--install-dir=')) { file_put_contents(substr(\$arg, 14) . '/composer', 'composer'); } }";

    File::ensureDirectoryExists($scratch . '/tmp');
    File::ensureDirectoryExists($scratch . '/fake');
    File::ensureDirectoryExists($scratch . '/php');
    File::put($scratch . '/setup.php', $setup);
    File::put($scratch . '/installer.sig', $matches ? hash('sha384', $setup) : str_repeat('0', 96));
    File::put($scratch . '/fake/curl', "#!/usr/bin/env bash\ncase \"\$2\" in\n  *installer.sig) cp '{$scratch}/installer.sig' \"\$4\" ;;\n  *) cp '{$scratch}/setup.php' \"\$4\" ;;\nesac\n");
    chmod($scratch . '/fake/curl', 0755);

    $script = (fn(): string => $this->composerScript())->call(app(RuntimeBinaries::class));
    $result = Process::env([
        'PATH' => $scratch . '/fake:' . getenv('PATH'),
        'TMPDIR' => $scratch . '/tmp',
        'CORESMITH_PHP' => PHP_BINARY,
        'CORESMITH_BIN' => $scratch . '/php',
    ])->run(['bash', '-c', $script]);

    $left = File::directories($scratch . '/tmp');
    $installed = File::exists($scratch . '/php/composer');

    File::deleteDirectory($scratch);

    expect($result->exitCode())->toBe($status)
        ->and($left)->toBe([])
        ->and($installed)->toBe($matches);
})->with([
    'the checksum matches' => [true, 0],
    'the checksum does not match' => [false, 1],
]);
