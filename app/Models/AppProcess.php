<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppProcess extends Model
{
    public const DAY_SECONDS = 86400;

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function restartsToday(): int
    {
        $since = now()->getTimestamp() - self::DAY_SECONDS;

        return count(array_filter((array) $this->restart_times, fn(mixed $time): bool => (int) $time > $since));
    }

    protected function casts(): array
    {
        return [
            'restart_times' => 'array',
            'started_at' => 'datetime',
            'read_at' => 'datetime',
            'memory_bytes' => 'integer',
            'restarts' => 'integer',
        ];
    }
}
