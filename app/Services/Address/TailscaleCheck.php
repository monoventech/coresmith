<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use Illuminate\Support\Facades\Process;
use Throwable;

final class TailscaleCheck
{
    public function refusal(string $host): ?string
    {
        try {
            $result = Process::timeout(15)->run('tailscale status --json');
        } catch (Throwable) {
            $result = null;
        }

        $status = $result?->successful() ? json_decode($result->output(), true) : null;

        if (!is_array($status)) {
            return "Tailscale isn't running on this server. Install it and run tailscale up, then try again.";
        }

        if (($status['BackendState'] ?? null) !== 'Running') {
            return "Tailscale on this server isn't signed in. Run tailscale up on it, then try again.";
        }

        $name = rtrim(strtolower((string) ($status['Self']['DNSName'] ?? '')), '.');

        if ($name !== $host) {
            return "This server's Tailscale name is {$name}. Use that name instead.";
        }

        if (($status['CertDomains'] ?? []) === [] || !in_array($host, (array) $status['CertDomains'], true)) {
            return 'HTTPS certificates are off for your tailnet. Turn them on in the Tailscale admin console under DNS, then try again.';
        }

        if (!$this->caddyMayFetch()) {
            return "Caddy isn't allowed to fetch Tailscale certificates. Add TS_PERMIT_CERT_UID=caddy to /etc/default/tailscaled and restart tailscaled, then try again.";
        }

        return null;
    }

    private function caddyMayFetch(): bool
    {
        $defaults = (string) @file_get_contents((string) config('coresmith.address.tailscaled_defaults'));
        $allowed = ['caddy'];

        if (function_exists('posix_getpwnam') && is_array($caddy = posix_getpwnam('caddy'))) {
            $allowed[] = (string) $caddy['uid'];
        }

        return preg_match('/^\s*TS_PERMIT_CERT_UID\s*=\s*["\']?(' . implode('|', array_map(preg_quote(...), $allowed)) . ')["\']?\s*$/m', $defaults) === 1;
    }
}
