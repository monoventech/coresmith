<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Catalog;
use App\Services\Server\RuntimeInstaller;
use App\Services\Server\ServerRuntimes;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RemoveServerRuntime implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly Server $server,
        public readonly string $runtime,
        public readonly string $version,
        public readonly ?User $by = null,
    ) {
        AsdfManager::assertValid($runtime, $version);
        $this->onQueue('provisioning');
    }

    public function handle(SSHConnectionManager $connections, AsdfManager $asdf, ServerRuntimes $runtimes, Catalog $catalog): void
    {
        Cache::lock('runtime-install:' . $this->server->getKey() . ':' . $this->runtime . ':' . $this->version, RuntimeInstaller::SECONDS)
            ->block($this->timeout - 60, function () use ($connections, $asdf, $runtimes, $catalog): void {
                $use = $runtimes->usage($this->server)["{$this->runtime}@{$this->version}"] ?? ['runs' => [], 'kept' => []];

                if ($use['runs'] !== [] || $use['kept'] !== []) {
                    return;
                }

                $connection = $connections->for($this->server);

                try {
                    $asdf->uninstall($connection, $this->runtime, $this->version);
                } finally {
                    $connections->disconnect($this->server);
                }

                $this->forget();

                activity()->performedOn($this->server)->causedBy($this->by)
                    ->withProperties(['runtime' => $catalog->label($this->runtime), 'version' => $this->version])
                    ->log('runtime_removed');
            });
    }

    private function forget(): void
    {
        $this->server->refresh();
        $installed = (array) $this->server->installed_runtimes;
        $left = array_values(array_filter(array_map('strval', (array) ($installed[$this->runtime] ?? [])), fn(string $version): bool => $version !== $this->version));

        if ($left === []) {
            unset($installed[$this->runtime]);
        } else {
            $installed[$this->runtime] = $left;
        }

        $this->server->forceFill(['installed_runtimes' => $installed])->save();
    }
}
