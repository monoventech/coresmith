<?php

declare(strict_types=1);

use App\Services\SSH\HostKeyFingerprint;

const RSA_HOST_KEY_BLOB = 'AAAAB3NzaC1yc2EAAAADAQABAAAAgQCv18shDIVgYG9U8I6NPjovihEbadZjGC5VvpnRd9tqhs4adk1IvYIbRhIdeXDZiG0WWkgA8eB17o80GIXMpW/Qj1TFnCyHhO+rhu2NOq//aIkVUoE3XYsEzPxo0C5cgHKC5e4aoEuY3DFA+fWQWnb+bmvxquJdgQhG4RqoY7c0ww==';

test('a host key as the transport reports it has the fingerprint ssh-keygen prints', function () {
    expect(HostKeyFingerprint::fromPublicKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4'))
        ->toBe('SHA256:4tuIdFPGvascdN/EivEGNJyqg/5BjbbXAHuVWTItMk0');
});

test('an RSA host key has one fingerprint whichever signature algorithm names it', function (string $algorithm) {
    expect(HostKeyFingerprint::fromPublicKey($algorithm . ' ' . RSA_HOST_KEY_BLOB))
        ->toBe('SHA256:g/kGi3cfVVisrykdQLnRg+GiSfJih8/yIzKY+ODHA/0');
})->with(['ssh-rsa', 'rsa-sha2-256', 'rsa-sha2-512']);

test('different host keys have different fingerprints', function () {
    expect(HostKeyFingerprint::fromPublicKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4'))
        ->not->toBe(HostKeyFingerprint::fromPublicKey('ssh-rsa ' . RSA_HOST_KEY_BLOB));
});

test('the old fingerprint is the digest of the text the transport reported', function () {
    expect(HostKeyFingerprint::legacy('abc'))->toBe('SHA256:ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0');
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
