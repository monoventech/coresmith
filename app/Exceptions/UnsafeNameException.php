<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class UnsafeNameException extends InvalidArgumentException
{
    public static function slug(string $value): self
    {
        return new self("[{$value}] is not a valid application slug. Slugs are lowercase letters, digits and hyphens only.");
    }

    public static function process(string $value): self
    {
        return new self("[{$value}] is not a valid process name. Process names are lowercase letters, digits and hyphens only.");
    }

    public static function databaseIdentifier(string $value): self
    {
        return new self("[{$value}] is not a valid database name or user. SQL identifiers cannot be parameterised, so only letters, digits and underscores are accepted.");
    }

    public static function systemUser(string $value): self
    {
        return new self("[{$value}] is not a valid system user name.");
    }
}
