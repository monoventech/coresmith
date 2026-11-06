<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncStripeCustomerEmail implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 5;

    public function __construct(public readonly Team $team)
    {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        if ($this->team->hasStripeId() && $this->team->owner?->hasVerifiedEmail() === true) {
            $this->team->updateStripeCustomer(['email' => $this->team->stripeEmail()]);
        }
    }
}
