<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Install\SetupToken;
use Illuminate\Console\Command;

final class SetupTokenCommand extends Command
{
    protected $signature = 'coresmith:setup-token {--force : Issue a new token even if one already exists}';

    protected $description = 'Issue the one-time token that unlocks the first-run setup page';

    public function handle(SetupToken $token): int
    {
        if (User::query()->exists()) {
            $this->error('This instance already has an account. Setup is closed.');

            return self::FAILURE;
        }

        if ($token->exists() && !$this->option('force')) {
            $this->error('A setup token already exists at ' . $token->path() . '. Pass --force to replace it.');

            return self::FAILURE;
        }

        $this->line('Open this link to create the owner account. It works once:');
        $this->line(rtrim((string) config('app.url'), '/') . route('install.show', ['token' => $token->generate()], false));

        return self::SUCCESS;
    }
}
