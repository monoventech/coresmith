<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Server;
use RuntimeException;

final class PackageSourceFailed extends RuntimeException implements ExplainsFailure
{
    public static function refreshing(Server $server, string $source, SSHCommandFailedException $failure): self
    {
        return new self("Refreshing the package lists on {$server->name} failed at {$source}. Fix or remove that source, then deploy again.", 0, $failure);
    }
}
