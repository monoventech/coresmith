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

test('root hands this process to the uninstaller, which asks its own question', function () {
    $handOff = uninstallHandOff();
    $script = base_path('scripts/uninstall.sh');

    $this->artisan('coresmith:uninstall')
        ->doesntExpectOutputToContain('This removes')
        ->doesntExpectOutputToContain('the coresmith user')
        ->expectsOutput("Coresmith couldn't start the uninstaller: the test kept its own process. Run sudo bash {$script} instead.")
        ->assertExitCode(1);

    expect($handOff->replacements)->toBe([['program' => '/bin/bash', 'arguments' => [$script]]])
        ->and(is_executable($script))->toBeTrue();
});

test('the machine test and the readme answer the question the uninstaller asks', function () {
    expect((string) file_get_contents(base_path('tests/Machine/updates.sh')))->toContain("printf 'uninstall\\n' | php artisan coresmith:uninstall")
        ->and((string) file_get_contents(base_path('README.md')))->toContain('It asks you to type `uninstall` to confirm,');
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
