<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use Database\Factories\GitHubAppFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GitHubApp extends Model
{
    /** @use HasFactory<GitHubAppFactory> */
    use HasFactory, HasUlids, IgnoresJsonKeyOrder;

    protected $table = 'github_apps';

    protected $guarded = ['id'];

    protected $hidden = ['client_secret', 'webhook_secret', 'private_key'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(SourceProvider::class, 'github_app_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => Vaulted::class,
            'webhook_secret' => Vaulted::class,
            'private_key' => Vaulted::class,
            'permissions' => 'array',
            'events' => 'array',
        ];
    }
}
