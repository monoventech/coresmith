<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Dns;

final class NativeDnsResolver implements DnsResolver
{
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (!is_array($records)) {
            return [];
        }

        return array_values(array_map(
            fn(array $record): string => isset($record['entries']) ? implode('', (array) $record['entries']) : (string) ($record['txt'] ?? ''),
            $records,
        ));
    }
}
