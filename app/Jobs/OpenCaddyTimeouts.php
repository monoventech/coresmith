<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class OpenCaddyTimeouts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const OPEN = 'open';

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    public function handle(SSHConnectionManager $connections, CaddyApiClient $caddy): void
    {
        try {
            $caddy->openTimeouts($connections->for($this->server));
        } catch (RuntimeException $e) {
            report($e);

            return;
        } finally {
            $connections->disconnect($this->server);
        }

        $server = $this->server->fresh() ?? $this->server;
        $server->forceFill(['meta' => [...(array) $server->meta, 'caddy_timeouts' => self::OPEN]])->save();
    }
}
