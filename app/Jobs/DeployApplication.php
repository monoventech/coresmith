<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Events\DeploymentFailed;
use App\Events\DeploymentSucceeded;
use App\Events\DeploymentUpdated;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Deployment\GitFailure;
use App\Services\Deployment\LogSanitizer;
use App\Services\Deployment\ReleasePaths;
use App\Services\Git\DeployKeyManager;
use App\Services\Process\CrashLoopWatch;
use App\Services\Process\ProcessManager;
use App\Services\Server\PortBlockGuard;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use phpseclib3\Exception\ConnectionClosedException;
use Throwable;

final class DeployApplication implements ShouldQueue
{
    use Queueable;

    public const LOCK_SECONDS = 840;

    public const DISK_ABORT_PERCENT = 95;

    public const GIT_RETRIES = 2;

    public const DEPENDENCY_RETRIES = 1;

    public const RETRY_SECONDS = 5;

    private const NETWORK_ERRORS = ['ETIMEDOUT', 'ECONNRESET', 'ECONNREFUSED', 'ENOTFOUND', 'EAI_AGAIN', 'Could not resolve host', 'Connection timed out', 'Operation timed out', 'curl error 28', 'curl error 6', 'could not be downloaded', 'Failed to download', '502 Bad Gateway', '503 Service Unavailable', '504 Gateway Time', 'socket hang up', 'Net::OpenTimeout', 'Gem::RemoteFetcher'];

    public int $tries = 1;

    public int $timeout = 780;

    private bool $swapped = false;

    private ?string $failureReason = null;

    private bool $crashLooping = false;

    public function __construct(
        private readonly Deployment $deployment,
        private readonly string $timestamp,
    ) {
        $this->onQueue('deployments');
    }

