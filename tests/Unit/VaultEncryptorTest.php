<?php

declare(strict_types=1);

use App\Exceptions\VaultDecryptionException;
use App\Services\VaultEncryptor;

function vaultKey(): string
{
    return base64_encode(str_repeat('k', 32));
}

test('round-trips a value', function () {
    $vault = new VaultEncryptor(vaultKey());

    expect($vault->decrypt($vault->encrypt('super-secret')))->toBe('super-secret');
});

test('produces a different ciphertext each time for the same plaintext', function () {
    $vault = new VaultEncryptor(vaultKey());

    expect($vault->encrypt('same'))->not->toBe($vault->encrypt('same'));
});

test('rejects a missing key', function () {
    new VaultEncryptor(null);
})->throws(InvalidArgumentException::class);

test('rejects a key of the wrong length', function () {
    new VaultEncryptor(base64_encode('too-short'));
})->throws(InvalidArgumentException::class);

test('rejects a value encrypted under a different key', function () {
    $payload = (new VaultEncryptor(vaultKey()))->encrypt('secret');

    (new VaultEncryptor(base64_encode(str_repeat('x', 32))))->decrypt($payload);
})->throws(VaultDecryptionException::class);

test('rejects a tampered payload', function () {
    $vault = new VaultEncryptor(vaultKey());
    $payload = base64_decode($vault->encrypt('secret'));
    $payload[strlen($payload) - 1] = $payload[strlen($payload) - 1] === 'A' ? 'B' : 'A';

    $vault->decrypt(base64_encode($payload));
})->throws(VaultDecryptionException::class);
