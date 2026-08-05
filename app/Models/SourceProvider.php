<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use App\Enums\SourceProviderType;
use App\Services\Git\ProviderHost;
use Database\Factories\SourceProviderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SourceProvider extends Model
{
    /** @use HasFactory<SourceProviderFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token', 'app_private_key'];

    protected static function booted(): void
    {
        static::saving(function (self $provider): void {
            $provider->custom_host = ProviderHost::normalise($provider->custom_host);
        });
    }

    public function connected(): bool
    {
        return !$this->type->usesTokens() || $this->access_token !== null;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SourceProviderType::class,
            'access_token' => Vaulted::class,
            'refresh_token' => Vaulted::class,
            'app_private_key' => Vaulted::class,
            'scopes' => 'array',
            'token_expires_at' => 'datetime',
        ];
    }
}
