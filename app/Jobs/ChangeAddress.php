<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Address\AddressChange;
use App\Services\Address\CaddyAdmin;
use App\Services\Address\CertificateProbe;
use App\Services\Address\CertificateSource;
use App\Services\Address\EnvironmentFile;
use App\Services\Address\InstallationAddress;
use App\Services\Address\SiteFile;
use App\Services\Address\TailscaleCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

final class ChangeAddress implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const WAIT_SECONDS = 180;

    public const PROBE_SECONDS = 5;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $host)
    {
        $this->onQueue('default');
    }

    public function handle(InstallationAddress $address, SiteFile $site, CaddyAdmin $caddy, CertificateProbe $probe, EnvironmentFile $environment, TailscaleCheck $tailscale): void
    {
        $from = $address->host();
        $source = InstallationAddress::certificate($this->host);
        $refusal = $this->servesApps()
            ? 'This server also serves apps through Caddy, so Coresmith leaves its Caddy setup alone. Run the installer again with --domain instead.'
            : ($source === CertificateSource::Tailscale ? $tailscale->refusal($this->host) : null);

        if ($refusal !== null) {
            AddressChange::fail($refusal);

            return;
        }

        try {
            $site->write([$from, $this->host]);
            $caddy->reload();
        } catch (RuntimeException $refused) {
            $this->restore($site, $caddy, $from);
            AddressChange::fail($refused->getMessage());

            return;
        }

        if (!$this->certified($probe, $source)) {
            $this->restore($site, $caddy, $from);
            AddressChange::fail($this->uncertified($source));

            return;
        }

        $environment->set(['APP_URL' => "https://{$this->host}", 'VITE_REVERB_HOST' => $this->host]);

        if (app()->configurationIsCached()) {
            Artisan::call('config:cache');
        }

        AddressChange::finish();
        activity()->withProperties(['from' => $from, 'to' => $this->host])->log('address_changed');

        try {
            $site->write([$this->host], [$from => $this->host]);
            $caddy->reload();
        } catch (RuntimeException $unredirected) {
            report($unredirected);
        }

        Artisan::call('horizon:terminate');
        Artisan::call('reverb:restart');
    }

    public function failed(Throwable $exception): void
    {
        AddressChange::fail("Changing the address to {$this->host} stopped part way. Try again, and check Caddy's log with journalctl -u caddy if it keeps failing.");
    }

    public function uniqueId(): string
    {
        return 'address';
    }

    private function servesApps(): bool
    {
        return str_contains((string) @file_get_contents((string) config('coresmith.address.apps_config')), '"apps"');
    }

    private function certified(CertificateProbe $probe, CertificateSource $source): bool
    {
        $attempts = intdiv(self::WAIT_SECONDS, self::PROBE_SECONDS);

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            if ($probe->serves($this->host, $source)) {
                return true;
            }

            if ($attempt < $attempts) {
                Sleep::for(self::PROBE_SECONDS)->seconds();
            }
        }

        return false;
    }

    private function uncertified(CertificateSource $source): string
    {
        $minutes = intdiv(self::WAIT_SECONDS, 60);

        return match ($source) {
            CertificateSource::Public => "No certificate for {$this->host} after {$minutes} minutes. Check that it points at this server and that ports 80 and 443 reach this server from the internet, then try again.",
            CertificateSource::Tailscale => "No certificate for {$this->host} from Tailscale after {$minutes} minutes. Check that tailscale cert {$this->host} works on this server, then try again.",
            CertificateSource::Internal => "Caddy didn't start serving {$this->host} after {$minutes} minutes. Check Caddy's log with journalctl -u caddy, then try again.",
        };
    }

    private function restore(SiteFile $site, CaddyAdmin $caddy, string $from): void
    {
        try {
            $site->write([$from]);
            $caddy->reload();
        } catch (RuntimeException) {
            return;
        }
    }
}
