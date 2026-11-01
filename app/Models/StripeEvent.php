<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class StripeEvent extends Model
{
    use MassPrunable;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function prunable(): Builder
    {
        return static::query()->where('processed_at', '<', now()->subDays(30));
    }

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
