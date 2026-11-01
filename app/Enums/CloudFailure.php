<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum CloudFailure: string
{
    case TokenRejected = 'token_rejected';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case Busy = 'busy';
    case RateLimited = 'rate_limited';
    case Refused = 'refused';
    case Unavailable = 'unavailable';
    case Unreachable = 'unreachable';

    public function retryable(): bool
    {
        return match ($this) {
            self::Busy, self::RateLimited, self::Unavailable, self::Unreachable => true,
            self::TokenRejected, self::Forbidden, self::NotFound, self::Refused => false,
        };
    }
}
