<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class TestSSHConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 15;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('default');
    }

    public function handle(SSHConnectionManager $connections): void
    {
        $connection = $connections->for($this->server);

        try {
            $connection->execute('whoami')->throw();
            $connection->execute('uname -a')->throw();

            $status = $this->server->status ?? ServerStatus::Pending;

            $this->server->forceFill([
                'last_connected_at' => now(),
                'status' => $status === ServerStatus::Pending ? ServerStatus::Provisioning : $status,
            ])->save();
        } finally {
            $connections->disconnect($this->server);
        }
    }
}
