<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Address\CaddyAdmin;
use Illuminate\Console\Command;
use RuntimeException;

final class CaddySyncCommand extends Command
{
    protected $signature = 'coresmith:caddy-sync';

    protected $description = "Bring Coresmith's routes in Caddy up to date on a machine whose Caddy also serves apps";

    public function handle(CaddyAdmin $caddy): int
    {
        if (!$caddy->managed()) {
            $this->line("Caddy serves Coresmith from its own Caddyfile here, so there's nothing to update.");

            return self::SUCCESS;
        }

        try {
            $caddy->reload();
        } catch (RuntimeException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("Coresmith's routes in Caddy are up to date.");

        return self::SUCCESS;
    }
}
