<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\AppSize;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Events\DeploymentSucceeded;
use App\Events\DeploymentUpdated;
use App\Events\FleetChanged;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\DeploymentCancelled;
use App\Exceptions\ExplainsFailure;
use App\Exceptions\GitHubLinkProofException;
use App\Exceptions\GitProviderException;
use App\Exceptions\ProcessDidNotStart;
use App\Exceptions\SSHCommandFailedException;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\SourceProvider;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\DatabaseFailure;
use App\Services\Deployment\DeploymentCancellation;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\DeploymentLog;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Deployment\GitFailure;
use App\Services\Deployment\LiveAnswer;
use App\Services\Deployment\LiveCheck;
use App\Services\Deployment\LogSanitizer;
use App\Services\Deployment\ReleasePaths;
use App\Services\Deployment\RepositoryCache;
use App\Services\Deployment\SharedStorage;
use App\Services\Deployment\StackDefaults;
use App\Services\Detection\Blocker;
use App\Services\Detection\StackChecks;
use App\Services\Git\CloneToken;
use App\Services\Git\DeployKeyManager;
use App\Services\Git\GitHub\ApplicationRelinker;
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitHub\LinkProof;
use App\Services\Git\GitHub\SuspensionCheck;
use App\Services\Git\GitProviderFactory;
use App\Services\Panel\Reverb;
use App\Services\Panel\SmartToggles;
use App\Services\Panel\SsrServer;
use App\Services\Process\CrashLoopWatch;
use App\Services\Process\ProcessManager;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\NativeGemTools;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\RuntimeBinaries;
use App\Services\Runtime\RuntimeLifecycle;
use App\Services\Runtime\Stack;
use App\Services\Runtime\StackResolver;
use App\Services\SafeName;
use App\Services\Server\PortBlockGuard;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use phpseclib3\Exception\ConnectionClosedException;
use Throwable;

