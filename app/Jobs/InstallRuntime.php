<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class InstallRuntime implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        private readonly Server $server,
        private readonly string $runtime,
        private readonly string $version,
    ) {
        AsdfManager::assertValid($runtime, $version);

        $this->onQueue('provisioning');
    }

    public function handle(SSHConnectionManager $connections, AsdfManager $asdf): void
    {
        $connection = $connections->for($this->server);

        try {
            $asdf->install($connection, $this->server, $this->runtime, $this->version);

            $installed = $this->server->installed_runtimes ?? [];
            $versions = $installed[$this->runtime] ?? [];
            $versions = array_values(array_unique([...(array) $versions, $this->version]));
            sort($versions);
            $installed[$this->runtime] = $versions;

            $this->server->forceFill(['installed_runtimes' => $installed])->save();
        } finally {
            $connections->disconnect($this->server);
        }
    }
}
