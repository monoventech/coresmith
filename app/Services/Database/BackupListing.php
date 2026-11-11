<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

final readonly class BackupListing
{
    /**
     * @param  list<Backup>  $backups
     */
    public function __construct(
        public array $backups,
        public ?string $serverError = null,
        public ?string $s3Error = null,
    ) {}
}
