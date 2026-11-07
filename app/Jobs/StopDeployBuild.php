<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\Deployment\AppShell;
use App\Services\Server\ServerReachability;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class StopDeployBuild implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    private const COMMAND_SECONDS = 25;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly Deployment $deployment)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections): void
    {
        $application = $this->deployment->application;
        $server = $application?->server;
        $release = $this->deployment->release;

        if ($application === null || $server === null || $release === null) {
            return;
        }

        $command = 'systemctl stop ' . AppShell::buildUnit($application, $release) . '.service || true';

        try {
            $connections->for($server)->execute($command, sudo: true, timeout: self::COMMAND_SECONDS);
        } catch (Throwable $e) {
            if (!ServerReachability::isConnectionFailure($e)) {
                throw $e;
            }
        } finally {
            $connections->disconnect($server);
        }
    }
}
