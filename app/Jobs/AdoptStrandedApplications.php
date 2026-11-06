<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SourceProvider;
use App\Services\Git\GitHub\ApplicationRelinker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class AdoptStrandedApplications implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly SourceProvider $provider)
    {
        $this->onQueue('default');
    }

    public function handle(ApplicationRelinker $relinker): void
    {
        $connection = $this->provider->fresh();

        if ($connection !== null) {
            $relinker->adopt($connection);
        }
    }
}
