<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

final class PublicAddress
{
    public static function is(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = (string) inet_pton($address);

        return strlen($packed) !== 4 || ord($packed[0]) !== 100 || (ord($packed[1]) & 0xC0) !== 0x40;
    }
}
