<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Server;
use App\Services\Deployment\LogSanitizer;

final class ProvisioningLog
{
    public static function append(Server $server, string $line, bool $stamped = true): void
    {
        $line = app(LogSanitizer::class)->sanitize($line);

        $server->forceFill([
            'provisioning_log' => trim((string) $server->provisioning_log . "\n" . ($stamped ? '[' . now()->format('H:i:s') . '] ' : '') . $line),
        ])->save();
    }
}