final class DeployApplication implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public const TIMEOUT = 780;

    public const QUEUE = 'deployments';

    public const LOCK_SECONDS = 10800;

    public const DISK_ABORT_PERCENT = 95;

    public const GIT_RETRIES = 2;

    public const DEPENDENCY_RETRIES = 1;

    public const RETRY_SECONDS = 5;

    public const LOCK_HELD = 'Another deploy or a rollback is running for this app, so this one did not start.';

    public const NEVER_STARTED = 'The deploy never started, so Coresmith ended it. Deploy again.';

    private const SSR_STOPPED = 'ssr: inertia:start-ssr did not start, pages render in the browser until it does';

    private const NETWORK_ERRORS = ['ETIMEDOUT', 'ECONNRESET', 'ECONNREFUSED', 'ENOTFOUND', 'EAI_AGAIN', 'Could not resolve host', 'Connection timed out', 'Operation timed out', 'curl error 28', 'curl error 6', 'could not be downloaded', 'Failed to download', '502 Bad Gateway', '503 Service Unavailable', '504 Gateway Time', 'socket hang up', 'Net::OpenTimeout', 'Gem::RemoteFetcher'];

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    private bool $swapped = false;

    private bool $handedOff = false;

    private bool $lockLost = false;

    private bool $pastCancelPoint = false;

    private ?string $failureReason = null;

    private bool $keepFailed = false;

    private ?string $cloneAdvice = null;

    public function __construct(
        private readonly Deployment $deployment,
        private readonly string $timestamp,
        private readonly ?string $lockOwner = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(SSHConnectionManager $connections, DeploymentCommands $commands): void
    {
        if ($this->deployment->status === DeploymentStatus::Cancelled) {
            return;
        }

        $application = $this->deployment->application;

        if ($application === null) {
            $this->cancelForRemovedApplication();

            return;
        }

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

            if ($this->stoppedByRequest($e)) {
                $cancellation = app(DeploymentCancellation::class);
                $cancellation->note($this->deployment);
                $cancellation->finish($this->deployment);

                return;
            }

            if ($e instanceof SSHCommandFailedException) {
                $this->appendUnloggedOutput($e->output());
            }

            $this->failureReason ??= $e instanceof ExplainsFailure ? $e->getMessage() : null;

            $this->markFailed(app(LogSanitizer::class)->sanitize($e->getMessage(), $this->deployment->application));

            throw $e;
        } finally {
            if (!$this->handedOff) {
                $lock->release();
            }

            $connections->disconnect($application->server);

            if (!$this->handedOff) {
                $this->followUp();
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $deployment = $this->deployment->fresh();

        if ($deployment?->status === DeploymentStatus::Pending) {
            app(DeploymentLog::class)->failIfNeverStarted($deployment, self::NEVER_STARTED);
        }

        if ($deployment?->status !== DeploymentStatus::Running) {
            $this->releaseKilledLock(false);

            return;
        }

        $swapped = $deployment->application?->current_release === $this->timestamp;

        if (DeploymentCancellation::endsCancelled($deployment)) {
            $this->cancelKilled($deployment, $swapped);

            return;
        }

        $failed = app(DeploymentLog::class)->failIfRunning($deployment, $this->killedReason($e), $swapped);

        if ($failed && !$swapped) {
            $this->removeKilledRelease($deployment);
        }

        $this->releaseKilledLock($failed);
    }

    private function cancelKilled(Deployment $deployment, bool $swapped): void
    {
        $cancelled = Deployment::query()
            ->whereKey($deployment->getKey())
            ->where('status', DeploymentStatus::Running->value)
            ->update(['status' => DeploymentStatus::Cancelled->value, 'finished_at' => now()]);

        if ($cancelled === 0) {
            $this->releaseKilledLock(false);

            return;
        }

        try {
            $cancellation = app(DeploymentCancellation::class);
            $cancellation->note($deployment);
            $cancellation->finish($deployment);
        } finally {
            if (!$swapped) {
                $this->removeKilledRelease($deployment);
            }

            $this->releaseKilledLock(true);
            $this->followUp();
        }
    }

    private function releaseKilledLock(bool $ended): void
    {
        if ($this->lockOwner !== null) {
            Cache::restoreLock($this->lockKey(), $this->lockOwner)->release();
        } elseif ($ended) {
            Cache::lock($this->lockKey())->forceRelease();
        }
    }

    private function killedReason(Throwable $e): string
    {
        return match (true) {
            $e instanceof TimeoutExceededException => 'The deploy ran longer than ' . intdiv(self::TIMEOUT, 60) . ' minutes, so Coresmith stopped it. Deploy again.',
            $e instanceof MaxAttemptsExceededException => 'The worker running this deploy stopped before the deploy finished, so Coresmith ended it. Deploy again.',
            default => 'The deploy stopped before it finished, so Coresmith ended it. Deploy again.',
        };
    }

    private function removeKilledRelease(Deployment $deployment): void
    {
        $application = $deployment->application;
        $server = $application?->server;

        if ($application === null || $server === null || preg_match(RollbackApplication::RELEASE_PATTERN, $this->timestamp) !== 1) {
            return;
        }

        $connections = app(SSHConnectionManager::class);
        $paths = new ReleasePaths($application);

        $connections->disconnect($server);

        try {
            $connection = $connections->for($server);
            $current = $connection->execute('readlink ' . escapeshellarg($paths->current()), sudo: true);

            if ($current->successful() && basename(rtrim(trim($current->output), '/')) === $this->timestamp) {
                return;
            }

            $connection->execute('rm -rf ' . escapeshellarg($paths->release($this->timestamp)), sudo: true)->throw();

            if ($this->holdsLock()) {
                app(SharedStorage::class)->discard($connection, $deployment);
            }
        } catch (Throwable $e) {
            report($e);
            rescue(fn() => app(DeploymentLog::class)->append($deployment, "Could not remove the half-built release. Remove releases/{$this->timestamp} by hand if it is still there."), report: true);
        } finally {
            $connections->disconnect($server);
        }
    }

    private function holdsLock(): bool
    {
        if ($this->lockOwner === null) {
            return true;
        }

        $lock = Cache::restoreLock($this->lockKey(), $this->lockOwner);

        return $lock instanceof Lock && rescue(fn(): bool => $lock->isOwnedByCurrentProcess(), false, false);
    }

    private function lockKey(): string
    {
        return 'deploy:' . $this->deployment->application_id;
    }

    private function cancelForRemovedApplication(): void
    {
        Deployment::query()
            ->whereKey($this->deployment->getKey())
            ->whereIn('status', [DeploymentStatus::Pending->value, DeploymentStatus::Running->value])
            ->update([
                'status' => DeploymentStatus::Cancelled->value,
                'finished_at' => now(),
                'log' => trim((string) $this->deployment->log . "\nThe app was removed while it deployed."),
            ]);

        if ($this->lockOwner !== null) {
            Cache::restoreLock($this->lockKey(), $this->lockOwner)->release();
        }
    }

    private function followUp(): void
    {
        rescue(fn() => app(AutoDeploy::class)->afterDeployment($this->deployment->fresh() ?? $this->deployment), report: true);
    }

    private function lock(Application $application): ?Lock
    {
        if ($this->lockOwner !== null) {
            return $this->deployment->status === DeploymentStatus::Running
                ? Cache::restoreLock($this->lockKey(), $this->lockOwner)
                : null;
        }

        $lock = Cache::lock($this->lockKey(), self::LOCK_SECONDS);

        if (!$lock->get()) {
            $collided = Deployment::query()
                ->whereKey($this->deployment->getKey())
                ->where('status', DeploymentStatus::Pending->value)
                ->update(['status' => DeploymentStatus::Cancelled->value, 'log' => self::LOCK_HELD]);

            if ($collided > 0) {
                FleetChanged::forDeployment($this->deployment);
            }

            return null;
        }

        try {
            $started = DB::transaction(function () use ($application): bool {
                $claimed = Deployment::query()
                    ->whereKey($this->deployment->getKey())
                    ->where('status', DeploymentStatus::Pending->value)
                    ->update(['status' => DeploymentStatus::Running->value]);

                if ($claimed === 0) {
                    return false;
                }

                $this->deployment->forceFill([
                    'status' => DeploymentStatus::Running,
                    'started_at' => now(),
                    'stale_after' => now()->addMinutes(DetectStaleDeployments::STALE_AFTER_MINUTES),
                    'release' => $this->timestamp,
                    'branch' => $application->repository_branch,
                ])->save();

                $application->forceFill(['status' => ApplicationStatus::Deploying])->save();

                return true;
            });
        } catch (Throwable $e) {
            $lock->release();

            throw $e;
        }

        if (!$started) {
            $lock->release();

            return null;
        }

        FleetChanged::forDeployment($this->deployment);

        return $lock;
    }

    private function begin(SSHConnection $connection, ReleasePaths $paths, string $release, Application $application): Stack
    {
        $this->guardDiskSpace($connection);
        $this->checkpoint();

        $this->appendLog($this->stamp() . ' release ' . $this->timestamp . ' · branch ' . $application->repository_branch . ' · triggered by ' . $this->trigger());

        $this->claimPorts($connection, $application);

        $this->reconnecting($connection, DeploymentPhases::CLONE, fn() => $this->prepare($connection, $paths, $release));
        $this->checkpoint();

        $stack = $this->resolveStack($connection, $paths, $release, $application);
        $this->reconnecting($connection, DeploymentPhases::CLONE, fn() => app(EnvironmentFile::class)->write($connection, $application, $stack->toArray()));
        $this->checkpoint();

        return $stack;
    }

    private function resolveStack(SSHConnection $connection, ReleasePaths $paths, string $release, Application $application): Stack
    {
        $asdf = app(AsdfManager::class);
        $binaries = app(RuntimeBinaries::class);
        $facts = $this->reconnecting($connection, DeploymentPhases::CLONE, fn() => app(RepositoryProbe::class)->read($connection, $paths->appDirectory($release), $application));
        $this->refuseUnrunnable($application, $facts);
        $this->fillMissingSecrets($application, $facts);
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

    private function refuseUnrunnable(Application $application, RepositoryFacts $facts): void
    {
        $blockers = app(StackChecks::class)->blockers($application, $facts, fn(): array => [], runtime: false);

        if ($blockers === []) {
            return;
        }

        $this->failureReason = implode(' ', array_map(fn(Blocker $blocker): string => trim($blocker->reason . ' ' . $blocker->fix), $blockers));

        throw new DeploymentAborted($this->failureReason);
    }

    private function fillMissingSecrets(Application $application, RepositoryFacts $facts): void
    {
        $defaults = app(StackDefaults::class);
        $missing = $defaults->missing($application, $facts);

        if ($missing === []) {
            return;
        }

        $application->forceFill(['env_variables' => $defaults->fill((string) $application->env_variables, $missing)])->save();

        foreach (array_keys($missing) as $name) {
            $this->appendLog("{$name} wasn't set, so Coresmith generated one and saved it in the app's environment.");
        }
    }

    private function recordLiveAnswer(SSHConnection $connection, Application $application): void
    {
        $answer = rescue(fn(): ?LiveAnswer => app(LiveCheck::class)->ask($connection, $application), report: false);

        if (!$answer instanceof LiveAnswer) {
            return;
        }

        $this->deployment->forceFill(['live_status' => $answer->status, 'live_detail' => $answer->detail])->save();
        $this->appendLog('check: ' . $answer->url . ' answered ' . $answer->status . ($answer->detail === null ? '' : '. The app logged: ' . $answer->detail));
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

        return $stack->withRuntimes($runtimes);
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

        $this->checkpoint();

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
            $this->checkpoint();
            $this->pastCancelPoint = true;
            $this->mark(DeploymentPhases::BUILD, 'running the custom blueprint');
            $this->runBlueprint($connection, $release, $application, $stack);
        } else {
            $stopBuild = fn() => $this->stopBuild($connection, $application);
            $this->checkpoint();
            $this->reconnecting($connection, DeploymentPhases::DEPENDENCIES, fn() => $this->installDependencies($connection, $commands, $release, $stack), $stopBuild);
            $this->checkpoint();
            $this->reconnecting($connection, DeploymentPhases::BUILD, fn() => $this->build($connection, $commands, $release, $stack), $stopBuild);
            $this->checkpoint();
            $this->pastCancelPoint = true;
            $this->migrate($connection, $commands, $release, $stack);
        }

        $this->checkHealth($connection, $commands, $paths, $release);
        $phpChanged = $this->swapSymlink($connection, $paths, $release, $stack);
        $toggles = app(SmartToggles::class);
        $processes = app(ProcessManager::class);
        $dropped = $toggles->syncDeployTimeProcesses($application);

        foreach ($toggles->leftOutOfRelease($application) as $note) {
            $this->appendLog($note);
        }

        if (app(Reverb::class)->live($application)) {
            $this->appendLog('reverb: routing wss://' . $application->domain . '/app to port ' . app(Reverb::class)->port($application));
        }

        $this->reconnecting($connection, DeploymentPhases::WATCH, function () use ($connection, $processes, $application, $dropped, $phpChanged): void {
            $processes->removeUnits($connection, $application, $dropped);
            $processes->install($connection, $application);
            $this->startProcesses($connection, $processes, $application, $phpChanged);
            $this->routeTraffic($connection, $application);
            app(CrontabBuilder::class)->write($connection, $application);
        });
        $this->pruneOldReleases($connection, $application);
        $this->watchProcesses($connection, $processes, $application);
        $this->recordLiveAnswer($connection, $application);

        if (DeploymentCancellation::requested($this->deployment)) {
            app(DeploymentCancellation::class)->note($this->deployment);
            $this->appendLog('cancel: asked after migrations started, so the deploy carried on');
        }

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

    private function reconnecting(SSHConnection $connection, string $phase, Closure $work, ?Closure $beforeRetry = null): mixed
    {
        try {
            return $work();
        } catch (ConnectionClosedException) {
            $this->appendLog("the SSH connection dropped during {$phase}, reconnecting and running it once more");
            $connection->disconnect();
            Sleep::for(self::RETRY_SECONDS)->seconds();

            try {
                if ($beforeRetry !== null) {
                    $beforeRetry();
                }

                return $work();
            } catch (ConnectionClosedException $e) {
                $this->failureReason = 'The SSH connection to ' . $connection->server()->name . " dropped twice during {$phase}. Check the server is reachable, then deploy again.";

                throw $e;
            }
        }
    }

    private function stopBuild(SSHConnection $connection, Application $application): void
    {
        $connection->execute('systemctl stop ' . AppShell::buildUnit($application, $this->timestamp) . '.service || true', sudo: true);
    }

    private function git(SSHConnection $connection, string $command, string $step, ?Closure $beforeRetry = null): void
    {
        $application = $this->deployment->application;
        $attempts = self::GIT_RETRIES + 1;

        for ($attempt = 1; ; $attempt++) {
            $result = $connection->execute($command, sudo: true);

            if ($result->successful()) {
                return;
            }

            $this->appendLog($result->output);
            $failure = GitFailure::from($result->output, $application, $this->cloneAdvice);

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
        $url = (string) $application->repository_url;
        $branch = escapeshellarg(SafeName::assertBranch($application->repository_branch));
        $keys = app(DeployKeyManager::class);
        $tokens = app(CloneToken::class);
        $cache = app(RepositoryCache::class);
        $account = app(AppAccount::class);
        $credential = 'none';
        $token = null;

        try {
            $this->cloneAdvice = null;
            $github = $this->cloneConnection($application);
            $token = $github === null ? null : $this->mintCloneToken($application, $github);
            $credential = match (true) {
                $token !== null => 'token',
                $github === null && $keys->usesDeployKey($application) => 'key',
                default => 'none',
            };

            $this->mark(DeploymentPhases::CLONE, match ($credential) {
                'token' => 'fetching with a one-hour GitHub token (read-only, ' . $application->repositoryFullName() . ')',
                'key' => 'fetching via deploy key (ed25519, read-only)',
                default => $github === null ? 'fetching over https' : 'GitHub gave no token, fetching without credentials',
            });

            $account->ensure($connection, $application);
            app(EnvironmentFile::class)->write($connection, $application);

            if ($token !== null) {
                $this->installCloneToken($connection, $keys, $tokens, $application, $token);
            } elseif ($credential === 'key') {
                $this->installDeployKey($connection, $keys, $application);
            }

            $git = fn(string $arguments): string => match ($credential) {
                'token' => $tokens->wrap($application, $arguments),
                'key' => $keys->withKey($application, 'git ' . $arguments),
                default => 'git ' . $arguments,
            };

            $this->git($connection, "test -d {$repo} || " . $git($cache->cloneArguments($url, $repo)), 'clone', fn() => $connection->execute('rm -rf ' . $repo, sudo: true));
            $connection->execute($cache->setUrlCommand($repo, $url), sudo: true)->throw();
            $this->git($connection, $git($cache->fetchArguments($repo)), 'fetch');
        } finally {
            if ($credential === 'key') {
                $connection->execute($keys->removeCommand($application), sudo: true);
            }

            if ($token !== null) {
                $this->revokeCloneToken($token);
                $connection->execute($tokens->removeCommand($application), sudo: true);
            }
        }

        $connection->execute('chmod 700 ' . $repo, sudo: true)->throw();

        $this->recordCommit($connection, $repo, $branch);

        $connection->execute('install -d -m 750 ' . $release, sudo: true)->throw();
        $this->git($connection, "set -o pipefail; git --git-dir={$repo} archive {$branch} | tar -x -C {$release}", 'checkout');
        $connection->execute($account->claimRelease($application, $release), sudo: true)->throw();

        $this->prepareShared($connection, $paths, $release, $application);
    }

    private function prepareShared(SSHConnection $connection, ReleasePaths $paths, string $release, Application $application): void
    {
        $storage = app(SharedStorage::class);
        $result = $connection->execute(
            app(AppShell::class)->run($application, 'mkdir -p ' . escapeshellarg($paths->shared() . '/logs') . '; ' . $storage->script($application, $release), $release),
            sudo: true,
        );

        if ($storage->seeded($result->output)) {
            $this->deployment->forceFill(['seeded_shared_storage' => true])->save();
            $this->appendLog("storage: copied the live release's data into shared/storage. Changes made while this deploy runs stay in the previous release.");
        }

        if ($storage->seededDatabase($result->output)) {
            $this->deployment->forceFill(['seeded_shared_database' => true])->save();
            $this->appendLog("storage: copied the live release's SQLite database into shared/database. Changes made while this deploy runs stay in the previous release.");
        }

        if (!$result->successful()) {
            $this->deployment->forceFill(['failed_step' => DeploymentPhases::STORAGE])->save();
        }

        $result->throw();
    }

    private function recordCommit(SSHConnection $connection, string $repo, string $branch): void
    {
        $result = $connection->execute("git --git-dir={$repo} log -1 --format='%H%n%an%n%s' {$branch} 2>/dev/null || true", sudo: true);
        $lines = array_values(array_filter(array_map(trim(...), preg_split('/\r\n|\n|\r/', $result->output) ?: []), static fn(string $line): bool => $line !== ''));

        if (count($lines) < 2 || preg_match(AutoDeploy::COMMIT, $lines[0]) !== 1) {
            return;
        }

        [$hash, $author] = $lines;
        $subject = $lines[2] ?? null;

        $tag = trim($connection->execute("git --git-dir={$repo} describe --tags --exact-match {$hash} 2>/dev/null || true", sudo: true)->output);
        $tag = preg_match('/^[A-Za-z0-9._\/-]{1,120}$/D', $tag) === 1 ? $tag : null;

        $signed = null;

        if ($tag !== null) {
            $object = $connection->execute('git --git-dir=' . $repo . ' cat-file -p ' . escapeshellarg('refs/tags/' . $tag) . ' 2>/dev/null || true', sudo: true)->output;
            $signed = str_contains($object, 'BEGIN PGP SIGNATURE') || str_contains($object, 'BEGIN SSH SIGNATURE');
        }

        $this->deployment->forceFill([
            'commit_hash' => $hash,
            'commit_message' => $subject ?? $this->deployment->commit_message,
            'commit_author' => $author,
            'tag' => $tag,
            'tag_signed' => $signed,
        ])->save();

        app(AutoDeploy::class)->archived($this->deployment, $hash);

        $this->appendLog('commit ' . substr($hash, 0, 7) . ' by ' . $author . ($tag !== null ? ' · tag ' . $tag : ''));

        PostCommitStatus::dispatchFor($this->deployment);
    }

    private function installDependencies(SSHConnection $connection, DeploymentCommands $commands, string $release, Stack $stack): void
    {
        $steps = $commands->dependencies($this->deployment->application, $stack);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::DEPENDENCIES, 'installing dependencies');

        if ($this->deployment->application->type === ApplicationType::Ruby) {
            $this->installGemTools($connection, $release);
        }

        $this->enableCorepackCommands($connection, $stack);
        $this->runInRelease($connection, $release, $stack, $steps, DeploymentPhases::DEPENDENCIES, self::DEPENDENCY_RETRIES);
    }

    private function installGemTools(SSHConnection $connection, string $release): void
    {
        $tools = app(NativeGemTools::class);
        $lockfile = (new ReleasePaths($this->deployment->application))->appDirectory($release) . '/Gemfile.lock';
        $needed = $tools->needed($connection->server(), $connection->execute($tools->find($lockfile), sudo: true)->output);
        $missing = $tools->missing($connection, array_values(array_unique(array_merge(...array_values($needed)))));

        if ($missing === []) {
            return;
        }

        $gems = array_keys(array_filter($needed, fn(array $packages): bool => array_intersect($packages, $missing) !== []));
        $this->appendLog($this->stamp() . ' deps: installing ' . implode(', ', $missing) . ', which ' . implode(' and ', $gems) . (count($gems) === 1 ? ' needs' : ' need') . ' to build');
        $tools->install($connection, $missing);
    }

    private function enableCorepackCommands(SSHConnection $connection, Stack $stack): void
    {
        $node = $stack->versions()['nodejs'] ?? null;

        if ($node === null || (!in_array($stack->tooling?->manager, ['yarn', 'pnpm'], true) && $this->deployment->application->type !== ApplicationType::Ruby)) {
            return;
        }

        $bin = escapeshellarg(app(AsdfManager::class)->versionPath('nodejs', $node) . '/bin');
        $corepack = escapeshellarg(app(AsdfManager::class)->binaryPath('nodejs', $node, 'corepack'));

        $connection->execute("test ! -x {$corepack} || PATH={$bin}:/usr/bin:/bin {$corepack} enable --install-directory {$bin}", sudo: true);
    }

    private function build(SSHConnection $connection, DeploymentCommands $commands, string $release, Stack $stack): void
    {
        $steps = $commands->build($this->deployment->application, $stack);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::BUILD, 'building the release');

        if ($stack->tooling !== null && $stack->tooling->build && app(SmartToggles::class)->ssrBuilds($this->deployment->application, $stack)) {
            $this->appendLog('ssr: building with build:ssr');
        }

        $this->runInRelease($connection, $release, $stack, $steps, DeploymentPhases::BUILD, 0);
    }

    private function migrate(SSHConnection $connection, DeploymentCommands $commands, string $release, Stack $stack): void
    {
        $steps = $commands->migrations($this->deployment->application, $stack);

        if ($steps === []) {
            return;
        }

        $this->mark(DeploymentPhases::MIGRATIONS, 'running migrations against release ' . $this->timestamp);
        $this->runInRelease($connection, $release, $stack, $steps, DeploymentPhases::MIGRATIONS, 0);
    }

    private function checkHealth(SSHConnection $connection, DeploymentCommands $commands, ReleasePaths $paths, string $release): void
    {
        $this->mark(DeploymentPhases::HEALTH, 'checking the release before it goes live');

        foreach ($commands->healthChecks($this->deployment->application, $paths, $release) as $description => $check) {
            $result = $connection->execute($check, sudo: true);

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

        $connection->execute("ln -sfn {$release} {$current}.tmp", sudo: true)->throw();
        $connection->execute("mv -Tf {$current}.tmp {$current}", sudo: true)->throw();

        $this->swapped = true;
        $application->forceFill(['current_release' => $this->timestamp, ...$stack->applicationAttributes()])->save();

        $this->appendLog('symlink: current → releases/' . $this->timestamp . ' (atomic swap)');

        return $php !== null && $php !== ($application->runtimeVersions()['php'] ?? null);
    }

    private function startProcesses(SSHConnection $connection, ProcessManager $processes, Application $application, bool $restart): void
    {
        if (in_array($application->power_state, [PowerState::Stopped, PowerState::Sleeping], true)) {
            $this->appendLog('processes: ' . Str::squish($application->name) . ' is ' . $application->power_state->value . ', so the new release starts when the app does');

            return;
        }

        $units = $processes->unitNames($application);

        if ($units !== []) {
            $this->mark(DeploymentPhases::WATCH, 'starting ' . implode(', ', $units));
        }

        $processes->refresh($connection, $application, $restart);

        $server = app(SsrServer::class);
        $ssr = $server->unit($application);

        try {
            $processes->verifyStarted($connection, $application, $ssr === null ? null : array_values(array_diff($units, [$ssr])));
        } catch (ProcessDidNotStart $e) {
            $worker = app(SmartToggles::class)->failedWorker($application, $e->unit);

            $this->appendLog($e->journal);
            $this->keepFailed = $worker === null;
            $this->failureReason = $e->getMessage();
            $this->deployment->forceFill(['start_failed' => true, 'failed_worker' => $worker])->save();

            throw $e;
        }

        if ($ssr !== null && !$server->confirm($connection, $application, $ssr)) {
            $this->appendLog(self::SSR_STOPPED);
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

        $server = app(SsrServer::class);
        $ssr = $server->unit($application);
        $loop = app(CrashLoopWatch::class)->watch($connection, $application);

        if ($loop !== null && $ssr !== null && $loop->unit === $ssr) {
            $server->stop($connection, $application);
            $this->appendLog(self::SSR_STOPPED);
            $loop = app(CrashLoopWatch::class)->watch($connection, $application);
        }

        if ($loop === null) {
            $this->appendLog('watch: every process stayed up');

            return;
        }

        $this->appendLog($loop->output);
        $this->keepFailed = true;
        $size = AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small;
        $this->failureReason = $loop->outOfMemory
            ? "Out of memory: {$loop->unit} went past the {$size->memoryMaxMb()} MB the " . ucfirst($size->value) . " size allows, so it kept being stopped. Pick a bigger size on the app page, then deploy again. releases/{$this->timestamp} is live but not serving, so roll back to the previous release meanwhile."
            : "Crash loop: {$loop->unit} kept stopping in the minute after going live. releases/{$this->timestamp} is live but not serving, so roll back to the previous release or fix the error in the log.";

        throw new DeploymentAborted($this->failureReason);
    }

    private function routeTraffic(SSHConnection $connection, Application $application): void
    {
        if ($application->allDomains() === []) {
            return;
        }

        app(CaddyApiClient::class)->putSite($connection, $application);

        rescue(function () use ($application): void {
            CheckCertificates::dispatch($application->server)->delay(now()->addSeconds(90));
        }, report: true);

        $this->appendLog('caddy: route for ' . implode(', ', $application->allDomains()) . ' is in place');
    }

    private function installDeployKey(SSHConnection $connection, DeployKeyManager $keys, Application $application): void
    {
        $application = $keys->ensure($application);

        $connection->execute($keys->prepareDirectory($application), sudo: true)->throw();
        $connection->execute($keys->reserveFile($application), sudo: true)->throw();
        $connection->uploadContent((string) $application->deploy_key_private, $keys->keyPath($application));
    }

    private function cloneConnection(Application $application): ?SourceProvider
    {
        $connection = $application->githubConnection();

        if ($connection !== null && $connection->suspended_at !== null) {
            app(SuspensionCheck::class)->recheck($connection);
        }

        return $application->usesGitHubToken() ? $application->githubConnection() : app(ApplicationRelinker::class)->relink($application);
    }

    private function mintCloneToken(Application $application, SourceProvider $github): ?string
    {
        try {
            return $this->cloneTokenThrough($application, $github);
        } catch (GitHubLinkProofException $refusal) {
            throw new DeploymentAborted($refusal->getMessage());
        } catch (GitProviderException $refusal) {
            $github->markRefused($refusal);

            $other = app(ApplicationRelinker::class)->relinkAfter($application, $refusal);

            if ($other !== null) {
                try {
                    return $this->cloneTokenThrough($application, $other);
                } catch (GitHubLinkProofException $again) {
                    throw new DeploymentAborted($again->getMessage());
                } catch (GitProviderException $again) {
                    $other->markRefused($again);
                    $refusal = $again;
                }
            }

            $this->cloneAdvice = GitFailure::adviceFor($refusal, $application);

            return null;
        }
    }

    private function cloneTokenThrough(Application $application, SourceProvider $github): string
    {
        app(LinkProof::class)->check($github, (string) $application->repositoryFullName());

        return app(GitProviderFactory::class)->github($github)->cloneToken((string) $application->repository_id);
    }

    private function installCloneToken(SSHConnection $connection, DeployKeyManager $keys, CloneToken $tokens, Application $application, #[\SensitiveParameter] string $token): void
    {
        $connection->execute($keys->prepareDirectory($application), sudo: true)->throw();
        $connection->execute($tokens->reserveCommand($application), sudo: true)->throw();
        $connection->uploadContent($tokens->content($token), $tokens->path($application));
    }

    private function revokeCloneToken(#[\SensitiveParameter] string $token): void
    {
        try {
            app(GitHubAppApi::class)->revokeToken($token);
        } catch (Throwable) {
        }
    }

    private function hasCustomBlueprint(Application $application): bool
    {
        return trim((string) $application->custom_blueprint) !== '';
    }

    private function runBlueprint(SSHConnection $connection, string $release, Application $application, Stack $stack): void
    {
        $paths = new ReleasePaths($application);
        $script = $paths->blueprint();
        $directory = escapeshellarg(dirname($script));

        $connection->execute("mkdir -p {$directory} && chmod 700 {$directory} && install -m 600 -o root -g root /dev/null " . escapeshellarg($script), sudo: true)->throw();
        $connection->uploadContent((string) $application->custom_blueprint, $script);

        try {
            $result = $connection->execute(
                app(AppShell::class)->runScript($application, $script, $paths->appDirectory($release), $stack->versions(), unit: AppShell::blueprintUnit($application, $this->timestamp)),
                sudo: true,
            );

            $this->appendLog($result->output);

            $result->throw();
        } finally {
            $connection->execute('rm -f ' . escapeshellarg($script), sudo: true);
        }
    }

    private function pruneOldReleases(SSHConnection $connection, Application $application): void
    {
        $connection->execute(PruneReleases::command($application), sudo: true)->throw();
    }

    private function cleanUpFailedRelease(SSHConnection $connection, string $release): void
    {
        try {
            $connection->execute('rm -rf ' . $release, sudo: true);
        } catch (Throwable) {
            $this->appendLog("Could not remove the half-built release. Remove releases/{$this->timestamp} by hand if it is still there.");
        }

        if (!$this->lockLost) {
            app(SharedStorage::class)->discard($connection, $this->deployment);
        }
    }

    /**
     * @param  list<string>  $steps
     */
    private function runInRelease(SSHConnection $connection, string $release, Stack $stack, array $steps, string $phase, int $retries): void
    {
        $application = $this->deployment->application;
        $directory = (new ReleasePaths($application))->appDirectory($release);
        $shell = app(AppShell::class);
        $unit = $phase === DeploymentPhases::MIGRATIONS
            ? AppShell::migrateUnit($application, $this->timestamp)
            : AppShell::buildUnit($application, $this->timestamp);

        foreach ($steps as $step) {
            for ($attempt = 1; ; $attempt++) {
                $this->appendLog('$ ' . $step);

                $result = $connection->execute($shell->run($application, $step, $directory, $stack->versions(), unit: $unit, environment: app(StackDefaults::class)->build($application)), sudo: true);

                $this->appendLog($result->output);

                if ($result->successful()) {
                    break;
                }

                if (in_array($step, DeploymentCommands::LARAVEL_CACHES, true) && !DeploymentCancellation::requested($this->deployment)) {
                    $this->appendLog("{$step} failed, so Coresmith skipped it. The app runs without that cache. Fix the error above to bring it back.");

                    break;
                }

                if ($attempt > $retries || !$this->looksLikeNetwork($result->output) || DeploymentCancellation::requested($this->deployment)) {
                    if ($phase === DeploymentPhases::MIGRATIONS) {
                        $this->failureReason = DatabaseFailure::reason($result->output, $this->deployment->application->type) ?? $this->failureReason;
                    }

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
        return match ($this->deployment->trigger) {
            'poll' => 'polling',
            'push' => 'push',
            default => Str::squish($this->deployment->triggeredBy?->name ?? 'push'),
        };
    }

    private function appendLog(string $output): void
    {
        app(DeploymentLog::class)->append($this->deployment, $output);
    }

    private function appendUnloggedOutput(string $output): void
    {
        $tail = trim(implode("\n", array_slice(preg_split('/\r\n|\n|\r/', trim($output)) ?: [], -30)));

        if ($tail === '' || str_ends_with(trim((string) $this->deployment->log), app(LogSanitizer::class)->sanitize($tail, $this->deployment->application))) {
            return;
        }

        $this->appendLog($tail);
    }

    private function markFailed(string $message): void
    {
        $log = app(DeploymentLog::class);

        if ($this->lockLost) {
            $log->failLeavingApplication($this->deployment, $message, $this->failureReason);

            return;
        }

        $log->fail($this->deployment, $message, $this->failureReason, $this->swapped, $this->keepFailed);
    }

    private function checkpoint(): void
    {
        if ($this->pastCancelPoint || !DeploymentCancellation::requested($this->deployment)) {
            return;
        }

        app(DeploymentCancellation::class)->note($this->deployment);

        throw new DeploymentCancelled('The deploy was cancelled before its next phase.');
    }

    private function stoppedByRequest(Throwable $e): bool
    {
        if ($e instanceof DeploymentCancelled) {
            return true;
        }

        return !$this->pastCancelPoint && !$this->lockLost && DeploymentCancellation::requested($this->deployment);
    }
}
