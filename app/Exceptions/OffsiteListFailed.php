<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Network\OutboundAddress;
use RuntimeException;

final class OffsiteListFailed extends RuntimeException
{
    public static function unreachable(string $why): self
    {
        return new self($why === OutboundAddress::UNRESOLVED ? "Coresmith Cloud couldn't find an address for its endpoint." : "Its endpoint points at a private address, so Coresmith Cloud can't reach it.");
    }

    public static function refused(?string $said): self
    {
        return new self($said === null || trim($said) === '' ? "S3 didn't answer with a listing." : 'S3 said: ' . rtrim(trim($said), '.') . '.');
    }
}
