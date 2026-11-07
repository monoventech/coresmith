<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use Database\Factories\TeamSsoConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamSsoConnection extends Model
{
    /** @use HasFactory<TeamSsoConnectionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'team_id',
        'issuer',
        'client_id',
        'client_secret',
        'email_domain',
        'domain_token',
        'domain_verified_at',
        'enabled',
        'jit_provisioning',
    ];

    protected $hidden = ['client_secret', 'domain_token'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(SsoIdentity::class, 'team_id', 'team_id');
    }

    public function isVerified(): bool
    {
        return $this->domain_verified_at !== null;
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->isVerified();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => Vaulted::class,
            'domain_verified_at' => 'datetime',
            'enabled' => 'boolean',
            'jit_provisioning' => 'boolean',
        ];
    }
}
