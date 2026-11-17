<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Server\CertificateCheck;
use App\Services\Server\CertificateRetry;
use App\Services\Server\ServerReachability;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use Throwable;

final class CheckCertificates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public readonly Server $server)
    {
        $this->onQueue('server-tasks');
    }

    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    public function handle(SSHConnectionManager $connections, CertificateCheck $check, CertificateRetry $retry): void
    {
        $server = $this->server->fresh();

        if ($server === null || $server->status !== ServerStatus::Active || $server->unreachable_since !== null || $server->pending_host_key_fingerprint !== null) {
            return;
        }

        try {
            $connection = $connections->for($server);
            $waiting = $retry->pointedHere($server, $check->run($connection));

            if ($waiting !== [] && $retry->ask($connection)) {
                Sleep::for(CertificateRetry::WAIT_SECONDS)->seconds();
                $check->run($connection, $waiting);
            }
        } catch (Throwable $e) {
            if (!ServerReachability::isConnectionFailure($e)) {
                throw $e;
            }
        } finally {
            $connections->disconnect($server);
        }
    }
}
