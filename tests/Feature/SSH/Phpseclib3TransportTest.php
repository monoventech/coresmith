<?php

declare(strict_types=1);

use App\Services\SSH\Phpseclib3Transport;
use phpseclib3\Crypt\EC;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

test('the exit status left by the previous command is cleared before the next one', function () {
    $ssh = new SSH2('192.0.2.1');
    (new ReflectionProperty(SSH2::class, 'exit_status'))->setValue($ssh, 0);

    expect($ssh->getExitStatus())->toBe(0);

    Phpseclib3Transport::forgetExitStatus($ssh);

    expect($ssh->getExitStatus())->toBeFalse();
});

function phpseclibTransportUploading(bool $accepted): Phpseclib3Transport
{
    $transport = new Phpseclib3Transport;
    $sftp = new class('192.0.2.1') extends SFTP
    {
        public bool $accepted = true;

        public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
        {
            return $this->accepted;
        }

        public function getLastSFTPError()
        {
            return $this->accepted ? '' : 'SSH_FX_PERMISSION_DENIED: Permission denied';
        }
    };
    $sftp->accepted = $accepted;

    (new ReflectionProperty(Phpseclib3Transport::class, 'key'))->setValue($transport, EC::createKey('Ed25519'));
    (new ReflectionProperty(Phpseclib3Transport::class, 'sftp'))->setValue($transport, $sftp);

    return $transport;
}

test('an upload the server refuses fails with the path and the reason, never the content', function () {
    $thrown = null;

    try {
        phpseclibTransportUploading(accepted: false)->uploadContent('KEEP-THIS-SECRET', '/etc/coresmith/env/shop.env');
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe('Could not upload [/etc/coresmith/env/shop.env]: SSH_FX_PERMISSION_DENIED: Permission denied')
        ->and($thrown->getMessage())->not->toContain('KEEP-THIS-SECRET');
});

test('an upload the server accepts returns quietly', function () {
    phpseclibTransportUploading(accepted: true)->uploadContent('content', '/tmp/coresmith-upload.Fake000001');
})->throwsNoExceptions();

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
