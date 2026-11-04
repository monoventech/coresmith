<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Services\Process\ProcessManager;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\SSH\SSHConnection;

final class SsrServer
{
    private const PROCESS = 'ssr';

    public function __construct(
        private readonly ProcessManager $processes,
        private readonly SmartToggles $toggles,
        private readonly SystemdUnitBuilder $units,
    ) {}

    public function unit(Application $application): ?string
    {
        $runs = collect((array) $application->processes)->contains(fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) === self::PROCESS);

        return $runs ? $this->units->unitName($application, self::PROCESS) : null;
    }

    public function confirm(SSHConnection $connection, Application $application, string $unit): bool
    {
        if ($this->answers($connection, $application, $unit)) {
            $this->toggles->started($application, SmartToggles::INERTIA_SSR);

            return true;
        }

        $this->stop($connection, $application);

        return false;
    }

    public function stop(SSHConnection $connection, Application $application): void
    {
        $this->processes->removeUnits($connection, $application, [self::PROCESS]);

        $this->toggles->failedToStart($application, SmartToggles::INERTIA_SSR, self::PROCESS, "The SSR server did not start on port {$application->ssr_port}. Pass Number(process.env.INERTIA_SSR_PORT) as the port in your SSR entry, then deploy again.");
    }

    private function answers(SSHConnection $connection, Application $application, string $unit): bool
    {
        try {
            $this->processes->verifyStarted($connection, $application, [$unit]);
        } catch (ProcessDidNotStart) {
            return false;
        }

        $health = 'http://127.0.0.1:' . $application->ssr_port . '/health';

        return $connection->execute('curl -fsS --max-time 2 --retry 3 --retry-delay 1 --retry-connrefused -o /dev/null ' . escapeshellarg($health))->successful();
    }
}
