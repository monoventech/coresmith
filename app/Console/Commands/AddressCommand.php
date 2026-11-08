<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Address\AddressChange;
use App\Services\Address\AddressRequest;
use App\Services\Address\AddressSwitch;
use Illuminate\Console\Command;
use Throwable;

final class AddressCommand extends Command
{
    protected $signature = 'coresmith:address';

    protected $description = 'Move Coresmith to the address requested from Settings';

    public function handle(AddressSwitch $switch): int
    {
        $host = AddressRequest::take();

        if ($host === null) {
            $this->line('No address change was requested.');

            return self::SUCCESS;
        }

        try {
            $switch->run($host);
        } catch (Throwable $stopped) {
            AddressChange::fail("Changing the address to {$host} stopped part way. Try again, and check Caddy's log with journalctl -u caddy if it keeps failing.");
            report($stopped);
        }

        $change = AddressChange::current();

        if (($change['state'] ?? null) !== 'done') {
            $this->error((string) ($change['error'] ?? "Coresmith couldn't move to {$host}."));

            return self::FAILURE;
        }

        $this->info("Coresmith now answers on {$host}.");

        return self::SUCCESS;
    }
}
