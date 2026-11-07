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
use InvalidArgumentException;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\UnableToConnectException;
use RuntimeException;

final class CheckRemoteHeads implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public const APPLICATIONS_PER_JOB = 5;

    private const KEY_SECONDS = 10;

    private const CHECK_SECONDS = LsRemote::TIMEOUT_SECONDS + 15;

    private const WAIT_SECONDS = 2 * SSHConnection::TIMEOUT_SECONDS;

    private const APPLICATION_SECONDS = 6 * self::KEY_SECONDS + self::CHECK_SECONDS + 2 * self::WAIT_SECONDS;

    private const MARGIN_SECONDS = 20;

    private const NO_MATCHING_REF = 2;

    private const TIMED_OUT = 124;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    private bool $connectionLost = false;

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
        $startBy = now()->addSeconds($this->timeout - self::MARGIN_SECONDS - self::APPLICATION_SECONDS);

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

        $connection = $connections->for($this->server);

        try {
            $this->connect($connection);

            foreach ($applications as $application) {
                if (!$this->connectionLost && now()->greaterThan($startBy)) {
                    break;
                }

                $application->forceFill(['polled_at' => now()])->saveQuietly();

                [$sha, $error] = $this->check($connection, $lsRemote, $keys, $application);

                $autoDeploy->checked($application, $sha, $error);
            }
        } finally {
            $connections->disconnect($this->server);
        }
    }

    private function connect(SSHConnection $connection): void
    {
        try {
            $connection->connect();
        } catch (RuntimeException) {
            $this->connectionLost = true;
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function check(SSHConnection $connection, LsRemote $lsRemote, DeployKeyManager $keys, Application $application): array
    {
        try {
            $command = $lsRemote->command($application);
        } catch (InvalidArgumentException $refusal) {
            return [null, $refusal->getMessage()];
        }

        if ($this->connectionLost) {
            return [null, $this->unreachable()];
        }

        if (!$keys->usesDeployKey($application)) {
            return $this->lsRemote($connection, $lsRemote, $application, $command);
        }

        $application = $keys->ensure($application);
        $key = $keys->pollKeyPath($application);

        try {
            $outcome = $this->keyStep(function () use ($connection, $keys, $application, $key): void {
                $connection->execute($keys->prepareDirectory($application), sudo: true, timeout: self::KEY_SECONDS)->throw();
                $connection->execute($keys->reserveFile($application, $key), sudo: true, timeout: self::KEY_SECONDS)->throw();
                $connection->uploadContent((string) $application->deploy_key_private, $key, timeout: self::KEY_SECONDS);
            }) ?? $this->lsRemote($connection, $lsRemote, $application, $command);
        } finally {
            $removal = $this->keyStep(fn() => $connection->execute($keys->removeCommand($application, $key), sudo: true, timeout: self::KEY_SECONDS)->throw());
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
            if (!$this->lost($e)) {
                return [null, "Coresmith could not set up the deploy key on {$this->server->name}."];
            }

            $this->connectionLost = true;

            return [null, $this->unreachable()];
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function lsRemote(SSHConnection $connection, LsRemote $lsRemote, Application $application, string $command): array
    {
        try {
            $result = $connection->execute($command, sudo: true, timeout: self::CHECK_SECONDS);
        } catch (RuntimeException) {
            $this->connectionLost = true;

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
}
