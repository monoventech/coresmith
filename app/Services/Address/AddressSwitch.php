<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

final class AddressSwitch
{
    public const WAIT_SECONDS = 180;

    public const PROBE_SECONDS = 5;

    public function __construct(
        private readonly InstallationAddress $address,
        private readonly SiteFile $site,
        private readonly CaddyAdmin $caddy,
        private readonly CertificateProbe $probe,
        private readonly EnvironmentFile $environment,
        private readonly TailscaleCheck $tailscale,
    ) {}

    public function run(string $host): void
    {
        $from = $this->address->host();
        $source = InstallationAddress::certificate($host);
        $refusal = $this->servesApps() && !$this->caddy->managed()
            ? 'This machine also serves apps, so Coresmith leaves its web server setup alone. Run the installer again with --domain instead.'
            : ($source === CertificateSource::Tailscale ? $this->tailscale->refusal($host) : null);

        if ($refusal !== null) {
            AddressChange::fail($refusal);

            return;
        }

        try {
            $this->site->write([$from, $host]);
            $this->caddy->reload();
        } catch (RuntimeException $refused) {
            $this->restore($from);
            AddressChange::fail($refused->getMessage());

            return;
        }

        if (!$this->certified($host, $source)) {
            $this->restore($from);
            AddressChange::fail($this->uncertified($host, $source));

            return;
        }

        $before = ['APP_URL' => (string) config('app.url'), 'VITE_REVERB_HOST' => (string) config('coresmith.live.socket.host')];

        try {
            $this->environment->set(['APP_URL' => 'https://' . InstallationAddress::urlHost($host), 'VITE_REVERB_HOST' => InstallationAddress::urlHost($host)]);
        } catch (RuntimeException $unsaved) {
            $this->restore($from);
            AddressChange::fail($unsaved->getMessage());

            return;
        }

        if (app()->configurationIsCached() && Artisan::call('config:cache') !== 0) {
            $this->environment->set($before);
            $this->restore($from);
            AddressChange::fail("Coresmith couldn't load its new configuration, so it stays on {$from}.");

            return;
        }

        AddressChange::finish();
        activity()->withProperties(['from' => $from, 'to' => $host])->log('address_changed');

        try {
            $this->site->write([$host], [$from => $host]);
            $this->caddy->reload();
        } catch (RuntimeException $unredirected) {
            report($unredirected);
        }

        foreach (['horizon:terminate', 'reverb:restart'] as $restart) {
            try {
                Artisan::call($restart);
            } catch (Throwable $unrestarted) {
                report($unrestarted);
            }
        }
    }

    private function servesApps(): bool
    {
        return str_contains((string) @file_get_contents((string) config('coresmith.address.apps_config')), '"apps"');
    }

    private function certified(string $host, CertificateSource $source): bool
    {
        $attempts = intdiv(self::WAIT_SECONDS, self::PROBE_SECONDS);

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            if ($this->probe->serves($host, $source)) {
                return true;
            }

            if ($attempt < $attempts) {
                Sleep::for(self::PROBE_SECONDS)->seconds();
            }
        }

        return false;
    }

    private function uncertified(string $host, CertificateSource $source): string
    {
        $minutes = intdiv(self::WAIT_SECONDS, 60);

        return match ($source) {
            CertificateSource::Public => "No certificate for {$host} after {$minutes} minutes. Check that it points at this server and that ports 80 and 443 reach this server from the internet, then try again.",
            CertificateSource::Tailscale => "No certificate for {$host} from Tailscale after {$minutes} minutes. Check that tailscale cert {$host} works on this server, then try again.",
            CertificateSource::Internal => "Caddy didn't start serving {$host} after {$minutes} minutes. Check Caddy's log with journalctl -u caddy, then try again.",
        };
    }

    private function restore(string $from): void
    {
        try {
            $this->site->write([$from]);
            $this->caddy->reload();
        } catch (RuntimeException $unrestored) {
            report($unrestored);
        }
    }
}
