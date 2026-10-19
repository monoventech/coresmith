<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UnsafeNameException;

final class SafeName
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    public const PROCESS_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    public const SYSTEM_USER_PATTERN = '/^[a-z_][a-z0-9_-]{0,31}$/';

    public const DATABASE_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/';

    public const DOMAIN_PATTERN = '/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';

    public const BRANCH_PATTERN = '/^(?![-\/.])(?!.*(?:\.\.|\/\/|\/\.|\.lock\/))(?!.*(?:\/|\.|\.lock)\z)[A-Za-z0-9._\/+-]{1,255}\z/';

    public const RELATIVE_PATH_PATTERN = '~^/?(?:(?!\.\.?(?:/|\z))[A-Za-z0-9._-]+(?:/(?!\.\.?(?:/|\z))[A-Za-z0-9._-]+)*/?)?\z~';

    public const GIT_URL_PATTERN = '/^(https:\/\/[^\s]+|git@[A-Za-z0-9.-]+:[^\s]+)$/D';

    public static function isSlug(?string $value): bool
    {
        return $value !== null && preg_match(self::SLUG_PATTERN, $value) === 1;
    }

    public static function isProcess(?string $value): bool
    {
        return $value !== null && preg_match(self::PROCESS_PATTERN, $value) === 1;
    }

    public static function isSystemUser(?string $value): bool
    {
        return $value !== null && preg_match(self::SYSTEM_USER_PATTERN, $value) === 1;
    }

    public static function isDatabaseIdentifier(?string $value): bool
    {
        return $value !== null && preg_match(self::DATABASE_PATTERN, $value) === 1;
    }

    public static function assertDatabaseIdentifier(?string $value): string
    {
        if (!self::isDatabaseIdentifier($value)) {
            throw UnsafeNameException::databaseIdentifier((string) $value);
        }

        return (string) $value;
    }

    public static function isDomain(?string $value): bool
    {
        return $value !== null
            && strlen($value) <= 253
            && preg_match(self::DOMAIN_PATTERN, $value) === 1;
    }

    public static function assertDomain(?string $value): string
    {
        if (!self::isDomain($value)) {
            throw UnsafeNameException::domain((string) $value);
        }

        return (string) $value;
    }

    public static function assertSlug(?string $value): string
    {
        if (!self::isSlug($value)) {
            throw UnsafeNameException::slug((string) $value);
        }

        return (string) $value;
    }

    public static function assertProcess(?string $value): string
    {
        if (!self::isProcess($value)) {
            throw UnsafeNameException::process((string) $value);
        }

        return (string) $value;
    }

    public static function assertSystemUser(?string $value): string
    {
        if (!self::isSystemUser($value)) {
            throw UnsafeNameException::systemUser((string) $value);
        }

        return (string) $value;
    }

    public static function isBranch(?string $value): bool
    {
        return $value !== null && preg_match(self::BRANCH_PATTERN, $value) === 1;
    }

    public static function assertBranch(?string $value): string
    {
        if (!self::isBranch($value)) {
            throw UnsafeNameException::branch((string) $value);
        }

        return (string) $value;
    }

    public static function isRelativePath(?string $value): bool
    {
        return $value !== null && strlen($value) <= 255 && preg_match(self::RELATIVE_PATH_PATTERN, $value) === 1;
    }

    public static function assertRelativePath(?string $value): string
    {
        if (!self::isRelativePath($value)) {
            throw UnsafeNameException::relativePath((string) $value);
        }

        return (string) $value;
    }
}
