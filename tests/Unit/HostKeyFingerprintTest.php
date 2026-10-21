<?php

declare(strict_types=1);

use App\Services\SSH\HostKeyFingerprint;

test('formats an openssh style sha256 fingerprint', function () {
    $fingerprint = HostKeyFingerprint::fromHostKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5');

    expect($fingerprint)->toStartWith('SHA256:')->and($fingerprint)->not->toEndWith('=');
});

test('is stable for the same key', function () {
    expect(HostKeyFingerprint::fromHostKey('key-material'))
        ->toBe(HostKeyFingerprint::fromHostKey('key-material'));
});

test('differs for different keys', function () {
    expect(HostKeyFingerprint::fromHostKey('key-a'))->not->toBe(HostKeyFingerprint::fromHostKey('key-b'));
});

test('matches the openssh reference digest', function () {
    expect(HostKeyFingerprint::fromHostKey('abc'))
        ->toBe('SHA256:' . rtrim(base64_encode(hash('sha256', 'abc', true)), '='));
});

test('formats a public key as the colon separated md5 fingerprint providers list', function () {
    expect(HostKeyFingerprint::md5FromPublicKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 coresmith-test'))
        ->toBe('17:35:e1:e3:93:03:56:7d:6d:58:c3:98:5c:5b:c1:c2');
});

test('the md5 fingerprint does not depend on the comment or the whitespace around the key', function () {
    $blob = 'AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4';

    expect(HostKeyFingerprint::md5FromPublicKey("  ssh-ed25519 {$blob}\n"))
        ->toBe(HostKeyFingerprint::md5FromPublicKey("ssh-ed25519 {$blob} someone@laptop"))
        ->toBe('17:35:e1:e3:93:03:56:7d:6d:58:c3:98:5c:5b:c1:c2');
});

test('an unreadable public key has no md5 fingerprint', function (string $publicKey) {
    expect(HostKeyFingerprint::md5FromPublicKey($publicKey))->toBeNull();
})->with(['', '   ', 'ssh-ed25519', 'ssh-ed25519 not*base64', 'ssh-ed25519 =']);

test('the sha256 fingerprint of a public key matches what ssh-keygen prints', function () {
    expect(HostKeyFingerprint::fromPublicKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 coresmith-test'))
        ->toBe('SHA256:4tuIdFPGvascdN/EivEGNJyqg/5BjbbXAHuVWTItMk0')
        ->and(HostKeyFingerprint::fromPublicKey('ssh-ed25519'))->toBeNull();
});
