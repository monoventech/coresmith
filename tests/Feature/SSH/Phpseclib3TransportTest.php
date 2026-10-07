<?php

declare(strict_types=1);

use App\Services\SSH\Phpseclib3Transport;
use phpseclib3\Net\SSH2;

test('the exit status left by the previous command is cleared before the next one', function () {
    $ssh = new SSH2('192.0.2.1');
    (new ReflectionProperty(SSH2::class, 'exit_status'))->setValue($ssh, 0);

    expect($ssh->getExitStatus())->toBe(0);

    Phpseclib3Transport::forgetExitStatus($ssh);

    expect($ssh->getExitStatus())->toBeFalse();
});

test('a command that never reported an exit status counts as failed', function () {
    $transport = new Phpseclib3Transport;
    $transport->connect('192.0.2.1', 22, 5);

    expect($transport->exitStatus())->toBe(-1)
        ->and($transport->timedOut())->toBeFalse();
});
