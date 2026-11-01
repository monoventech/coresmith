<?php

declare(strict_types=1);

use App\Services\Install\NativeProcessHandOff;
use App\Services\Install\ProcessHandOff;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcessHandOff;

function uninstallHandOff(bool $root = true): FakeProcessHandOff
{
    $handOff = new FakeProcessHandOff($root);
    app()->instance(ProcessHandOff::class, $handOff);

    return $handOff;
}

test('the uninstall command refuses anyone but root', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('This test asserts the non-root path and cannot run as root.');
    }

    $this->artisan('coresmith:uninstall')
        ->expectsOutput('Run this as root: sudo php artisan coresmith:uninstall')
        ->assertExitCode(1);
});

test('the uninstall command hands nothing over without root', function () {
    $handOff = uninstallHandOff(root: false);

    $this->artisan('coresmith:uninstall')
        ->expectsOutput('Run this as root: sudo php artisan coresmith:uninstall')
        ->assertExitCode(1);

    expect($handOff->replacements)->toBe([]);
});

test('the uninstall command says what it removes and wants the panel host typed', function () {
    config(['app.url' => 'https://panel.example.test']);
    $handOff = uninstallHandOff();

    $this->artisan('coresmith:uninstall')
        ->expectsOutput('This removes the Coresmith panel from this server:')
        ->expectsOutput("  - /opt/coresmith, /var/lib/coresmith, the panel's logs and the coresmith user")
        ->expectsOutput('A copy of its .env, backups and SQLite database is saved under /root first. Apps deployed on this machine keep running.')
        ->expectsQuestion('Type panel.example.test to remove Coresmith from this server', 'panel.example')
        ->expectsOutput('Nothing was removed.')
        ->assertExitCode(1);

    expect($handOff->replacements)->toBe([]);
});

test('the panel host hands this process over to the uninstaller', function () {
    config(['app.url' => 'https://panel.example.test']);
    $handOff = uninstallHandOff();
    $script = base_path('scripts/uninstall.sh');

    $this->artisan('coresmith:uninstall')
        ->expectsQuestion('Type panel.example.test to remove Coresmith from this server', 'panel.example.test')
        ->expectsOutput("Coresmith couldn't start the uninstaller: the test kept its own process. Run sudo bash {$script} --yes instead.")
        ->assertExitCode(1);

    expect($handOff->replacements)->toBe([['program' => '/bin/bash', 'arguments' => [$script, '--yes']]])
        ->and(is_executable($script))->toBeTrue();
});

test('without a panel address the word uninstall confirms', function () {
    config(['app.url' => '']);
    $handOff = uninstallHandOff();

    $this->artisan('coresmith:uninstall')
        ->expectsQuestion('Type uninstall to remove Coresmith from this server', 'uninstall')
        ->assertExitCode(1);

    expect($handOff->replacements)->toHaveCount(1);
});

test('the native hand-off replaces the process it runs in', function () {
    $autoload = base_path('vendor/autoload.php');
    $code = 'require ' . var_export($autoload, true) . '; echo (new ' . NativeProcessHandOff::class . ')->replaceWith(...json_decode($argv[1], true));';

    $handedOff = new Process([PHP_BINARY, '-r', $code, json_encode(['/bin/echo', ['handed', 'off']])]);
    $handedOff->run();

    $failed = new Process([PHP_BINARY, '-r', $code, json_encode(['/nonexistent/uninstall', []])]);
    $failed->run();

    expect($handedOff->getOutput())->toBe("handed off\n")
        ->and($failed->getOutput())->toBe('no such file or directory')
        ->and((new NativeProcessHandOff)->runningAsRoot())->toBe(posix_geteuid() === 0);
});
