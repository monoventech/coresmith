<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamInvoice extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'attempt_count' => 'integer',
            'period_start' => 'datetime',
            'paid_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'stripe_updated_at' => 'datetime',
        ];
    }
}
