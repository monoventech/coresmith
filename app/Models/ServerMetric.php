<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServerMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetric extends Model
{
    /** @use HasFactory<ServerMetricFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'memory_used_mb' => 'integer',
            'memory_total_mb' => 'integer',
            'disk_used_mb' => 'integer',
            'disk_free_mb' => 'integer',
            'disk_total_mb' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }
}
