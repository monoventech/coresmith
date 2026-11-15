<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Services\Deployment\CronSyncFailure;
use App\Services\Process\ProcessManager;
use App\Services\Server\ServerReachability;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ApplyPhpLimits implements ShouldQueue
{
    use Queueable;

    public const CHANGED = 'php_limits_changed';

    public const FAILED = 'php_limits_failed';

    public const CATCH_UP_BATCH = 20;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly Application $application)
    {
        $this->onQueue('server-tasks');
    }

    public static function catchUp(): void
    {
        Application::query()
            ->where('type', ApplicationType::Php->value)
            ->whereNull('php_written_at')
            ->whereNotNull('current_release')
            ->whereHas('server', fn($server) => $server->where('status', ServerStatus::Active->value)->where('connection_failures', '<', ServerReachability::FAILURES_BEFORE_UNREACHABLE))
            ->orderBy('id')
            ->limit(self::CATCH_UP_BATCH)
            ->get()
            ->each(fn(Application $application) => self::dispatch($application));
    }

    public function handle(SSHConnectionManager $connections, ProcessManager $processes): void
    {
        $application = $this->application->fresh() ?? $this->application;

        if (!$application->type->language()->usesFpm()) {
            return;
        }

        $connection = $connections->for($application->server);

        try {
            $processes->install($connection, $application);

            if ($application->power_state === PowerState::Active && $application->current_release !== null) {
                $processes->refresh($connection, $application);
            }
        } catch (Throwable $e) {
            activity()->performedOn($application)->withProperties(['error' => CronSyncFailure::reason($e, $application)])->log(self::FAILED);

            throw $e;
        } finally {
            $connections->disconnect($application->server);
        }
    }
}
