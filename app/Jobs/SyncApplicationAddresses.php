<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Server\FirewallManagerFactory;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncApplicationAddresses implements ShouldQueue
{
    use Queueable;

    public function __construct(public Application $application) {}

    public function handle(SSHConnectionManager $connections, CaddyApiClient $caddy): void
    {
        $application = $this->application->fresh();
        $server = $application?->server;

        if ($application === null || $server === null) {
            return;
        }

        $connection = $connections->for($server);
        $port = $application->addressPort();
        $ip = $application->tailnet ? $this->tailscaleAddress($connection) : null;
        $problem = $application->tailnet && $ip === null
            ? "Tailscale isn't running on {$server->name}, so there is no tailnet address. Start Tailscale there, then turn this on again."
            : null;

        if ($ip !== null) {
            $caddy->putAddress($connection, $application, 'tailnet', "{$ip}:{$port}");
        } else {
            $caddy->removeAddress($connection, $application, 'tailnet');
        }

        if ($application->preview) {
            $caddy->putAddress($connection, $application, 'preview', ":{$port}");

            if (!$server->runs_coresmith) {
                $connection->execute(FirewallManagerFactory::for($server)->allow($port), sudo: true)->throw();
            }
        } elseif ($caddy->removeAddress($connection, $application, 'preview') && !$server->runs_coresmith) {
            $connection->execute(FirewallManagerFactory::for($server)->remove($port), sudo: true);
        }

        $application->forceFill(['tailnet_ip' => $ip, 'address_problem' => $problem])->save();
    }

    private function tailscaleAddress(SSHConnection $connection): ?string
    {
        $ip = trim($connection->execute('tailscale ip -4 2>/dev/null | head -n 1')->output);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $ip;
    }
}
