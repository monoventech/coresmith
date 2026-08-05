<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Events\DeploymentSucceeded;
use App\Exceptions\DeploymentAborted;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\LogSanitizer;
use App\Services\Deployment\ReleasePaths;
use App\Services\Git\DeployKeyManager;
use App\Services\Process\ProcessManager;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class DeployApplication implements ShouldQueue
{
    use Queueable;

    public const LOCK_SECONDS = 600;

    public const DISK_ABORT_PERCENT = 95;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly Deployment $deployment,
        private readonly string $timestamp,
    ) {
        $this->onQueue('deployments');
    }

    public function handle(SSHConnectionManager $connections, DeploymentCommands $commands): void
    {
        $application = $this->deployment->application;
        $lock = Cache::lock('deploy:'.$application->getKey(), self::LOCK_SECONDS);

        if (!$lock->get()) {
            $this->deployment->forceFill([
                'status' => DeploymentStatus::Cancelled,
                'log' => 'Another deployment is already in progress.',
            ])->save();

            return;
        }

        $connection = $connections->for($application->server);
        $paths = new ReleasePaths($application);
        $release = $paths->release($this->timestamp);

        try {
            $this->guardDiskSpace($connection);

            $this->deployment->forceFill([
                'status' => DeploymentStatus::Running,
                'started_at' => now(),
            ])->save();

            $this->prepare($connection, $paths, $release);

            if ($this->hasCustomBlueprint($application)) {
                $this->runBlueprint($connection, $commands, $release, $application);
            } else {
                $this->installDependencies($connection, $commands, $release);
                $this->build($connection, $commands, $release);
                $this->migrate($connection, $commands, $release);
            }
            $this->swapSymlink($connection, $paths, $release);
            app(ProcessManager::class)->install($connection, $application);
            $this->restartProcesses($connection, $commands);
            $this->writeCrontab($connection, $application);
            $this->pruneOldReleases($connection, $paths);

            $this->deployment->forceFill([
                'status' => DeploymentStatus::Succeeded,
                'finished_at' => now(),
            ])->save();

            DeploymentSucceeded::dispatch($this->deployment);
        } catch (Throwable $e) {
            $this->cleanUpFailedRelease($connection, $release);
            $this->markFailed(app(LogSanitizer::class)->sanitize($e->getMessage(), $this->deployment->application));

            throw $e;
        } finally {
            $lock->release();
            $connections->disconnect($application->server);
        }
    }

    private function guardDiskSpace(SSHConnection $connection): void
    {
        $result = $connection->execute("df / | awk 'NR==2 {print \$5}' | sed 's/%//'");
        $percent = (int) trim($result->output);

        if ($percent >= self::DISK_ABORT_PERCENT) {
            throw new DeploymentAborted(
                "Deployment aborted: server disk is {$percent}% full. Free up space (clear old releases, prune logs) before deploying."
            );
        }
    }

    private function prepare(SSHConnection $connection, ReleasePaths $paths, string $release): void
    {
        $application = $this->deployment->application;
        $repo = $paths->repo();
        $url = escapeshellarg((string) $application->repository_url);
        $branch = escapeshellarg($application->repository_branch);
        $keys = app(DeployKeyManager::class);
        $private = $keys->usesDeployKey($application);

        $connection->execute('mkdir -p '.$paths->releases().' '.$paths->shared())->throw();

        if ($private) {
            $this->installDeployKey($connection, $keys, $application);
        }

        try {
            $clone = $private
                ? $keys->withKey($application, "git clone --bare {$url} {$repo}")
                : "git clone --bare {$url} {$repo}";

            $fetch = $private
                ? $keys->withKey($application, "git --git-dir={$repo} fetch --all --prune")
                : "git --git-dir={$repo} fetch --all --prune";

            $connection->execute("test -d {$repo} || {$clone}")->throw();
            $connection->execute($fetch)->throw();
        } finally {
            if ($private) {
                $connection->execute($keys->removeCommand($application));
            }
        }

        $connection->execute('mkdir -p '.$release)->throw();
        $connection->execute("git --git-dir={$repo} archive {$branch} | tar -x -C {$release}")->throw();

        $connection->execute(
            'test -d '.$paths->shared().'/storage && ln -sfn '.$paths->shared().'/storage '.$release.'/storage || true',
        )->throw();
    }

    private function installDependencies(SSHConnection $connection, DeploymentCommands $commands, string $release): void
    {
        $this->runInRelease($connection, $commands, $release, $commands->dependencies($this->deployment->application));
    }

    private function build(SSHConnection $connection, DeploymentCommands $commands, string $release): void
    {
        $this->runInRelease($connection, $commands, $release, $commands->build($this->deployment->application));
    }

    private function migrate(SSHConnection $connection, DeploymentCommands $commands, string $release): void
    {
        $this->runInRelease($connection, $commands, $release, $commands->migrations($this->deployment->application));
    }

    private function swapSymlink(SSHConnection $connection, ReleasePaths $paths, string $release): void
    {
        $current = $paths->current();

        $connection->execute("ln -sfn {$release} {$current}.tmp")->throw();
        $connection->execute("mv -Tf {$current}.tmp {$current}")->throw();
    }

    private function restartProcesses(SSHConnection $connection, DeploymentCommands $commands): void
    {
        foreach ($commands->restart($this->deployment->application) as $command) {
            $connection->execute($command, sudo: true)->throw();
        }
    }

    private function installDeployKey(SSHConnection $connection, DeployKeyManager $keys, Application $application): void
    {
        $application = $keys->ensure($application);

        $connection->execute($keys->prepareDirectory($application), sudo: true)->throw();
        $connection->execute($keys->reserveFile($application))->throw();
        $connection->uploadContent((string) $application->deploy_key_private, $keys->keyPath($application));
    }

    private function hasCustomBlueprint(Application $application): bool
    {
        return trim((string) $application->custom_blueprint) !== '';
    }

    private function runBlueprint(SSHConnection $connection, DeploymentCommands $commands, string $release, Application $application): void
    {
        $script = $release.'/.coresmith-blueprint.sh';

        $connection->uploadContent((string) $application->custom_blueprint, $script);

        $result = $connection->execute(
            'cd '.$release.' && '.$commands->pathPrefix($application).'bash '.$script,
        );

        $this->appendLog($result->output);

        $result->throw();

        $connection->execute('rm -f '.$script);
    }

    private function writeCrontab(SSHConnection $connection, Application $application): void
    {
        $builder = app(CrontabBuilder::class);
        $crontab = $builder->build($application);

        $connection->uploadContent($crontab, '/tmp/coresmith-cron-'.$application->slug);
        $connection->execute(
            'cat /tmp/coresmith-cron-'.$application->slug.' | '.$builder->install($application)
            .' && rm -f /tmp/coresmith-cron-'.$application->slug,
            sudo: true,
        )->throw();
    }

    private function pruneOldReleases(SSHConnection $connection, ReleasePaths $paths): void
    {
        $releases = $paths->releases();
        $keep = ReleasePaths::KEEP_RELEASES;

        $connection->execute(
            "ls -1dt {$releases}/*/ 2>/dev/null | tail -n +".($keep + 1).' | xargs -r rm -rf',
        )->throw();
    }

    private function cleanUpFailedRelease(SSHConnection $connection, string $release): void
    {
        try {
            $connection->execute('rm -rf '.$release);
        } catch (Throwable) {
            $this->appendLog('Could not remove the half-built release; the stale deployment detector will reclaim it.');
        }
    }

    /**
     * @param  list<string>  $steps
     */
    private function runInRelease(SSHConnection $connection, DeploymentCommands $commands, string $release, array $steps): void
    {
        $prefix = $commands->pathPrefix($this->deployment->application);

        foreach ($steps as $step) {
            $result = $connection->execute("cd {$release} && {$prefix}{$step}");

            $this->appendLog($result->output);

            $result->throw();
        }
    }

    private function appendLog(string $output): void
    {
        if ($output === '') {
            return;
        }

        $clean = app(LogSanitizer::class)->sanitize($output, $this->deployment->application);

        $this->deployment->forceFill([
            'log' => trim((string) $this->deployment->log."\n".$clean),
        ])->save();
    }

    private function markFailed(string $message): void
    {
        $this->deployment->forceFill([
            'status' => DeploymentStatus::Failed,
            'finished_at' => now(),
            'log' => trim((string) $this->deployment->log."\n".$message),
        ])->save();

        DeploymentFailed::dispatch($this->deployment);
    }
}
