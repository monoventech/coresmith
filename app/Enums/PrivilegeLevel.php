<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum PrivilegeLevel: string
{
    case Standard = 'standard';
    case Extended = 'extended';
    case Full = 'full';

    private const STANDARD = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'ALTER',
        'INDEX',
        'DROP',
        'REFERENCES',
        'TRIGGER',
        'LOCK TABLES',
        'EXECUTE',
        'CREATE TEMPORARY TABLES',
    ];

    private const EXTENDED_EXTRA = [
        'CREATE VIEW',
        'CREATE ROUTINE',
        'ALTER ROUTINE',
        'EVENT',
        'SHOW VIEW',
    ];

    private const FULL_EXTRA = [
        'CREATE VIEW',
        'CREATE ROUTINE',
        'ALTER ROUTINE',
        'EVENT',
        'SHOW VIEW',
    ];

    /**
     * @return list<string>
     */
    public function mysqlGrants(): array
    {
        return match ($this) {
            self::Standard => self::STANDARD,
            self::Extended => [...self::STANDARD, ...self::EXTENDED_EXTRA],
            self::Full => [...self::STANDARD, ...self::FULL_EXTRA],
        };
    }
}
