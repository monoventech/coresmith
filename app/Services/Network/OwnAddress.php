<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

final class OwnAddress
{
    public function __construct(private readonly LocalAddresses $local) {}

    public function is(string $address): bool
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4 && ($packed[0] === "\x7f" || $packed === "\0\0\0\0")) {
            return true;
        }

        $own = array_map(inet_pton(...), array_filter([...$this->local->all(), '::1', '::'], fn(string $local): bool => filter_var($local, FILTER_VALIDATE_IP) !== false));

        return in_array($packed, $own, true);
    }
}
