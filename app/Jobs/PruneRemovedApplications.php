<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Deployment\RemovedApplicationFolders;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PruneRemovedApplications implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly Server $server)
    {
        $this->onQueue('default');
    }

    public function handle(SSHConnectionManager $connections, RemovedApplicationFolders $folders): void
    {
        if ($folders->due($this->server)->isEmpty()) {
            return;
        }

        $connection = $connections->for($this->server);

        try {
            $folders->clear($connection, $this->server);
        } finally {
            $connections->disconnect($this->server);
        }
    }
}
