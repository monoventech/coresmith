<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class SSHPasswordRefused extends RuntimeException
{
    public function __construct(string $message, public readonly bool $passwordsAllowed)
    {
        parent::__construct($message);
    }

    public static function forUser(string $ipAddress, string $user, bool $passwordsAllowed): self
    {
        return new self(
            $passwordsAllowed ? "{$ipAddress} refused the password for {$user}." : "{$ipAddress} does not accept passwords over SSH.",
            $passwordsAllowed,
        );
    }
}
