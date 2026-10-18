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

test('the connection only offers the ssh algorithms the security checklist allows', function () {
    $transport = new Phpseclib3Transport;
    $transport->connect('192.0.2.1', 22, 5);

    $ssh = (new ReflectionProperty(Phpseclib3Transport::class, 'ssh'))->getValue($transport);
    $preferred = (new ReflectionProperty(SSH2::class, 'preferred'))->getValue($ssh);

    expect(array_values($preferred['kex']))->toBe(['curve25519-sha256', 'curve25519-sha256@libssh.org'])
        ->and(array_values($preferred['hostkey']))->toBe(['ssh-ed25519', 'rsa-sha2-512', 'rsa-sha2-256']);

    foreach (['client_to_server', 'server_to_client'] as $direction) {
        expect(array_values($preferred[$direction]['crypt']))->toBe(['chacha20-poly1305@openssh.com', 'aes256-gcm@openssh.com', 'aes128-gcm@openssh.com'])
            ->and(array_values($preferred[$direction]['mac']))->toBe(['hmac-sha2-512-etm@openssh.com', 'hmac-sha2-256-etm@openssh.com'])
            ->and(array_values($preferred[$direction]['comp']))->toBe(['none']);
    }
});
