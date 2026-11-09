<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

final class NativeLocalAddresses implements LocalAddresses
{
    public function all(): array
    {
        $addresses = [];

        foreach (net_get_interfaces() ?: [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                $address = (string) ($unicast['address'] ?? '');

                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
