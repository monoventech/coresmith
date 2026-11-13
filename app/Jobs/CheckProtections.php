<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Events\FleetChanged;
use App\Models\Server;
use App\Services\Server\ProtectionCheck;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class CheckProtections implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    public function handle(SSHConnectionManager $connections, ProtectionCheck $check): void
    {
        try {
            $reading = $check->read($connections->for($this->server));
        } catch (RuntimeException $e) {
            report($e);

            return;
        } finally {
            $connections->disconnect($this->server);
        }

        $check->record($this->server, $reading);
        FleetChanged::forServer($this->server);
    }
}
