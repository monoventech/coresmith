<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\VaultDecryptionException;
use InvalidArgumentException;

final class VaultEncryptor
{
    private const CIPHER = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    private readonly string $key;

    public function __construct(?string $encodedKey)
    {
        if ($encodedKey === null || $encodedKey === '') {
            throw new InvalidArgumentException('CORESMITH_VAULT_KEY is not set. Run "php artisan coresmith:vault-key" to generate one.');
        }

        $key = base64_decode($encodedKey, true);

        if ($key === false || strlen($key) !== 32) {
            throw new InvalidArgumentException('CORESMITH_VAULT_KEY must be a base64-encoded 256-bit key.');
        }

        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new InvalidArgumentException('Vault encryption failed.');
        }

        return base64_encode($nonce.$ciphertext.$tag);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw VaultDecryptionException::forPayload();
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, -self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES, -self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plaintext === false) {
            throw VaultDecryptionException::forPayload();
        }

        return $plaintext;
    }
}
