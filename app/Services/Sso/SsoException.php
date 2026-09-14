<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\Team;
use RuntimeException;

final class SsoException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'failed', public readonly ?Team $team = null)
    {
        parent::__construct($message);
    }
}
