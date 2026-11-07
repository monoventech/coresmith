<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Support;

final class Mailer
{
    public static function name(): string
    {
        return (string) config('mail.default');
    }

    public static function writesToLog(): bool
    {
        return self::name() === 'log';
    }

    public static function defined(): bool
    {
        return is_array(config('mail.mailers.' . self::name()));
    }

    public static function delivers(): bool
    {
        return self::defined() && !self::writesToLog() && self::name() !== 'array';
    }
}
