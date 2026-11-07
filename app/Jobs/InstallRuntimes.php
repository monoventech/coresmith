<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Exceptions\ExplainsFailure;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\SSHCommandTimedOut;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\DeploymentCancellation;
use App\Services\Deployment\DeploymentLog;
use App\Services\Deployment\ReleasePaths;
use App\Services\Deployment\SharedStorage;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeBinaries;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\UnableToConnectException;
use Throwable;

final class InstallRuntimes implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public const TIMEOUT = 2700;

    public const HANDOFF_MARGIN_SECONDS = 120;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    private ?string $installing = null;

    /**
     * @param  list<array{runtime: string, version: string}>  $runtimes
     */
    public function __construct(
        private readonly Deployment $deployment,
        private readonly string $timestamp,
        private readonly array $runtimes,
        private readonly string $lockOwner,
    ) {
        foreach ($runtimes as $runtime) {
            AsdfManager::assertValid($runtime['runtime'], $runtime['version']);
        }

        $this->onQueue('provisioning');
    }

    public static function window(): int
    {
        return self::TIMEOUT + DeployApplication::TIMEOUT + self::HANDOFF_MARGIN_SECONDS;
    }

    public function handle(SSHConnectionManager $connections, AsdfManager $asdf, RuntimeBinaries $binaries, DeploymentLog $log, Catalog $catalog): void
    {
        if ($this->deployment->status !== DeploymentStatus::Running) {
            return;
        }

        $this->deployment->forceFill(['stale_after' => now()->addSeconds(self::window())])->save();

        $server = $this->deployment->application->server;
        $connection = $connections->for($server);

        try {
            foreach ($this->runtimes as ['runtime' => $runtime, 'version' => $version]) {
                $this->installing = $catalog->label($runtime) . ' ' . $version;
                $this->install($connection, $server, $asdf, $binaries, $log, $runtime, $version);

                if (DeploymentCancellation::requested($this->deployment)) {
                    $this->stopOnRequest($connection, $log);

                    return;
                }
            }
        } catch (Throwable $e) {
            $this->abandon($connection, $log, $e);

            throw $e;
        } finally {
            $connections->disconnect($server);
        }

        DeployApplication::dispatch($this->deployment, $this->timestamp, $this->lockOwner);
    }

    public function failed(Throwable $e): void
    {
        $deployment = $this->deployment->fresh();

        $this->installing ??= $this->installingFromRuntimes();

        if ($deployment?->status === DeploymentStatus::Running) {
            $application = $deployment->application;

            $this->removeReleaseDirectory($deployment, $application);

            if (DeploymentCancellation::endsCancelled($deployment)) {
                $this->cancel($deployment);

                return;
            }

            $application === null
                ? app(DeploymentLog::class)->failLeavingApplication($deployment, $e->getMessage(), $this->reason($e))
                : app(DeploymentLog::class)->fail($deployment, $e->getMessage(), $this->reason($e));
        }

        $this->releaseLock();
    }

    private function installingFromRuntimes(): ?string
    {
        $first = $this->runtimes[0] ?? null;

        return $first === null ? null : app(Catalog::class)->label($first['runtime']) . ' ' . $first['version'];
    }

    private function install(SSHConnection $connection, Server $server, AsdfManager $asdf, RuntimeBinaries $binaries, DeploymentLog $log, string $runtime, string $version): void
    {
        $how = in_array($runtime, ['php', 'ruby'], true)
            ? 'compiling it, usually 5–15 minutes the first time'
            : 'downloading it';

        $log->append($this->deployment, $log->stamp() . " deps: installing {$this->installing}, {$how}");

        Cache::lock('runtime-install:' . $server->getKey() . ':' . $runtime . ':' . $version, self::TIMEOUT)
            ->block(self::TIMEOUT - 60, function () use ($connection, $server, $asdf, $binaries, $log, $runtime, $version): void {
                if ($asdf->isInstalled($connection, $runtime, $version) && !$binaries->runtimeInstalled($connection, $runtime, $version)) {
                    $log->append($this->deployment, "{$this->installing} was left partly installed, so it is removed and installed again");
                    $asdf->uninstall($connection, $runtime, $version);
                }

                $asdf->install($connection, $server, $runtime, $version);

                foreach ($binaries->ensure($connection, $runtime, $version) as $note) {
                    $log->append($this->deployment, $note);
                }
            });

        $this->record($server, $runtime, $version);

        $log->append($this->deployment, "{$this->installing} is installed");
    }

    private function record(Server $server, string $runtime, string $version): void
    {
        $server->refresh();

        $installed = (array) $server->installed_runtimes;
        $versions = array_values(array_unique([...array_map('strval', (array) ($installed[$runtime] ?? [])), $version]));
        usort($versions, 'version_compare');
        $installed[$runtime] = $versions;

        $server->forceFill(['installed_runtimes' => $installed])->save();
    }

    private function stopOnRequest(SSHConnection $connection, DeploymentLog $log): void
    {
        $cancellation = app(DeploymentCancellation::class);

        $cancellation->note($this->deployment);
        $log->append($this->deployment, "cancel: {$this->installing} finishes installing first, the server keeps it for the next deploy");
        $this->removeRelease($connection, $this->deployment);
        $cancellation->finish($this->deployment);
        $this->releaseLock();
        $cancellation->followUp($this->deployment);
    }

    private function abandon(SSHConnection $connection, DeploymentLog $log, Throwable $e): void
    {
        $failure = $e instanceof SSHCommandFailedException ? $e : $e->getPrevious();

        if ($failure instanceof SSHCommandFailedException) {
            $log->append($this->deployment, implode("\n", array_slice(preg_split('/\R/', trim($failure->output())) ?: [], -30)));
        }

        $this->removeRelease($connection, $this->deployment);

        $deployment = $this->deployment->fresh();

        if ($deployment?->status === DeploymentStatus::Running) {
            if (DeploymentCancellation::endsCancelled($deployment)) {
                $this->cancel($deployment);

                return;
            }

            $log->fail($this->deployment, $e->getMessage(), $this->reason($e));
        }

        $this->releaseLock();
    }

    private function cancel(Deployment $deployment): void
    {
        $cancelled = Deployment::query()
            ->whereKey($deployment->getKey())
            ->where('status', DeploymentStatus::Running->value)
            ->update(['status' => DeploymentStatus::Cancelled->value, 'finished_at' => now()]);

        if ($cancelled === 0) {
            $this->releaseLock();

            return;
        }

        $cancellation = app(DeploymentCancellation::class);

        try {
            $cancellation->note($deployment);
            $cancellation->finish($deployment);
        } finally {
            $this->releaseLock();
            $cancellation->followUp($deployment);
        }
    }

    private function removeReleaseDirectory(Deployment $deployment, ?Application $application): void
    {
        $server = $application?->server;

        if ($application === null || $server === null || $application->current_release === $this->timestamp) {
            return;
        }

        $connections = app(SSHConnectionManager::class);
        $connections->disconnect($server);

        try {
            $this->removeRelease($connections->for($server), $deployment);
        } finally {
            $connections->disconnect($server);
        }
    }

    private function removeRelease(SSHConnection $connection, Deployment $deployment): void
    {
        try {
            $connection->execute('rm -rf ' . $this->releasePath(), sudo: true);
        } catch (Throwable) {
            app(DeploymentLog::class)->append($deployment, $this->cannotRemoveReleaseMessage());
        }

        $lock = Cache::restoreLock('deploy:' . $deployment->application_id, $this->lockOwner);

        if ($lock instanceof Lock && rescue(fn(): bool => $lock->isOwnedByCurrentProcess(), false, false)) {
            app(SharedStorage::class)->discard($connection, $deployment);
        }
    }

    private function releasePath(): string
    {
        return (new ReleasePaths($this->deployment->application))->release($this->timestamp);
    }

    private function cannotRemoveReleaseMessage(): string
    {
        return "Could not remove the half-built release. Remove releases/{$this->timestamp} by hand if it is still there.";
    }

    private function reason(Throwable $e): string
    {
        $what = $this->installing ?? 'the runtime';

        return match (true) {
            $e instanceof SSHCommandTimedOut => "Installing {$what} ran for " . SSHCommandTimedOut::duration($e->seconds) . " without finishing, so Coresmith stopped it. Deploy again to retry; if it happens again, check the server's network, memory and CPU.",
            $e instanceof ConnectionClosedException, $e instanceof UnableToConnectException => "Coresmith lost the connection to {$this->deployment->application->server->name} while installing {$what}. Check the server is reachable, then deploy again.",
            $e instanceof ExplainsFailure => $e->getMessage(),
            $e instanceof LockTimeoutException => "Another deploy was still installing {$what}. Deploy again once it finishes.",
            $e instanceof MaxAttemptsExceededException => "Installing {$what} ran for 45 minutes without finishing, so Coresmith stopped it. Deploy again, and check the server's memory and disk if it happens twice.",
            default => "Installing {$what} failed, so nothing changed. The end of the build output is in the log: install what it says is missing, then deploy again.",
        };
    }

    private function releaseLock(): void
    {
        Cache::restoreLock('deploy:' . $this->deployment->application_id, $this->lockOwner)->release();
    }
}
