<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TeamDomainFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamDomain extends Model
{
    /** @use HasFactory<TeamDomainFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['team_id', 'hostname', 'verification_token', 'verified_at', 'checked_at', 'failure'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
