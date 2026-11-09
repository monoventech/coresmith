<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Address\InstallationAddress;
use App\Services\Address\Reachability;
use Illuminate\Console\Command;

final class AddressCheckCommand extends Command
{
    protected $signature = 'coresmith:address-check {host : The domain people should open Coresmith on}';

    protected $description = 'Check from outside whether a domain reaches this server on ports 80 and 443, and say what to fix';

    public function handle(Reachability $reachability): int
    {
        $host = InstallationAddress::normalise((string) $this->argument('host'));
        $report = $reachability->check($host);

        if ($report->ready === true) {
            $this->info("{$host} reaches this server from the internet.");
        }

        foreach ([...$report->problems, ...$report->notes] as $line) {
            $this->line($line);
        }

        return $report->ready === false ? self::FAILURE : self::SUCCESS;
    }
}
