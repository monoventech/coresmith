<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PowerState;
use App\Exceptions\SSHCommandFailedException;
use App\Models\Application;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Notifications\DatabaseRestoreNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use App\Services\Database\Backup;
use App\Services\Database\DatabaseBackups;
use App\Services\Database\DatabaseManager;
use App\Services\Database\OffsiteBackups;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class RestoreDatabase implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 2700;

    /**
     * @param  list<string>  $stop
     */
    public function __construct(
        public readonly ManagedDatabase $database,
        public readonly string $stamp,
        public readonly ?string $key,
        public readonly int $size,
        public readonly array $stop,
        public readonly string $by,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(SSHConnectionManager $connections, DatabaseManager $databases, OffsiteBackups $offsite): void
    {
        $name = (string) $this->database->name;
        $lock = $this->database->credentialsLock();

        if (!$lock->get()) {
            $this->finish(false, "Coresmith is still working on {$name}. Try again in a minute.");

            return;
        }

        $server = $this->database->server;
        $folder = DatabaseManager::BACKUP_ROOT . '/' . $name;
        $working = "{$folder}/.restore-{$this->stamp}.sql.gz";
        $when = Backup::at($this->stamp)->format('M j, H:i');
        $stopped = [];

        try {
            $this->step('backing_up');
            $safetyStamp = now()->format('YmdHis');

            try {
                $safety = $databases->backup($connections->for($server), $this->database, $safetyStamp);
            } catch (Throwable $e) {
                $this->finish(false, "Coresmith couldn't back up {$name} as it is now, so nothing changed. " . SSHCommandFailedException::reason($this->runtime($e)));

                return;
            }

            $this->step('copying', ['safety' => $safetyStamp]);

            try {
                $this->copy($connections, $offsite, "{$folder}/{$name}-{$this->stamp}.sql.gz", $working);
            } catch (Throwable $e) {
                $this->finish(false, "Coresmith couldn't get the backup from {$when} ready, so nothing changed. " . SSHCommandFailedException::reason($this->runtime($e)));

                return;
            }

            $this->step('stopping');

            foreach ($this->applications() as $application) {
                try {
                    app()->call([new StopApplication($application), 'handle']);
                    $stopped[] = $application;
                    app()->call([new SyncApplicationRoute($application->fresh() ?? $application), 'handle']);
                } catch (Throwable $e) {
                    $this->start($stopped);
                    $this->finish(false, "Coresmith couldn't stop {$application->name}, so nothing changed. " . SSHCommandFailedException::reason($this->runtime($e)));

                    return;
                }
            }

            $this->step('restoring');

            try {
                $databases->restore($connections->for($server), $this->database, $working);
            } catch (Throwable $e) {
                $reason = SSHCommandFailedException::reason($this->runtime($e));

                try {
                    $databases->restore($connections->for($server), $this->database, $safety);
                    $error = "Coresmith couldn't restore the backup from {$when}, so it put back the copy it took first. {$reason}";
                } catch (Throwable) {
                    $error = "Coresmith couldn't restore the backup from {$when}, or put back the copy it took first, so {$name} may be empty. Restore {$safety} on {$server->name} by hand. {$reason}";
                }

                $this->step('starting');
                $this->start($stopped);
                $this->finish(false, $error);

                return;
            }

            $this->step('starting');
            $this->start($stopped);
            $this->finish(true, null);
        } finally {
            rescue(fn() => $connections->for($server)->execute('rm -f ' . escapeshellarg($working), sudo: true), report: false);
            $connections->disconnect($server);
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $name = (string) $this->database->name;
        $safety = ((array) $this->database->fresh()?->restore)['safety'] ?? null;
        $copy = is_string($safety) ? ' The copy Coresmith took first is the backup from ' . Backup::at($safety)->format('M j, H:i') . '.' : '';

        $this->finish(false, "The restore of {$name} stopped before it finished, so {$name} may be empty and apps Coresmith stopped may still be stopped.{$copy}");
    }

    private function copy(SSHConnectionManager $connections, OffsiteBackups $offsite, string $source, string $working): void
    {
        $connection = $connections->for($this->database->server);
        $destination = $this->database->team?->backupDestination;

        if ($this->key === null) {
            $connection->execute('cp -p ' . escapeshellarg($source) . ' ' . escapeshellarg($working), sudo: true)->throw();

            return;
        }

        if (!$destination instanceof BackupDestination) {
            throw new RuntimeException('The team no longer sends backups to S3.');
        }

        $offsite->fetch($connection, $destination, $this->key, $working, $this->size);
    }

    /**
     * @return list<Application>
     */
    private function applications(): array
    {
        return Application::query()
            ->where('server_id', $this->database->server_id)
            ->whereIn('id', $this->stop)
            ->where('power_state', PowerState::Active->value)
            ->get()
            ->all();
    }

    /**
     * @param  list<Application>  $applications
     */
    private function start(array $applications): void
    {
        foreach ($applications as $application) {
            rescue(function () use ($application): void {
                app()->call([new StartApplication($application), 'handle']);
                app()->call([new SyncApplicationRoute($application->fresh() ?? $application), 'handle']);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function step(string $step, array $extra = []): void
    {
        $current = $step === 'backing_up' ? [] : (array) ($this->database->fresh()?->restore ?? []);

        $this->database->forceFill(['restore' => [
            'safety' => null,
            'started_at' => now()->toIso8601String(),
            ...$current,
            ...$extra,
            'state' => 'running',
            'stamp' => $this->stamp,
            'step' => $step,
            'error' => null,
            'by' => $this->by,
            'finished_at' => null,
        ]])->save();
    }

    private function finish(bool $succeeded, ?string $error): void
    {
        $fresh = $this->database->fresh() ?? $this->database;
        $restore = (array) $fresh->restore;

        $fresh->forceFill(['restore' => [
            'safety' => null,
            'started_at' => now()->toIso8601String(),
            'step' => null,
            ...$restore,
            'state' => $succeeded ? 'done' : 'failed',
            'stamp' => $this->stamp,
            'by' => $this->by,
            'error' => $error,
            'finished_at' => now()->toIso8601String(),
        ]])->save();

        app(DatabaseBackups::class)->forget($fresh);

        $by = User::query()->find($this->by);
        $when = Backup::at($this->stamp)->format('M j, H:i');

        activity()->performedOn($fresh)->causedBy($by)->withProperties(['from' => $this->stamp])->log($succeeded ? 'database_restored' : 'database_restore_failed');

        rescue(fn() => app(TeamNotifier::class)->send($fresh->team, Preferences::SERVER_ALERTS, new DatabaseRestoreNotification($fresh, $succeeded, $when, $error), about: $fresh->server), report: true);
    }

    private function runtime(Throwable $e): RuntimeException
    {
        return $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
    }
}
