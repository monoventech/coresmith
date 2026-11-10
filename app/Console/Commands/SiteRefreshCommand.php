<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Address\AddressChange;
use App\Services\Address\AtomicFile;
use App\Services\Address\CaddyAdmin;
use App\Services\Address\InstallationAddress;
use App\Services\Address\SiteFile;
use Illuminate\Console\Command;
use RuntimeException;

final class SiteRefreshCommand extends Command
{
    protected $signature = 'coresmith:site-refresh';

    protected $description = "Rewrite Coresmith's own Caddy site from this release's template, and reload Caddy when it changed";

    public function handle(InstallationAddress $address, SiteFile $site, CaddyAdmin $caddy): int
    {
        if (!$address->managed()) {
            return self::SUCCESS;
        }

        $path = (string) config('coresmith.address.site');
        $host = $address->host();
        $change = AddressChange::current();
        $redirects = ($change['state'] ?? null) === 'done' && ($change['to'] ?? null) === $host && ($change['from'] ?? $host) !== $host ? [$change['from'] => $host] : [];
        $before = @file_get_contents($path);

        if ($before === $site->render([$host], $redirects)) {
            return self::SUCCESS;
        }

        try {
            $site->write([$host], $redirects);
            $caddy->reload();
        } catch (RuntimeException $refused) {
            $this->restore($path, $before, $caddy);
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("Caddy now serves {$host} with this release's site.");

        return self::SUCCESS;
    }

    private function restore(string $path, string|false $before, CaddyAdmin $caddy): void
    {
        if ($before === false) {
            return;
        }

        try {
            AtomicFile::write($path, $before, 0640, "Coresmith couldn't put its Caddy site back at {$path}");
            $caddy->reload();
        } catch (RuntimeException $unrestored) {
            report($unrestored);
        }
    }
}
