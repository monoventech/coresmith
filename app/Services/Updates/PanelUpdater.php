<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Deployment\LogSanitizer;
use App\Services\Panel\PanelBackup;
use Closure;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PanelUpdater
{
    public const TOOLS = ['git', 'composer', 'bun'];

    private const UNFINISHED = '. The rollback did not finish: ';

    public function __construct(
        private readonly UpdatePaths $paths,
        private readonly UpdateState $state,
        private readonly UpdateChecker $checker,
        private readonly ReleaseLayout $layout,
        private readonly ReleaseBuilder $builder,
        private readonly PanelServices $services,
        private readonly ReleaseHealth $health,
        private readonly PanelBackup $backups,
        private readonly LogSanitizer $sanitizer,
        private readonly PanelUpdateNotifier $notifier,
    ) {}

    public function assertCanUpdate(): void
    {
        if (!(bool) config('coresmith.updates.enabled')) {
            throw new UpdateRefused('Self-updates are off on this install. Installs made by the Coresmith installer turn them on with CORESMITH_UPDATES_ENABLED=true.');
        }

        $this->assertOwner();

        if (!$this->layout->isManaged()) {
            throw new UpdateRefused("This install doesn't use release directories, so it can't update itself. Run the installer for the version you want instead.");
        }

        if ($this->layout->current() === null) {
            throw new UpdateRefused("Coresmith can't read the release {$this->paths->current()} points at, so it could not roll back a failed update. Run the installer for this version to repair it, then update.");
        }
    }

    public function target(?string $requested): ?Version
    {
        $current = Version::current();

        if ($requested !== null) {
            $target = Version::tryParse($requested) ?? throw new UpdateRefused("\"{$requested}\" is not a version like 1.2.3.");
            $this->assertTarget($target, $current);

            return $target;
        }

        $check = $this->checker->check();

        if (!$check->ok()) {
            throw new UpdateRefused("{$check->error} Pass --to=<version> to update without the feed.");
        }

        return $check->available($current);
    }

    public function run(Version $target, string $trigger = 'cli', ?string $requestedBy = null, ?Closure $listener = null): UpdateStatus
    {
        $this->assertCanUpdate();
        $lock = $this->state->acquireLock() ?? throw new UpdateRefused($this->busy());

        try {
            if ($this->state->status()?->isRunning() === true) {
                $this->recoverHeld($listener);
            }

            $previous = $this->layout->current();
            $run = $this->state->begin(UpdateStatus::start($previous, $target, $trigger, $requestedBy));

            if ($listener !== null) {
                $run->listen($listener);
            }

            try {
                $run->step('preflight');
                $this->preflight($target);
            } catch (UpdateRefused $e) {
                return $this->abort($run, new UpdateFailed('preflight', $e->getMessage()));
            }

            $path = null;

            try {
                $path = $this->layout->incoming();
                $fetched = $this->builder->fetch($target, $path, $run);
                $path = $this->layout->promote($path, ReleaseLayout::name($target, $fetched['commit']));
                $run->release(basename($path));
                $run->mark('built', basename($path));
                $this->builder->build($path, $run);
                $release = $this->layout->seal($path, [
                    'version' => (string) $target,
                    'commit' => $fetched['commit'],
                    'tag' => $target->tag(),
                    'signature' => $fetched['signature']->status->value,
                    'fingerprint' => $fetched['signature']->fingerprint,
                    'built_by' => 'update',
                ]);
            } catch (Throwable $e) {
                $this->discard($path);

                return $this->abort($run, $e);
            }

            try {
                $units = $this->services->active();
                $run->step('drain');
                $this->services->down();
                $run->mark('maintenance', true);
                $this->services->drain((int) config('coresmith.updates.drain_timeout'));
                $run->step('backup');
                $run->mark('backup', $this->backup());
            } catch (Throwable $e) {
                $this->services->up();
                $this->discard($release->path);

                return $this->abort($run, $e);
            }

            try {
                $run->step('migrate');
                $run->mark('migrating', true);
                $this->builder->migrate($release->path, $run);
                $run->step('switch');
                $run->mark('switched', true);
                $switchedAt = PanelServices::now();
                $this->layout->activate($release);
                $run->step('restart');
                $this->services->restart($units, (int) config('coresmith.updates.restart_timeout'), $switchedAt);
                $run->step('health');
                $this->health->check($release, (int) config('coresmith.updates.health_timeout'));
                $run->mark('healthy', true);
            } catch (Throwable $e) {
                return $this->rollBack($run, $previous, $units, $e);
            }

            return $this->succeed($run, $release, $previous);
        } finally {
            $lock->release();
        }
    }

    public function recover(?Closure $listener = null, bool $failed = false): ?UpdateStatus
    {
        if (!$this->state->interrupted() && !($failed && $this->stuck($this->state->status()))) {
            return null;
        }

        $this->assertOwner();
        $lock = $this->state->acquireLock() ?? throw new UpdateRefused($this->busy());

        try {
            $status = $this->state->status();

            return match (true) {
                $status?->isRunning() === true => $this->recoverHeld($listener),
                $failed && $this->stuck($status) => $this->retryRollback($status, $listener),
                default => null,
            };
        } finally {
            $lock->release();
        }
    }

    private function recoverHeld(?Closure $listener): UpdateStatus
    {
        $status = $this->state->status() ?? throw new RuntimeException('There is no update to recover.');
        $run = $this->state->begin($status);

        if ($listener !== null) {
            $run->listen($listener);
        }

        $interrupted = 'The update was interrupted while ' . lcfirst($status->stepLabel());

        if ($status->markers['healthy'] === true) {
            $this->services->up();
            $final = $run->finish(UpdateStatus::SUCCEEDED, 'The update finished, but its last step was interrupted.');
            $this->record($run, 'panel_updated', $final, ['duration_seconds' => $this->duration($final), 'signature' => $final->signature]);

            return $final;
        }

        if ($status->markers['migrating'] === true || $status->markers['switched'] === true) {
            return $this->rollBack($run, $this->previous($status), $this->services->active(), new UpdateFailed($status->failedStep ?? $status->step, $interrupted));
        }

        if ($status->markers['maintenance'] === true) {
            $this->services->up();
        }

        if (is_string($status->markers['built'])) {
            $this->discard($this->paths->releases() . '/' . $status->markers['built']);
        }

        return $this->abort($run, new UpdateFailed($status->step, "{$interrupted}. Nothing changed."));
    }

    private function retryRollback(UpdateStatus $status, ?Closure $listener): UpdateStatus
    {
        $run = $this->state->begin($status);

        if ($listener !== null) {
            $run->listen($listener);
        }

        $reason = Str::before((string) $status->message, self::UNFINISHED);

        return $this->rollBack($run, $this->previous($status), $this->services->active(), new UpdateFailed($status->failedStep ?? $status->step, $reason));
    }

    private function stuck(?UpdateStatus $status): bool
    {
        return $status?->state === UpdateStatus::FAILED && $this->services->isDown();
    }

    private function previous(UpdateStatus $status): ?Release
    {
        return $status->fromRelease === null ? null : $this->layout->find($status->fromRelease);
    }

    private function preflight(Version $target): void
    {
        $this->assertTarget($target, Version::current());

        $deployment = Deployment::query()
            ->whereIn('status', [DeploymentStatus::Pending->value, DeploymentStatus::Running->value])
            ->with('application')
            ->oldest('id')
            ->first();

        if ($deployment !== null) {
            throw new UpdateRefused("A deploy of {$deployment->application?->name} is running. Wait for it to finish, then update.");
        }

        $provisioning = Server::query()->where('status', ServerStatus::Provisioning->value)->oldest('id')->first();

        if ($provisioning !== null) {
            throw new UpdateRefused("{$provisioning->name} is provisioning. Wait for it to finish, then update.");
        }

        $busy = Server::query()->whereNotNull('provider_task')->with('cloudProvider')->get()->first(fn(Server $server): bool => CloudTask::running($server));

        if ($busy !== null) {
            $provider = $busy->cloudProvider?->type->label() ?? 'its provider';

            throw new UpdateRefused("Coresmith is still working on {$busy->name} at {$provider}. Wait for it to finish, then update.");
        }

        $free = @disk_free_space($this->paths->root());
        $needed = (int) config('coresmith.updates.min_free_bytes');

        if ($free !== false && $free < $needed) {
            throw new UpdateRefused(sprintf('Only %.1f GB is free on %s; an update needs %.1f GB. Free some space, then try again.', $free / 1024 ** 3, $this->paths->root(), $needed / 1024 ** 3));
        }

        foreach (self::TOOLS as $tool) {
            try {
                $found = Process::timeout(30)->run([$tool, '--version'])->successful();
            } catch (Throwable) {
                $found = false;
            }

            if (!$found) {
                throw new UpdateRefused("{$tool} isn't installed, and the update needs it. Install it, then try again.");
            }
        }
    }

    private function assertTarget(Version $target, Version $current): void
    {
        if (!$target->isNewerThan($current)) {
            throw new UpdateRefused("{$target->tag()} is not newer than the running {$current->tag()}. Coresmith only updates forward.");
        }

        $check = $this->checker->check();

        if ($check->ok() && $check->feed?->isWithdrawn($target) === true) {
            throw new UpdateRefused("{$target->tag()} was withdrawn, so Coresmith won't install it.");
        }
    }

    private function assertOwner(): void
    {
        $uid = @fileowner($this->paths->root());

        if ($uid === false || $uid === posix_geteuid()) {
            return;
        }

        $owner = posix_getpwuid($uid);
        $name = is_array($owner) ? $owner['name'] : (string) $uid;

        throw new UpdateRefused("Run updates as {$name}: cd {$this->paths->current()} && sudo -u {$name} php artisan coresmith:update");
    }

    private function backup(): string
    {
        try {
            return $this->backups->run()['path'];
        } catch (Throwable $e) {
            throw new UpdateFailed('backup', $this->sanitizer->sanitize("Coresmith couldn't back up the database: " . rtrim($e->getMessage(), '.') . '. Nothing changed.'), $e);
        }
    }

    /**
     * @param  list<string>  $units
     */
    private function rollBack(UpdateRun $run, ?Release $previous, array $units, Throwable $failure): UpdateStatus
    {
        $failedStep = $failure instanceof UpdateFailed ? $failure->step : $run->status()->step;
        $markers = $run->status()->markers;
        $backup = is_string($markers['backup']) ? $markers['backup'] : null;
        $problems = [];
        $restored = false;
        $restoreFailed = false;
        $switchBackFailed = false;

        $run->failing($failedStep);
        $run->step('rollback');

        if ($markers['switched'] === true) {
            try {
                $this->layout->activate($previous ?? throw new RuntimeException('the previous release is gone'));
            } catch (Throwable $e) {
                $switchBackFailed = true;
                $problems[] = "switching back failed: {$e->getMessage()}";
            }
        }

        if ($markers['migrating'] === true && $backup !== null) {
            try {
                $this->backups->restore($backup);
                $restored = true;
            } catch (Throwable $e) {
                $restoreFailed = true;
                $problems[] = $e->getMessage();
            }
        }

        if ($markers['switched'] === true || $restored || $failedStep === 'restart') {
            try {
                $this->services->restart($units, (int) config('coresmith.updates.restart_timeout'));
            } catch (Throwable $e) {
                $problems[] = $e->getMessage();
            }
        }

        if (!$restoreFailed && !$switchBackFailed) {
            try {
                $this->services->up();
            } catch (Throwable $e) {
                $problems[] = "taking the panel out of maintenance failed: {$e->getMessage()}";
            }
        }

        $reason = $this->withoutPeriod($failure->getMessage());

        if ($problems === []) {
            $final = $run->finish(UpdateStatus::ROLLED_BACK, "{$reason}. Coresmith rolled back to v{$run->status()->fromVersion}, and the panel is running as before.", $failedStep);
            $this->record($run, 'panel_update_rolled_back', $final, ['step' => $failedStep, 'message' => $final->message]);
            $this->notify($run, $final);

            return $final;
        }

        $message = "{$reason}" . self::UNFINISHED . implode('; ', array_map(fn(string $problem): string => $this->withoutPeriod($problem), $problems)) . '.';

        if ($this->services->isDown()) {
            $app = "cd {$this->paths->current()} &&";
            $message .= " The panel is in maintenance mode. Fix the problem above, then run {$app} sudo -u coresmith php artisan coresmith:update --recover to finish the rollback.";

            if ($restoreFailed && $backup !== null) {
                $message .= " To restore the backup by hand instead, run {$app} sudo -u coresmith php artisan tinker --execute=\"app(App\\Services\\Panel\\PanelBackup::class)->restore('{$backup}')\", then {$app} sudo -u coresmith php artisan up.";
            }
        }

        $final = $run->finish(UpdateStatus::FAILED, $message, $failedStep);
        $this->record($run, 'panel_update_failed', $final, ['step' => $failedStep, 'message' => $final->message]);
        $this->notify($run, $final);

        return $final;
    }

    private function abort(UpdateRun $run, Throwable $e): UpdateStatus
    {
        $step = $e instanceof UpdateFailed ? $e->step : $run->status()->step;
        $message = $e instanceof UpdateFailed
            ? $e->getMessage()
            : $this->sanitizer->sanitize($this->withoutPeriod($e->getMessage()) . '. Nothing changed.');

        $final = $run->finish(UpdateStatus::ABORTED, $message, $step);

        if ($final->trigger === 'auto') {
            $this->notify($run, $final);
        }

        return $final;
    }

    private function succeed(UpdateRun $run, Release $release, ?Release $previous): UpdateStatus
    {
        $this->services->up();

        try {
            $this->layout->prune($release, $previous);
        } catch (Throwable $e) {
            $run->warn("Coresmith couldn't remove old releases: {$e->getMessage()}");
        }

        $final = $run->finish(UpdateStatus::SUCCEEDED, "Coresmith v{$release->version} is running. Backup taken, health check passed.");
        $this->record($run, 'panel_updated', $final, ['duration_seconds' => $this->duration($final), 'signature' => $final->signature]);

        return $final;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(UpdateRun $run, string $description, UpdateStatus $status, array $extra): void
    {
        try {
            $causer = $status->requestedBy === null ? null : User::query()->find($status->requestedBy);

            activity()
                ->causedBy($causer)
                ->withProperties(['from' => $status->fromVersion, 'to' => $status->toVersion, 'trigger' => $status->trigger, ...$extra])
                ->log($description);
        } catch (Throwable) {
            $run->warn("Coresmith couldn't record this run in the activity log.");
        }
    }

    private function notify(UpdateRun $run, UpdateStatus $status): void
    {
        try {
            if ($this->notifier->failed($status)) {
                return;
            }
        } catch (Throwable) {
        }

        $run->warn($this->notifier->mailable()
            ? "Coresmith couldn't email the owners about this."
            : "Coresmith couldn't email the owners about this. No mail server is set up; add MAIL_MAILER and its settings to {$this->paths->shared()}/.env.");
    }

    private function duration(UpdateStatus $status): int
    {
        return (int) $status->startedAt->diffInSeconds($status->finishedAt ?? $status->updatedAt, true);
    }

    private function discard(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->layout->discard($path);
        } catch (Throwable) {
            return;
        }
    }

    private function busy(): string
    {
        $status = $this->state->status();

        if ($status === null || !$status->isRunning()) {
            return 'Another update is running.';
        }

        $to = $status->toVersion === null ? '' : "to v{$status->toVersion}, ";

        return "Another update is running ({$to}started {$status->startedAt->diffForHumans()}).";
    }

    private function withoutPeriod(string $text): string
    {
        return str_ends_with($text, '.') ? substr($text, 0, -1) : $text;
    }
}
