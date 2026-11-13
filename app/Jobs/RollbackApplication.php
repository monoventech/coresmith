<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Events\FleetChanged;
use App\Exceptions\NoPreviousReleaseException;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Deployment\LogSanitizer;
use App\Services\Deployment\ReleasePaths;
use App\Services\Deployment\SharedStorage;
use App\Services\Panel\Reverb;
use App\Services\Panel\RollbackHistory;
use App\Services\Panel\SmartToggles;
use App\Services\Panel\SsrServer;
use App\Services\Process\ProcessManager;
use App\Services\Runtime\Stack;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class RollbackApplication implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public const RELEASE_PATTERN = '/^\d{8}-?\d{6}$/D';

    public const LOCK_SECONDS = 300;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        private readonly Application $application,
        private readonly ?string $release = null,
    ) {
        if ($release !== null && preg_match(self::RELEASE_PATTERN, $release) !== 1) {
            throw new InvalidArgumentException("[{$release}] is not a release name.");
        }

        $this->onQueue('deployments');
    }

    public function handle(SSHConnectionManager $connections): void
    {
        $lock = Cache::lock('deploy:' . $this->application->getKey(), self::LOCK_SECONDS);

        if (!$lock->get()) {
            activity()
                ->performedOn($this->application)
                ->withProperties(['to' => $this->release])
                ->log('rollback_blocked');

            FleetChanged::forApplication($this->application);

            return;
        }

        $server = $this->application->server;

        try {
            $connection = $connections->for($server);
            $paths = new ReleasePaths($this->application);
            $releases = $connection->execute('ls -1dt ' . $paths->releases() . '/*/ 2>/dev/null || true', sudo: true)->output;
            $ordered = array_values(array_filter(array_map(
                static fn(string $line): string => rtrim(trim($line), '/'),
                preg_split('/\r\n|\n|\r/', $releases) ?: [],
            )));

            $target = $this->target($ordered, $paths);
            $current = $paths->current();
            $previous = $this->application->current_release;

            $relink = app(SharedStorage::class)->relinkDatabase($this->application, $target);

            if ($relink !== null) {
                $connection->execute(app(AppShell::class)->run($this->application, $relink, $target), sudo: true)->throw();
            }

            $connection->execute("ln -sfn {$target} {$current}.tmp", sudo: true)->throw();
            $connection->execute("mv -Tf {$current}.tmp {$current}", sudo: true)->throw();

            $name = basename($target);
            $stack = $this->stackFor($name);
            $before = $this->application->runtimeVersions();

            $this->application->forceFill(['current_release' => $name, ...($stack?->applicationAttributes() ?? [])])->save();
            $reverb = app(Reverb::class);
            $routed = $reverb->live($this->application);
            $listed = $this->application->processes;
            $processes = app(ProcessManager::class);

            $processes->removeUnits($connection, $this->application, app(SmartToggles::class)->syncDeployTimeProcesses($this->application));

            if ($stack !== null || $this->application->processes !== $listed) {
                $processes->install($connection, $this->application);
            }

            app(EnvironmentFile::class)->write($connection, $this->application);
            app(CrontabBuilder::class)->write($connection, $this->application);

            if ($reverb->live($this->application) !== $routed) {
                SyncApplicationRoute::dispatch($this->application);
            }

            if (!in_array($this->application->power_state, [PowerState::Stopped, PowerState::Sleeping], true)) {
                $processes->refresh($connection, $this->application, restart: $before !== $this->application->runtimeVersions());

                $ssrServer = app(SsrServer::class);
                $ssr = $ssrServer->unit($this->application);

                try {
                    $processes->verifyStarted($connection, $this->application, $ssr === null ? null : array_values(array_diff($processes->unitNames($this->application), [$ssr])));
                } catch (ProcessDidNotStart $e) {
                    $worker = app(SmartToggles::class)->failedWorker($this->application, $e->unit);
                    $error = app(LogSanitizer::class)->sanitize($e->getMessage(), $this->application);

                    $this->settle($worker === null ? ApplicationStatus::Failed : ApplicationStatus::Active);

                    activity()
                        ->performedOn($this->application)
                        ->withProperties(['from' => $previous, 'to' => $name, ...($worker === null ? [] : ['worker' => $worker]), 'error' => $error])
                        ->log($worker === null ? RollbackHistory::DID_NOT_START : RollbackHistory::WORKER_DID_NOT_START);

                    throw $e;
                }

                if ($ssr !== null) {
                    $ssrServer->confirm($connection, $this->application, $ssr);
                }
            }

            $this->settle(ApplicationStatus::Active);

            activity()
                ->performedOn($this->application)
                ->withProperties(['from' => $previous, 'to' => $name, 'runtimes' => $stack === null ? 'unchanged' : 'restored'])
                ->log('application_rolled_back');
        } finally {
            $lock->release();

            if ($server !== null) {
                $connections->disconnect($server);
            }

            FleetChanged::forApplication($this->application);
            rescue(fn() => app(AutoDeploy::class)->reconcile($this->application->fresh() ?? $this->application), report: true);
        }
    }

    /**
     * @param  list<string>  $ordered
     */
    private function target(array $ordered, ReleasePaths $paths): string
    {
        if ($this->release !== null) {
            $wanted = $paths->release($this->release);

            if (!in_array($wanted, $ordered, true)) {
                throw NoPreviousReleaseException::forApplication($this->application->slug);
            }

            return $wanted;
        }

        $release = $this->application->deployments()
            ->where('status', DeploymentStatus::Succeeded->value)
            ->where('release', '!=', $this->application->current_release)
            ->latest('created_at')
            ->value('release');

        if ($release === null) {
            throw NoPreviousReleaseException::forApplication($this->application->slug);
        }

        $wanted = $paths->release($release);

        if (!in_array($wanted, $ordered, true)) {
            throw NoPreviousReleaseException::releaseGone($release);
        }

        return $wanted;
    }

    private function settle(ApplicationStatus $status): void
    {
        Application::query()
            ->whereKey($this->application->getKey())
            ->where('status', '!=', ApplicationStatus::Deploying->value)
            ->update(['status' => $status->value]);
    }

    private function stackFor(string $release): ?Stack
    {
        $deployment = $this->application->deployments()
            ->where('release', $release)
            ->whereNotNull('stack')
            ->latest('created_at')
            ->first();

        return $deployment === null ? null : Stack::fromArray($deployment->stack);
    }
}
