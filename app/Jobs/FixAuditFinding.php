<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\Server\Audit\PreProvisioningAudit;
use App\Services\Server\Audit\ServerAudit;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class FixAuditFinding implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        private readonly Server $server,
        private readonly string $fix,
    ) {
        if (!array_key_exists($fix, PreProvisioningAudit::FIXES)) {
            throw new InvalidArgumentException("[{$fix}] is not a fix Coresmith applies.");
        }

        $this->onQueue('default');
    }

    public function handle(SSHConnectionManager $connections, PreProvisioningAudit $audit): void
    {
        $service = PreProvisioningAudit::FIXES[$this->fix];
        $connection = $connections->for($this->server);
        $step = 'stop';

        try {
            $connection->execute('systemctl stop ' . escapeshellarg($service), sudo: true)->throw();

            $step = 'disable';
            $connection->execute('systemctl disable ' . escapeshellarg($service), sudo: true)->throw();

            activity()->performedOn($this->server)->withProperties(['service' => $service])->log('audit_fix_applied');

            $step = 'recheck';
            ServerAudit::finish($this->server, $audit->run($connection));
        } catch (SSHCommandFailedException $e) {
            ServerAudit::fail($this->server, $this->reason($step, $service, $e));

            throw $e;
        } catch (Throwable $e) {
            ServerAudit::fail($this->server, AuditServer::explain($this->server, $e));

            throw $e;
        } finally {
            $connections->disconnect($this->server);
        }
    }

    private function reason(string $step, string $service, SSHCommandFailedException $e): string
    {
        $detail = Str::limit(trim($e->output()), 300);

        return match ($step) {
            'stop' => "Stopping {$service} did not work: {$detail}",
            'disable' => "Disabling {$service} did not work: {$detail}",
            default => "{$service} was stopped and disabled, but checking the server again failed: {$detail}. Check again.",
        };
    }
}