    public function handle(SSHConnectionManager $connections, DeploymentCommands $commands): void
    {
        $application = $this->deployment->application;
        $lock = Cache::lock('deploy:' . $application->getKey(), self::LOCK_SECONDS);

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
                'release' => $this->timestamp,
                'branch' => $application->repository_branch,
            ])->save();

            $application->forceFill(['status' => ApplicationStatus::Deploying])->save();

            $this->appendLog($this->stamp() . ' release ' . $this->timestamp . ' · branch ' . $application->repository_branch . ' · triggered by ' . $this->trigger());

            $this->claimPorts($connection, $application);

            $this->reconnecting($connection, DeploymentPhases::CLONE, fn() => $this->prepare($connection, $paths, $release));

            if ($this->hasCustomBlueprint($application)) {
                $this->mark(DeploymentPhases::BUILD, 'running the custom blueprint');
                $this->runBlueprint($connection, $commands, $release, $application);
            } else {
                $this->reconnecting($connection, DeploymentPhases::DEPENDENCIES, fn() => $this->installDependencies($connection, $commands, $release));
                $this->reconnecting($connection, DeploymentPhases::BUILD, fn() => $this->build($connection, $commands, $release));
                $this->migrate($connection, $commands, $release);
            }

            $this->checkHealth($connection, $commands, $paths, $release);
            $this->swapSymlink($connection, $paths, $release);
            $processes = app(ProcessManager::class);
            $processes->install($connection, $application);
            $this->startProcesses($connection, $processes, $application);
            $this->routeTraffic($connection, $application);
            $this->writeCrontab($connection, $application);
            $this->pruneOldReleases($connection, $paths);
            $this->watchProcesses($connection, $processes, $application);

            $this->appendLog($this->stamp() . ' deployed. previous release kept for instant rollback.');

            $this->deployment->forceFill([
                'status' => DeploymentStatus::Succeeded,
                'finished_at' => now(),
            ])->save();

            $application->forceFill([
                'status' => ApplicationStatus::Active,
                'current_release' => $this->timestamp,
            ])->save();

            DeploymentUpdated::dispatch($this->deployment);
            DeploymentSucceeded::dispatch($this->deployment);
        } catch (Throwable $e) {
            if (!$this->swapped) {
                $this->cleanUpFailedRelease($connection, $release);
            }

            $this->failureReason ??= $e instanceof DeploymentAborted ? $e->getMessage() : null;

            $this->markFailed(app(LogSanitizer::class)->sanitize($e->getMessage(), $this->deployment->application));

            throw $e;
        } finally {
            $lock->release();
            $connections->disconnect($application->server);
        }
    }

    private function reconnecting(SSHConnection $connection, string $phase, Closure $work): void
    {
        try {
            $work();
        } catch (ConnectionClosedException) {
            $this->appendLog("the SSH connection dropped during {$phase}, reconnecting and running it once more");
            $connection->disconnect();
            Sleep::for(self::RETRY_SECONDS)->seconds();

            try {
                $work();
            } catch (ConnectionClosedException $e) {
                $this->failureReason = 'The SSH connection to ' . $connection->server()->name . " dropped twice during {$phase}. Check the server is reachable, then deploy again.";

                throw $e;
            }
        }
    }

    private function git(SSHConnection $connection, string $command, string $step, ?Closure $beforeRetry = null): void
    {
        $application = $this->deployment->application;
        $attempts = self::GIT_RETRIES + 1;

        for ($attempt = 1; ; $attempt++) {
            $result = $connection->execute($command);

            if ($result->successful()) {
                return;
            }

            $this->appendLog($result->output);
            $failure = GitFailure::from($result->output, $application);

            if (!$failure->retryable || $attempt >= $attempts) {
                $this->failureReason = $failure->reason;

                throw new DeploymentAborted($failure->reason);
            }

            $this->appendLog("{$step} failed (attempt {$attempt} of {$attempts}), retrying in " . self::RETRY_SECONDS . ' seconds');

            if ($beforeRetry !== null) {
                $beforeRetry();
            }

            Sleep::for(self::RETRY_SECONDS)->seconds();
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

    private function claimPorts(SSHConnection $connection, Application $application): void
    {
        if ($application->current_release !== null) {
            return;
        }

        $moved = app(PortBlockGuard::class)->ensureFree($connection, $application);

        if ($moved === null) {
            return;
        }

        if (isset($moved['unknown'])) {
            $this->appendLog('ports: could not read the listening ports, going ahead');

            return;
        }

        $this->appendLog("ports: {$moved['process']} already listens on {$moved['port']}, moved to {$moved['to']}–" . ($moved['to'] + 9));
    }

    private function prepare(SSHConnection $connection, ReleasePaths $paths, string $release): void
    {
        $application = $this->deployment->application;
        $repo = $paths->repo();
        $url = escapeshellarg((string) $application->repository_url);
        $branch = escapeshellarg($application->repository_branch);
        $keys = app(DeployKeyManager::class);
        $private = $keys->usesDeployKey($application);

        $this->mark(DeploymentPhases::CLONE, $private ? 'fetching via deploy key (ed25519, read-only)' : 'fetching over https');

        $connection->execute('mkdir -p ' . $paths->releases() . ' ' . $paths->shared())->throw();

        if ($private) {
            $this->installDeployKey($connection, $keys, $application);
        }

        try {
            $clone = $private
                ? $keys->withKey($application, "git clone --bare {$url} {$repo}")
                : "git clone --bare {$url} {$repo}";

            $fetch = $private
                ? $keys->withKey($application, "git --git-dir={$repo} fetch --all --prune --tags")
                : "git --git-dir={$repo} fetch --all --prune --tags";

            $this->git($connection, "test -d {$repo} || {$clone}", 'clone', fn() => $connection->execute('rm -rf ' . $repo));
            $this->git($connection, $fetch, 'fetch');
        } finally {
            if ($private) {
                $connection->execute($keys->removeCommand($application));
            }
        }

        $this->recordCommit($connection, $repo, $branch);

        $connection->execute('mkdir -p ' . $release)->throw();
        $this->git($connection, "set -o pipefail; git --git-dir={$repo} archive {$branch} | tar -x -C {$release}", 'checkout');

        $connection->execute(
            'test -d ' . $paths->shared() . '/storage && ln -sfn ' . $paths->shared() . '/storage ' . $release . '/storage || true',
        )->throw();
    }

    private function recordCommit(SSHConnection $connection, string $repo, string $branch): void
    {
        $result = $connection->execute("git --git-dir={$repo} log -1 --format='%H%n%an%n%s' {$branch} 2>/dev/null || true");
        $lines = array_values(array_filter(array_map(trim(...), preg_split('/\R/', $result->output) ?: []), static fn(string $line): bool => $line !== ''));

        if (count($lines) < 2 || preg_match('/^[0-9a-f]{40}$/', $lines[0]) !== 1) {
            return;
        }

        [$hash, $author] = $lines;
        $subject = $lines[2] ?? null;

        $tag = trim($connection->execute("git --git-dir={$repo} describe --tags --exact-match {$hash} 2>/dev/null || true")->output);
        $tag = preg_match('/^[A-Za-z0-9._\/-]{1,120}$/', $tag) === 1 ? $tag : null;

        $signed = null;

        if ($tag !== null) {
            $object = $connection->execute('git --git-dir=' . $repo . ' cat-file -p ' . escapeshellarg('refs/tags/' . $tag) . ' 2>/dev/null || true')->output;
            $signed = str_contains($object, 'BEGIN PGP SIGNATURE') || str_contains($object, 'BEGIN SSH SIGNATURE');
        }

        $this->deployment->forceFill([
            'commit_hash' => $this->deployment->commit_hash ?? $hash,
            'commit_message' => $this->deployment->commit_message ?? $subject,
            'commit_author' => $author,
            'tag' => $tag,
            'tag_signed' => $signed,
        ])->save();

        $this->appendLog('commit ' . substr($hash, 0, 7) . ' by ' . $author . ($tag !== null ? ' · tag ' . $tag : ''));
    }

    private function installDependencies(SSHConnection $connection, DeploymentCommands $commands, string $release): void
    {
        $steps = $commands->dependencies($this->deployment->application);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::DEPENDENCIES, 'installing dependencies');
        $this->runInRelease($connection, $commands, $release, $steps, DeploymentPhases::DEPENDENCIES, self::DEPENDENCY_RETRIES);
    }

    private function build(SSHConnection $connection, DeploymentCommands $commands, string $release): void
    {
        $steps = $commands->build($this->deployment->application);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::BUILD, 'building the release');
        $this->runInRelease($connection, $commands, $release, $steps, DeploymentPhases::BUILD, 0);
    }

    private function migrate(SSHConnection $connection, DeploymentCommands $commands, string $release): void
    {
        $steps = $commands->migrations($this->deployment->application);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::MIGRATIONS, 'running migrations against release ' . $this->timestamp);
        $this->runInRelease($connection, $commands, $release, $steps, DeploymentPhases::MIGRATIONS, 0);
    }

    private function checkHealth(SSHConnection $connection, DeploymentCommands $commands, ReleasePaths $paths, string $release): void
    {
        $this->mark(DeploymentPhases::HEALTH, 'checking the release before it goes live');

        foreach ($commands->healthChecks($this->deployment->application, $paths, $release) as $description => $check) {
            $result = $connection->execute($check);

            if ($result->failed()) {
                throw new DeploymentAborted("Health check failed: {$description} is missing, so the release was not put live.");
            }

            $this->appendLog('health: ' . $description . ' present');
        }
    }

    private function swapSymlink(SSHConnection $connection, ReleasePaths $paths, string $release): void
    {
        $current = $paths->current();

        $this->mark(DeploymentPhases::SWAP, 'current → releases/' . $this->timestamp);

        $connection->execute("ln -sfn {$release} {$current}.tmp")->throw();
        $connection->execute("mv -Tf {$current}.tmp {$current}")->throw();

        $this->swapped = true;
        $this->deployment->application->forceFill(['current_release' => $this->timestamp])->save();

        $this->appendLog('symlink: current → releases/' . $this->timestamp . ' (atomic swap)');
    }

    private function startProcesses(SSHConnection $connection, ProcessManager $processes, Application $application): void
    {
        if (in_array($application->power_state, [PowerState::Stopped, PowerState::Sleeping], true)) {
            $this->appendLog('processes: ' . $application->name . ' is ' . $application->power_state->value . ', so the new release starts when the app does');

            return;
        }

        $processes->refresh($connection, $application);

        try {
            $processes->verifyStarted($connection, $application);
        } catch (ProcessDidNotStart $e) {
            $this->appendLog($e->journal);
            $this->failureReason = $e->getMessage();

            throw $e;
        }
    }

    private function watchProcesses(SSHConnection $connection, ProcessManager $processes, Application $application): void
    {
        if (in_array($application->power_state, [PowerState::Stopped, PowerState::Sleeping], true)) {
            return;
        }

        if ($processes->unitNames($application) === []) {
            return;
        }

        $seconds = CrashLoopWatch::CHECKS * CrashLoopWatch::INTERVAL_SECONDS;

        $this->appendLog($this->stamp() . " watch: keeping an eye on the processes for {$seconds} seconds");

        $loop = app(CrashLoopWatch::class)->watch($connection, $application);

        if ($loop === null) {
            $this->appendLog('watch: every process stayed up');

            return;
        }

        $this->appendLog($loop->output);
        $this->crashLooping = true;
        $this->failureReason = "Crash loop: {$loop->unit} kept stopping in the minute after going live. releases/{$this->timestamp} is live but not serving, so roll back to the previous release or fix the error in the log.";

        throw new DeploymentAborted($this->failureReason);
    }

    private function routeTraffic(SSHConnection $connection, Application $application): void
    {
        if ($application->allDomains() === []) {
            return;
        }

        app(CaddyApiClient::class)->putSite($connection, $application);

        $this->appendLog('caddy: route for ' . implode(', ', $application->allDomains()) . ' is in place');
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
        $script = $release . '/.coresmith-blueprint.sh';

        $connection->uploadContent((string) $application->custom_blueprint, $script);

        $result = $connection->execute(
            'cd ' . $release . ' && ' . $commands->pathPrefix($application) . 'bash ' . $script,
        );

        $this->appendLog($result->output);

        $result->throw();

        $connection->execute('rm -f ' . $script);
    }

    private function writeCrontab(SSHConnection $connection, Application $application): void
    {
        $builder = app(CrontabBuilder::class);
        $crontab = $builder->build($application);

        $connection->uploadContent($crontab, '/tmp/coresmith-cron-' . $application->slug);
        $connection->execute(
            'cat /tmp/coresmith-cron-' . $application->slug . ' | ' . $builder->install($application)
                . ' && rm -f /tmp/coresmith-cron-' . $application->slug,
            sudo: true,
        )->throw();
    }

    private function pruneOldReleases(SSHConnection $connection, ReleasePaths $paths): void
    {
        $releases = $paths->releases();
        $keep = ReleasePaths::KEEP_RELEASES;

        $connection->execute(
            "ls -1dt {$releases}/*/ 2>/dev/null | tail -n +" . ($keep + 1) . ' | xargs -r rm -rf',
        )->throw();
    }

    private function cleanUpFailedRelease(SSHConnection $connection, string $release): void
    {
        try {
            $connection->execute('rm -rf ' . $release);
        } catch (Throwable) {
            $this->appendLog('Could not remove the half-built release; the stale deployment detector will reclaim it.');
        }
    }

    /**
     * @param  list<string>  $steps
     */
    private function runInRelease(SSHConnection $connection, DeploymentCommands $commands, string $release, array $steps, string $phase, int $retries): void
    {
        $prefix = $commands->pathPrefix($this->deployment->application);

        foreach ($steps as $step) {
            for ($attempt = 1; ; $attempt++) {
                $this->appendLog('$ ' . $step);

                $result = $connection->execute("cd {$release} && {$prefix}{$step}");

                $this->appendLog($result->output);

                if ($result->successful()) {
                    break;
                }

                if ($attempt > $retries || !$this->looksLikeNetwork($result->output)) {
                    $result->throw();
                }

                $this->appendLog("{$phase} hit a network error (attempt {$attempt} of " . ($retries + 1) . '), retrying in ' . self::RETRY_SECONDS . ' seconds');
                Sleep::for(self::RETRY_SECONDS)->seconds();
            }
        }
    }

    private function looksLikeNetwork(string $output): bool
    {
        foreach (self::NETWORK_ERRORS as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function mark(string $phase, string $detail): void
    {
        $this->appendLog($this->stamp() . ' ' . $phase . ': ' . $detail);
    }

    private function stamp(): string
    {
        return '[' . now()->format('H:i:s') . ']';
    }

    private function trigger(): string
    {
        $user = $this->deployment->triggeredBy;

        return $user === null ? 'push' : $user->name;
    }

    private function appendLog(string $output): void
    {
        if ($output === '') {
            return;
        }

        $clean = app(LogSanitizer::class)->sanitize($output, $this->deployment->application);

        $this->deployment->forceFill([
            'log' => trim((string) $this->deployment->log . "\n" . $clean),
        ])->save();

        DeploymentUpdated::dispatch($this->deployment);
    }

    private function markFailed(string $message): void
    {
        $application = $this->deployment->application;

        $outcome = $this->swapped
            ? ' deploy failed after the symlink swap. releases/' . $this->timestamp . ' is live, check its processes.'
            : ' deploy failed. the current release is untouched.';

        $this->deployment->forceFill([
            'status' => DeploymentStatus::Failed,
            'finished_at' => now(),
            'log' => trim((string) $this->deployment->log . "\n" . $message . "\n" . $this->stamp() . $outcome),
            'failure_reason' => $this->failureReason === null ? null : app(LogSanitizer::class)->sanitize($this->failureReason, $application),
        ])->save();

        $application->forceFill([
            'status' => $application->current_release === null || $this->crashLooping ? ApplicationStatus::Failed : ApplicationStatus::Active,
        ])->save();

        DeploymentUpdated::dispatch($this->deployment);
        DeploymentFailed::dispatch($this->deployment);
    }
}
