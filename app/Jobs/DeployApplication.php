<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Events\DeploymentSucceeded;
use App\Events\DeploymentUpdated;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\ExplainsFailure;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\DeploymentLog;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Deployment\GitFailure;
use App\Services\Deployment\LogSanitizer;
use App\Services\Deployment\ReleasePaths;
use App\Services\Git\DeployKeyManager;
use App\Services\Process\CrashLoopWatch;
use App\Services\Process\ProcessManager;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\RuntimeBinaries;
use App\Services\Runtime\RuntimeLifecycle;
use App\Services\Runtime\Stack;
use App\Services\Runtime\StackResolver;
use App\Services\Server\PortBlockGuard;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use phpseclib3\Exception\ConnectionClosedException;
use Throwable;

final class DeployApplication implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 780;

    public const LOCK_SECONDS = 10800;

    public const DISK_ABORT_PERCENT = 95;

    public const GIT_RETRIES = 2;

    public const DEPENDENCY_RETRIES = 1;

    public const RETRY_SECONDS = 5;

    private const NETWORK_ERRORS = ['ETIMEDOUT', 'ECONNRESET', 'ECONNREFUSED', 'ENOTFOUND', 'EAI_AGAIN', 'Could not resolve host', 'Connection timed out', 'Operation timed out', 'curl error 28', 'curl error 6', 'could not be downloaded', 'Failed to download', '502 Bad Gateway', '503 Service Unavailable', '504 Gateway Time', 'socket hang up', 'Net::OpenTimeout', 'Gem::RemoteFetcher'];

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    private bool $swapped = false;

    private bool $handedOff = false;

    private bool $lockLost = false;

    private ?string $failureReason = null;

    private bool $crashLooping = false;

    public function __construct(
        private readonly Deployment $deployment,
        private readonly string $timestamp,
        private readonly ?string $lockOwner = null,
    ) {
        $this->onQueue('deployments');
    }

    public function handle(SSHConnectionManager $connections, DeploymentCommands $commands): void
    {
        $application = $this->deployment->application;
        $lock = $this->lock($application);

        if ($lock === null) {
            return;
        }

        $connection = $connections->for($application->server);
        $paths = new ReleasePaths($application);
        $release = $paths->release($this->timestamp);

        try {
            if ($this->lockOwner === null) {
                $stack = $this->begin($connection, $paths, $release, $application);

                if ($stack->missing() !== []) {
                    $this->handOff($stack, $lock);

                    return;
                }
            } else {
                $stack = $this->resume($connection, $lock);

                if ($stack === null) {
                    return;
                }
            }

            $this->finish($connection, $commands, $paths, $release, $application, $stack);
        } catch (Throwable $e) {
            if (!$this->swapped) {
                $this->cleanUpFailedRelease($connection, $release);
            }

            $this->failureReason ??= $e instanceof ExplainsFailure ? $e->getMessage() : null;

            $this->markFailed(app(LogSanitizer::class)->sanitize($e->getMessage(), $this->deployment->application));

            throw $e;
        } finally {
            if (!$this->handedOff) {
                $lock->release();
            }

            $connections->disconnect($application->server);
        }
    }

    private function lock(Application $application): ?Lock
    {
        if ($this->lockOwner !== null) {
            return $this->deployment->status === DeploymentStatus::Running
                ? Cache::restoreLock('deploy:' . $application->getKey(), $this->lockOwner)
                : null;
        }

        $lock = Cache::lock('deploy:' . $application->getKey(), self::LOCK_SECONDS);

        if (!$lock->get()) {
            $this->deployment->forceFill([
                'status' => DeploymentStatus::Cancelled,
                'log' => 'Another deployment is already in progress.',
            ])->save();

            return null;
        }

        try {
            DB::transaction(function () use ($application): void {
                $this->deployment->forceFill([
                    'status' => DeploymentStatus::Running,
                    'started_at' => now(),
                    'stale_after' => now()->addMinutes(DetectStaleDeployments::STALE_AFTER_MINUTES),
                    'release' => $this->timestamp,
                    'branch' => $application->repository_branch,
                ])->save();

                $application->forceFill(['status' => ApplicationStatus::Deploying])->save();
            });
        } catch (Throwable $e) {
            $lock->release();

            throw $e;
        }

        return $lock;
    }

    private function begin(SSHConnection $connection, ReleasePaths $paths, string $release, Application $application): Stack
    {
        $this->guardDiskSpace($connection);

        $this->appendLog($this->stamp() . ' release ' . $this->timestamp . ' · branch ' . $application->repository_branch . ' · triggered by ' . $this->trigger());

        $this->claimPorts($connection, $application);

        $this->reconnecting($connection, DeploymentPhases::CLONE, fn() => $this->prepare($connection, $paths, $release));

        return $this->resolveStack($connection, $paths, $release, $application);
    }

    private function resolveStack(SSHConnection $connection, ReleasePaths $paths, string $release, Application $application): Stack
    {
        $asdf = app(AsdfManager::class);
        $binaries = app(RuntimeBinaries::class);
        $facts = $this->reconnecting($connection, DeploymentPhases::CLONE, fn() => app(RepositoryProbe::class)->read($connection, $paths->appDirectory($release)));
        $stack = $this->reconnecting($connection, DeploymentPhases::CLONE, function () use ($connection, $application, $facts, $asdf, $binaries): Stack {
            $stack = app(StackResolver::class)->resolve($application, $facts, fn(string $runtime): array => $asdf->installedVersions($connection, $runtime));

            return $this->verifyInstalled($connection, $binaries, $stack);
        });

        $this->deployment->forceFill(['stack' => $stack->toArray()])->save();

        if ($stack->runtimes !== []) {
            $this->mark(DeploymentPhases::DEPENDENCIES, 'resolving runtimes from the repository');
        }

        foreach ($stack->runtimes as $runtime) {
            $this->appendLog($this->describe($runtime));
        }

        foreach ($stack->notes as $note) {
            $this->appendLog($note);
        }

        return $stack;
    }

    private function verifyInstalled(SSHConnection $connection, RuntimeBinaries $binaries, Stack $stack): Stack
    {
        $runtimes = [];

        foreach ($stack->runtimes as $key => $resolved) {
            $needsCorepack = $resolved->runtime === 'nodejs' && in_array($stack->tooling?->manager, ['pnpm', 'yarn'], true);
            $installed = $resolved->installed && $binaries->installed($connection, $resolved->runtime, $resolved->version, $needsCorepack);

            $runtimes[$key] = $installed === $resolved->installed
                ? $resolved
                : new ResolvedRuntime($resolved->runtime, $resolved->version, $resolved->source, $resolved->detail, $installed, $resolved->note);
        }

        return new Stack($runtimes, $stack->framework, $stack->tooling, $stack->notes);
    }

    private function describe(ResolvedRuntime $runtime): string
    {
        $status = app(RuntimeLifecycle::class)->status($runtime->runtime, $runtime->version)->label();

        return $runtime->describe(app(Catalog::class)->label($runtime->runtime))
            . ($status === '' ? '' : ', ' . lcfirst($status))
            . ($runtime->installed ? '' : ', not installed yet');
    }

    private function handOff(Stack $stack, Lock $lock): void
    {
        $missing = array_map(fn(ResolvedRuntime $runtime): array => ['runtime' => $runtime->runtime, 'version' => $runtime->version], $stack->missing());

        $this->deployment->forceFill(['stale_after' => now()->addSeconds(InstallRuntimes::window())])->save();

        InstallRuntimes::dispatch($this->deployment, $this->timestamp, $missing, (string) $lock->owner());

        $this->handedOff = true;
    }

    private function resume(SSHConnection $connection, Lock $lock): ?Stack
    {
        $extended = Deployment::query()
            ->whereKey($this->deployment->getKey())
            ->where('status', DeploymentStatus::Running->value)
            ->update(['stale_after' => now()->addMinutes(DetectStaleDeployments::STALE_AFTER_MINUTES)]);

        if ($extended === 0) {
            return null;
        }

        if (!$lock->isOwnedByCurrentProcess()) {
            $this->lockLost = true;

            throw new DeploymentAborted('The deploy lock expired while the runtimes installed, so another deploy may have started. Deploy again.');
        }

        $stack = Stack::fromArray($this->deployment->stack);
        $asdf = app(AsdfManager::class);

        $this->reconnecting($connection, DeploymentPhases::DEPENDENCIES, function () use ($connection, $stack, $asdf): void {
            foreach ($stack->runtimes as $runtime) {
                if (!$asdf->isInstalled($connection, $runtime->runtime, $runtime->version)) {
                    throw new DeploymentAborted(app(Catalog::class)->label($runtime->runtime) . ' ' . $runtime->version . ' is still not installed on ' . $connection->server()->name . ', so the deploy stopped before changing anything. Deploy again.');
                }
            }
        });

        $this->appendLog($this->stamp() . ' deps: runtimes installed, carrying on');

        return $stack;
    }

    private function finish(SSHConnection $connection, DeploymentCommands $commands, ReleasePaths $paths, string $release, Application $application, Stack $stack): void
    {
        if ($this->hasCustomBlueprint($application)) {
            $this->mark(DeploymentPhases::BUILD, 'running the custom blueprint');
            $this->runBlueprint($connection, $commands, $release, $application, $stack);
        } else {
            $this->reconnecting($connection, DeploymentPhases::DEPENDENCIES, fn() => $this->installDependencies($connection, $commands, $release, $stack));
            $this->reconnecting($connection, DeploymentPhases::BUILD, fn() => $this->build($connection, $commands, $release, $stack));
            $this->migrate($connection, $commands, $release, $stack);
        }

        $this->checkHealth($connection, $commands, $paths, $release);
        $phpChanged = $this->swapSymlink($connection, $paths, $release, $stack);
        $processes = app(ProcessManager::class);
        $processes->install($connection, $application);
        $this->startProcesses($connection, $processes, $application, $phpChanged);
        $this->routeTraffic($connection, $application);
        app(CrontabBuilder::class)->write($connection, $application);
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
    }

    private function reconnecting(SSHConnection $connection, string $phase, Closure $work): mixed
    {
        try {
            return $work();
        } catch (ConnectionClosedException) {
            $this->appendLog("the SSH connection dropped during {$phase}, reconnecting and running it once more");
            $connection->disconnect();
            Sleep::for(self::RETRY_SECONDS)->seconds();

            try {
                return $work();
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

    private function installDependencies(SSHConnection $connection, DeploymentCommands $commands, string $release, Stack $stack): void
    {
        $steps = $commands->dependencies($this->deployment->application, $stack);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::DEPENDENCIES, 'installing dependencies');
        $this->runInRelease($connection, $commands, $release, $stack, $steps, DeploymentPhases::DEPENDENCIES, self::DEPENDENCY_RETRIES);
    }

    private function build(SSHConnection $connection, DeploymentCommands $commands, string $release, Stack $stack): void
    {
        $steps = $commands->build($this->deployment->application, $stack);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::BUILD, 'building the release');
        $this->runInRelease($connection, $commands, $release, $stack, $steps, DeploymentPhases::BUILD, 0);
    }

    private function migrate(SSHConnection $connection, DeploymentCommands $commands, string $release, Stack $stack): void
    {
        $steps = $commands->migrations($this->deployment->application);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::MIGRATIONS, 'running migrations against release ' . $this->timestamp);
        $this->runInRelease($connection, $commands, $release, $stack, $steps, DeploymentPhases::MIGRATIONS, 0);
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

    private function swapSymlink(SSHConnection $connection, ReleasePaths $paths, string $release, Stack $stack): bool
    {
        $current = $paths->current();
        $application = $this->deployment->application;
        $php = $application->runtimeVersions()['php'] ?? null;

        $this->mark(DeploymentPhases::SWAP, 'current → releases/' . $this->timestamp);

        $connection->execute("ln -sfn {$release} {$current}.tmp")->throw();
        $connection->execute("mv -Tf {$current}.tmp {$current}")->throw();

        $this->swapped = true;
        $application->forceFill(['current_release' => $this->timestamp, ...$stack->applicationAttributes()])->save();

        $this->appendLog('symlink: current → releases/' . $this->timestamp . ' (atomic swap)');

        return $php !== null && $php !== ($application->runtimeVersions()['php'] ?? null);
    }

    private function startProcesses(SSHConnection $connection, ProcessManager $processes, Application $application, bool $restart): void
    {
        if (in_array($application->power_state, [PowerState::Stopped, PowerState::Sleeping], true)) {
            $this->appendLog('processes: ' . $application->name . ' is ' . $application->power_state->value . ', so the new release starts when the app does');

            return;
        }

        $processes->refresh($connection, $application, $restart);

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

    private function runBlueprint(SSHConnection $connection, DeploymentCommands $commands, string $release, Application $application, Stack $stack): void
    {
        $script = $release . '/.coresmith-blueprint.sh';

        $connection->uploadContent((string) $application->custom_blueprint, $script);

        $result = $connection->execute(
            'cd ' . $release . ' && ' . $commands->pathPrefix($stack->versions()) . 'bash ' . $script,
        );

        $this->appendLog($result->output);

        $result->throw();

        $connection->execute('rm -f ' . $script);
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
            $this->appendLog("Could not remove the half-built release. Remove releases/{$this->timestamp} by hand if it is still there.");
        }
    }

    /**
     * @param  list<string>  $steps
     */
    private function runInRelease(SSHConnection $connection, DeploymentCommands $commands, string $release, Stack $stack, array $steps, string $phase, int $retries): void
    {
        $prefix = $commands->pathPrefix($stack->versions());

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
        return app(DeploymentLog::class)->stamp();
    }

    private function trigger(): string
    {
        $user = $this->deployment->triggeredBy;

        return $user === null ? 'push' : $user->name;
    }

    private function appendLog(string $output): void
    {
        app(DeploymentLog::class)->append($this->deployment, $output);
    }

    private function markFailed(string $message): void
    {
        $log = app(DeploymentLog::class);

        if ($this->lockLost) {
            $log->failLeavingApplication($this->deployment, $message, $this->failureReason);

            return;
        }

        $log->fail($this->deployment, $message, $this->failureReason, $this->swapped, $this->crashLooping);
    }
}
