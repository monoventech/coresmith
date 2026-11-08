<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoveCode extends Model
{
    use HasUlids, Prunable;

    protected $guarded = ['id'];

    protected $hidden = ['secret_key', 'file'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function usable(): bool
    {
        return $this->imported_at === null && $this->secret_key !== null && $this->expires_at->isFuture();
    }

    public function prunable(): Builder
    {
        return static::query()->whereNull('imported_at')->where('expires_at', '<', now());
    }

    protected function casts(): array
    {
        return [
            'secret_key' => Vaulted::class,
            'expires_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }
}
