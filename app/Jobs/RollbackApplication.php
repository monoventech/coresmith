<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Exceptions\NoPreviousReleaseException;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Deployment\LogSanitizer;
use App\Services\Deployment\ReleasePaths;
use App\Services\Process\ProcessManager;
use App\Services\Runtime\Stack;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

final class RollbackApplication implements ShouldQueue
{
    use Queueable;

    public const RELEASE_PATTERN = '/^\d{8}-?\d{6}$/';

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
        $connection = $connections->for($this->application->server);
        $paths = new ReleasePaths($this->application);

        try {
            $releases = $connection->execute('ls -1dt ' . $paths->releases() . '/*/ 2>/dev/null || true', sudo: true)->output;
            $ordered = array_values(array_filter(array_map(
                static fn(string $line): string => rtrim(trim($line), '/'),
                preg_split('/\R/', $releases) ?: [],
            )));

            $target = $this->target($ordered, $paths);
            $current = $paths->current();
            $previous = $this->application->current_release;

            $connection->execute("ln -sfn {$target} {$current}.tmp", sudo: true)->throw();
            $connection->execute("mv -Tf {$current}.tmp {$current}", sudo: true)->throw();

            $name = basename($target);
            $stack = $this->stackFor($name);
            $before = $this->application->runtimeVersions();

            $this->application->forceFill(['current_release' => $name, ...($stack?->applicationAttributes() ?? [])])->save();

            $processes = app(ProcessManager::class);

            if ($stack !== null) {
                $processes->install($connection, $this->application);
            }

            app(EnvironmentFile::class)->write($connection, $this->application);
            app(CrontabBuilder::class)->write($connection, $this->application);

            if (!in_array($this->application->power_state, [PowerState::Stopped, PowerState::Sleeping], true)) {
                $processes->refresh($connection, $this->application, restart: $before !== $this->application->runtimeVersions());

                try {
                    $processes->verifyStarted($connection, $this->application);
                } catch (ProcessDidNotStart $e) {
                    activity()
                        ->performedOn($this->application)
                        ->withProperties(['from' => $previous, 'to' => $name, 'error' => app(LogSanitizer::class)->sanitize($e->getMessage(), $this->application)])
                        ->log('rollback_processes_failed');

                    throw $e;
                }
            }

            activity()
                ->performedOn($this->application)
                ->withProperties(['from' => $previous, 'to' => $name, 'runtimes' => $stack === null ? 'unchanged' : 'restored'])
                ->log('application_rolled_back');
        } finally {
            $connections->disconnect($this->application->server);
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
