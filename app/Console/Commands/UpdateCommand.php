<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InstanceSetting;
use App\Services\Updates\PanelUpdater;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdatePaths;
use App\Services\Updates\UpdateRefused;
use App\Services\Updates\UpdateRequest;
use App\Services\Updates\UpdateState;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;

final class UpdateCommand extends Command
{
    protected $signature = 'coresmith:update
        {--to= : Update to this version rather than the newest one}
        {--force : Update without asking first}
        {--recover : Finish or undo an update that was interrupted}
        {--requested : Run the update the panel asked for}
        {--auto : Ask for the nightly patch update when the owner turned them on}';

    protected $description = 'Update this panel to a newer Coresmith release, with a backup and automatic rollback';

    public function handle(PanelUpdater $updater, UpdatePaths $paths, UpdateState $state, UpdateChecker $checker): int
    {
        $listener = function (string $line): void {
            $this->line($line);
        };

        try {
            if ($this->option('auto')) {
                $this->line($this->requestPatch($state, $checker));

                return self::SUCCESS;
            }

            if ($this->option('requested')) {
                return $this->requested($updater, $state, $paths, $listener);
            }

            if ($this->option('recover')) {
                $status = $updater->recover($listener, failed: true);

                if ($status === null) {
                    $this->line('Nothing to recover.');

                    return self::SUCCESS;
                }

                return $this->report($status, $paths, recovering: true);
            }

            $updater->assertCanUpdate();
            $current = Version::current();
            $requested = $this->option('to');
            $target = $updater->target(is_string($requested) && $requested !== '' ? $requested : null);

            if ($target === null) {
                $this->line("You're on the latest version, {$current->tag()}.");

                return self::SUCCESS;
            }

            $this->line("Current version: {$current->tag()}");
            $this->line("Update available: {$target->tag()}");

            if (!$this->option('force') && !$this->confirm("Update Coresmith from {$current->tag()} to {$target->tag()}?")) {
                return self::SUCCESS;
            }

            return $this->report($updater->run($target, 'cli', null, $listener), $paths);
        } catch (UpdateRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function requested(PanelUpdater $updater, UpdateState $state, UpdatePaths $paths, Closure $listener): int
    {
        $updater->assertOwner();
        $lock = $state->acquireLock() ?? throw new UpdateRefused('Another update is running. The request waits until you cancel it on the General settings tab.');

        try {
            $request = $state->takeRequest();

            if ($request === null) {
                $this->line('No update was requested.');

                return self::SUCCESS;
            }

            if ($request->to === null) {
                $status = $updater->recover($listener, held: $lock);

                return $status === null ? self::SUCCESS : $this->report($status, $paths, recovering: true);
            }

            return $this->report($updater->runRequest($request, $lock, $listener), $paths);
        } finally {
            $lock->release();
        }
    }

    private function requestPatch(UpdateState $state, UpdateChecker $checker): string
    {
        if (InstanceSetting::get('auto_update') !== '1') {
            return 'Automatic updates are off.';
        }

        if ($state->pendingRequest() !== null || $state->status()?->isRunning() === true || $state->isLocked()) {
            return 'An update is already under way.';
        }

        $target = $checker->check()->autoTarget(Version::current());

        if ($target === null) {
            return 'No patch update to install.';
        }

        $state->request(new UpdateRequest($target, 'auto', null, CarbonImmutable::now()));

        return "Requested the patch update to {$target->tag()}.";
    }

    private function report(UpdateStatus $status, UpdatePaths $paths, bool $recovering = false): int
    {
        if ($status->state === UpdateStatus::SUCCEEDED && !$recovering) {
            $this->line("Coresmith updated to v{$status->toVersion}.");

            return self::SUCCESS;
        }

        $this->line("Log: {$paths->runs()}/{$status->id}.log");

        if ($recovering && $status->state !== UpdateStatus::FAILED) {
            return self::SUCCESS;
        }

        $this->line('Report it at ' . config('coresmith.updates.issues_url') . ' if it keeps happening.');

        return self::FAILURE;
    }
}
