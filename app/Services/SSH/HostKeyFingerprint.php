<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

final class HostKeyFingerprint
{
    public static function fromHostKey(string $hostKey): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $hostKey, true)), '=');
    }

    public static function fromPublicKey(string $publicKey): ?string
    {
        $blob = self::blob($publicKey);

        return $blob === null ? null : self::fromHostKey($blob);
    }

    public static function md5FromPublicKey(string $publicKey): ?string
    {
        $blob = self::blob($publicKey);

        return $blob === null ? null : implode(':', str_split(md5($blob), 2));
    }

    private static function blob(string $publicKey): ?string
    {
        $parts = preg_split('/\s+/', trim($publicKey)) ?: [];
        $blob = isset($parts[1]) ? base64_decode($parts[1], true) : false;

        return $blob === false || $blob === '' ? null : $blob;
    }
}
