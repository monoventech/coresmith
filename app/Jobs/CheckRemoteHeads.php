<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandTimedOut;
use App\Models\Application;
use App\Models\Server;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\AutoDeploy\LsRemote;
use App\Services\Deployment\GitFailure;
use App\Services\Git\DeployKeyManager;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use App\Services\SSH\SSHResult;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\UnableToConnectException;
use RuntimeException;

final class CheckRemoteHeads implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const APPLICATIONS_PER_JOB = 5;

    private const COMMAND_SECONDS = LsRemote::TIMEOUT_SECONDS + 15;

    private const NO_MATCHING_REF = 2;

    private const TIMED_OUT = 124;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    /**
     * @param  list<string>  $applicationIds
     */
    public function __construct(
        private readonly Server $server,
        private readonly array $applicationIds,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        $ids = $this->applicationIds;
        sort($ids);

        return $this->server->getKey() . ':' . implode(',', $ids);
    }

    public function handle(SSHConnectionManager $connections, LsRemote $lsRemote, DeployKeyManager $keys, AutoDeploy $autoDeploy): void
    {
        if ($this->server->status !== ServerStatus::Active || $this->server->trashed()) {
            return;
        }

        $applications = Application::query()
            ->whereKey($this->applicationIds)
            ->where('server_id', $this->server->getKey())
            ->where('quick_deploy', true)
            ->whereNotNull('repository_url')
            ->orderBy('id')
            ->get();

        if ($applications->isEmpty()) {
            return;
        }

        $applications->each(fn(Application $application) => $application->forceFill(['polled_at' => now()])->saveQuietly());
        $connection = $connections->for($this->server);

        try {
            $reachable = $this->connect($connection);

            foreach ($applications as $application) {
                try {
                    $command = $lsRemote->command($application);
                } catch (InvalidArgumentException $refusal) {
                    $this->conclude($autoDeploy, $application, null, $refusal->getMessage());

                    continue;
                }

                [$sha, $error] = $reachable ? $this->check($connection, $keys, $lsRemote, $application, $command) : [null, $this->unreachable()];

                $this->conclude($autoDeploy, $application, $sha, $error);
            }
        } finally {
            $connections->disconnect($this->server);
        }
    }

    private function connect(SSHConnection $connection): bool
    {
        try {
            $connection->connect();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function check(SSHConnection $connection, DeployKeyManager $keys, LsRemote $lsRemote, Application $application, string $command): array
    {
        if (!$keys->usesDeployKey($application)) {
            return $this->lsRemote($connection, $lsRemote, $application, $command);
        }

        $application = $keys->ensure($application);
        $key = $keys->pollKeyPath($application);

        try {
            $outcome = $this->keyStep(function () use ($connection, $keys, $application, $key): void {
                $connection->execute($keys->prepareDirectory($application), sudo: true)->throw();
                $connection->execute($keys->reserveFile($application, $key), sudo: true)->throw();
                $connection->uploadContent((string) $application->deploy_key_private, $key);
            }) ?? $this->lsRemote($connection, $lsRemote, $application, $command);
        } finally {
            $removal = $this->keyStep(fn() => $connection->execute($keys->removeCommand($application, $key), sudo: true)->throw());
        }

        return $removal ?? $outcome;
    }

    /**
     * @return array{0: null, 1: string}|null
     */
    private function keyStep(Closure $step): ?array
    {
        try {
            $step();

            return null;
        } catch (RuntimeException $e) {
            return [null, $this->lost($e) ? $this->unreachable() : "Coresmith could not set up the deploy key on {$this->server->name}."];
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function lsRemote(SSHConnection $connection, LsRemote $lsRemote, Application $application, string $command): array
    {
        try {
            $result = $connection->execute($command, sudo: true, timeout: self::COMMAND_SECONDS);
        } catch (RuntimeException) {
            return [null, $this->unreachable()];
        }

        $sha = $result->successful() ? $lsRemote->parse($result->output, (string) $application->repository_branch) : null;

        return [$sha, $sha === null ? $this->failure($application, $result) : null];
    }

    private function lost(RuntimeException $e): bool
    {
        return $e instanceof ConnectionClosedException
            || $e instanceof UnableToConnectException
            || $e instanceof SSHAuthenticationException
            || $e instanceof HostKeyChangedException
            || $e instanceof SSHCommandTimedOut;
    }

    private function failure(Application $application, SSHResult $result): string
    {
        if ($result->successful() || $result->exitCode === self::NO_MATCHING_REF) {
            return "Branch {$application->repository_branch} is not in the repository.";
        }

        $unrecognised = $result->exitCode === self::TIMED_OUT
            ? 'the repository did not answer within ' . LsRemote::TIMEOUT_SECONDS . ' seconds.'
            : "git ls-remote failed with exit code {$result->exitCode}. A deploy shows the full output.";

        return GitFailure::from($result->output, $application, unrecognised: $unrecognised)->reason;
    }

    private function unreachable(): string
    {
        return "Coresmith could not connect to {$this->server->name}.";
    }

    private function conclude(AutoDeploy $autoDeploy, Application $application, ?string $sha, ?string $error): void
    {
        $application->forceFill(['poll_error' => $error === null ? null : Str::limit($error, 255, '')])->saveQuietly();

        if ($sha !== null && $sha !== $application->repository_head) {
            $autoDeploy->observe($application, $sha, 'poll');

            return;
        }

        $autoDeploy->reconcile($application);
    }
}
