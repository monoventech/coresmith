<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Server\FirewallManagerFactory;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ManageFirewallRule implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        private readonly Server $server,
        private readonly string $action,
        private readonly int $port,
        private readonly ?string $fromIp = null,
    ) {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections): void
    {
        $connection = $connections->for($this->server);
        $firewall = FirewallManagerFactory::for($this->server);

        try {
            $command = match ($this->action) {
                'allow' => $this->fromIp !== null
                    ? $firewall->allowFrom($this->fromIp, $this->port)
                    : $firewall->allow($this->port),
                'deny' => $firewall->deny($this->port),
                default => null,
            };

            if ($command === null) {
                return;
            }

            $connection->execute($command, sudo: true)->throw();

            activity()
                ->performedOn($this->server)
                ->withProperties(['action' => $this->action, 'port' => $this->port, 'from' => $this->fromIp])
                ->log('firewall_rule_changed');
        } finally {
            $connections->disconnect($this->server);
        }
    }
}
