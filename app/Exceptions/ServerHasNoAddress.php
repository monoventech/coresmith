<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Server;
use RuntimeException;

final class ServerHasNoAddress extends RuntimeException implements ExplainsFailure
{
    public static function forServer(Server $server): self
    {
        return new self("{$server->name} has no IP address yet. Coresmith is still creating it at the provider.");
    }
}
