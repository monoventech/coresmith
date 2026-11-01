<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Updates\PanelUpdater;
use App\Services\Updates\UpdatePaths;
use App\Services\Updates\UpdateRefused;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Illuminate\Console\Command;

final class UpdateCommand extends Command
{
    protected $signature = 'coresmith:update
        {--to= : Update to this version rather than the newest one}
        {--force : Update without asking first}
        {--recover : Finish or undo an update that was interrupted}';

    protected $description = 'Update this panel to a newer Coresmith release, with a backup and automatic rollback';

    public function handle(PanelUpdater $updater, UpdatePaths $paths): int
    {
        $listener = function (string $line): void {
            $this->line($line);
        };

        try {
            if ($this->option('recover')) {
                $status = $updater->recover($listener);

                if ($status === null) {
                    $this->line('Nothing to recover.');

                    return self::SUCCESS;
                }

                return $this->report($status, $paths);
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

    private function report(UpdateStatus $status, UpdatePaths $paths): int
    {
        if ($status->state === UpdateStatus::SUCCEEDED) {
            $this->line("Coresmith updated to v{$status->toVersion}.");

            return self::SUCCESS;
        }

        $this->line("Log: {$paths->runs()}/{$status->id}.log");
        $this->line('Report it at ' . config('coresmith.updates.issues_url') . ' if it keeps happening.');

        return self::FAILURE;
    }
}
