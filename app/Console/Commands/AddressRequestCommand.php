<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Address\AddressChange;
use App\Services\Address\AddressGate;
use App\Services\Address\AddressRequest;
use App\Services\Address\InstallationAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use RuntimeException;

final class AddressRequestCommand extends Command
{
    private const POLL_SECONDS = 2;

    private const PICKUP_SECONDS = 30;

    private const GIVE_UP_SECONDS = 600;

    protected $signature = 'coresmith:address-request
        {host : The domain people should open Coresmith on}
        {--wait : Wait for the move to finish and say how it went}';

    protected $description = 'Check a new address the way Settings does, then ask the address service to move Coresmith to it';

    public function handle(AddressGate $gate, InstallationAddress $address): int
    {
        $host = InstallationAddress::normalise((string) $this->argument('host'));
        $verdict = $gate->check($host);

        if ($verdict->refusal !== null) {
            $this->error($verdict->refusal);

            return self::FAILURE;
        }

        AddressChange::begin($address->host(), $host);

        try {
            AddressRequest::submit($host);
        } catch (RuntimeException $unasked) {
            AddressChange::fail($unasked->getMessage());
            $this->error($unasked->getMessage());

            return self::FAILURE;
        }

        $this->line("Switching to {$host}.");

        foreach ($verdict->notes as $note) {
            $this->line($note);
        }

        return $this->option('wait') ? $this->await($host) : self::SUCCESS;
    }

    private function await(string $host): int
    {
        for ($waited = self::POLL_SECONDS; $waited <= self::GIVE_UP_SECONDS; $waited += self::POLL_SECONDS) {
            Sleep::sleep(self::POLL_SECONDS);
            $change = AddressChange::current();

            if (($change['state'] ?? null) === 'done') {
                $this->info("Coresmith now answers on {$host}.");

                return self::SUCCESS;
            }

            if (($change['state'] ?? null) === 'failed') {
                $this->error((string) ($change['error'] ?? "Coresmith couldn't move to {$host}."));

                return self::FAILURE;
            }

            if ($waited >= self::PICKUP_SECONDS && AddressRequest::withdraw()) {
                return $this->stop("Coresmith's address service didn't pick up the move to {$host}. Check it with systemctl status coresmith-address.path, then try again.");
            }
        }

        return $this->stop("Coresmith's move to {$host} didn't finish in " . intdiv(self::GIVE_UP_SECONDS, 60) . ' minutes. Check what happened with journalctl -u coresmith-address, then try again.');
    }

    private function stop(string $reason): int
    {
        AddressChange::fail($reason);
        $this->error($reason);

        return self::FAILURE;
    }
}
