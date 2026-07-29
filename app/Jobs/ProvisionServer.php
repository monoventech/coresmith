<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Runtime\AsdfInstaller;
use App\Services\Server\BasePackages;
use App\Services\Server\DirectoryStructure;
use App\Services\Server\OsDetector;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProvisionServer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('provisioning');
    }

    public function handle(SSHConnectionManager $connections, OsDetector $detector): void
    {
        $connection = $connections->for($this->server);

        $this->server->forceFill(['status' => ServerStatus::Provisioning])->save();

        try {
            $this->phase(1, fn () => $this->detectOperatingSystem($connection, $detector));
            $this->phase(2, fn () => $this->installBasePackages($connection));
            $this->phase(3, fn () => app(AsdfInstaller::class)->install($connection));
            $this->phase(4, fn () => app(DirectoryStructure::class)->create($connection));
            $this->phase(5, fn () => app(CaddyInstaller::class)->install($connection));
        } catch (Throwable $e) {
            $this->fail_($e);

            throw $e;
        } finally {
            $connections->disconnect($this->server);
        }
    }

    private function detectOperatingSystem(SSHConnection $connection, OsDetector $detector): void
    {
        $info = $detector->detect($connection);

        $this->server->forceFill([
            'os_name' => $info->name,
            'os_version' => $info->version,
            'meta' => $info->toMeta(),
        ])->save();
    }

    private function installBasePackages(SSHConnection $connection): void
    {
        $packages = PackageManagerFactory::for($this->server->fresh());

        $connection->execute($packages->update(), sudo: true)->throw();
        $connection->execute($packages->install(BasePackages::for($this->server->fresh())), sudo: true)->throw();
    }

    private function phase(int $number, Closure $work): void
    {
        if (($this->server->provisioning_phase ?? 0) >= $number) {
            return;
        }

        $work();

        $this->server->forceFill(['provisioning_phase' => $number])->save();
    }

    private function fail_(Throwable $e): void
    {
        $log = (string) $this->server->provisioning_log;

        $this->server->forceFill([
            'status' => ServerStatus::Failed,
            'provisioning_log' => trim($log."\n".$e->getMessage()),
        ])->save();
    }
}
