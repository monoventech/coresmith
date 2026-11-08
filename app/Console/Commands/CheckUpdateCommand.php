<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Updates\UpdateChecker;
use App\Services\Updates\Version;
use Illuminate\Console\Command;

final class CheckUpdateCommand extends Command
{
    protected $signature = 'coresmith:check-update';

    protected $description = 'Ask the release feed whether a newer Coresmith is out';

    public function handle(UpdateChecker $checker): int
    {
        if (!$checker->enabled()) {
            $this->line(UpdateChecker::OFF);

            return self::SUCCESS;
        }

        $check = $checker->check();

        if (!$check->ok()) {
            $this->error((string) $check->error);

            return self::FAILURE;
        }

        $current = Version::current();
        $available = $check->available($current);

        if ($available === null) {
            $this->line("You're on the latest version, {$current->tag()}.");

            return self::SUCCESS;
        }

        $this->line("Coresmith {$available->tag()} is available. You're running {$current->tag()}.");

        if ($check->feed?->changelogUrl !== null) {
            $this->line("Release notes: {$check->feed->changelogUrl}");
        }

        return self::SUCCESS;
    }
}
