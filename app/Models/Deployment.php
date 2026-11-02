<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory, HasUlids, IgnoresJsonKeyOrder;

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'tag_signed' => 'boolean',
            'stack' => 'array',
            'seeded_shared_storage' => 'boolean',
            'start_failed' => 'boolean',
            'stale_after' => 'datetime',
        ];
    }
}
