<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class GenerateVaultKey extends Command
{
    protected $signature = 'coresmith:vault-key {--force : Overwrite an existing key}';

    protected $description = 'Generate the CORESMITH_VAULT_KEY used to encrypt server credentials';

    public function handle(): int
    {
        $path = base_path('.env');

        if (!File::exists($path)) {
            $this->error('No .env file found.');

            return self::FAILURE;
        }

        $existing = config('coresmith.vault_key');

        if (!empty($existing) && !$this->option('force')) {
            $this->error('CORESMITH_VAULT_KEY is already set. Overwriting it makes every stored credential unrecoverable.');
            $this->line('Run it again with --force if you mean to.');

            return self::FAILURE;
        }

        $contents = File::get($path);
        $key = base64_encode(random_bytes(32));

        $contents = preg_match('/^CORESMITH_VAULT_KEY=.*$/m', $contents) === 1
            ? preg_replace('/^CORESMITH_VAULT_KEY=.*$/m', 'CORESMITH_VAULT_KEY=' . $key, $contents)
            : $contents . PHP_EOL . 'CORESMITH_VAULT_KEY=' . $key . PHP_EOL;

        File::put($path, (string) $contents);

        $this->info('Vault key generated.');
        $this->line('Back up your .env separately. Without this key every stored credential is unrecoverable.');

        return self::SUCCESS;
    }
}
