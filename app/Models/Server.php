<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use App\Enums\ServerStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['ssh_private_key'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ssh_private_key' => Vaulted::class,
            'status' => ServerStatus::class,
            'installed_runtimes' => 'array',
            'meta' => 'array',
            'audit' => 'array',
            'audit_logging_enabled' => 'boolean',
            'last_connected_at' => 'datetime',
            'host_key_changed_at' => 'datetime',
            'booted_at' => 'datetime',
        ];
    }
}
