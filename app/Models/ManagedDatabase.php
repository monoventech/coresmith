<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use App\Enums\DatabaseType;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use Database\Factories\ManagedDatabaseFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class ManagedDatabase extends Model
{
    /** @use HasFactory<ManagedDatabaseFactory> */
    use HasFactory, HasUlids, IgnoresJsonKeyOrder, SoftDeletes;

    public const CREDENTIALS_LOCK_SECONDS = 1320;

    public const SHARED_REDIS_PORT = 6379;

    protected $table = 'databases';

    protected $guarded = ['id'];

    protected $hidden = ['db_password'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function onSharedRedis(): bool
    {
        return $this->type === DatabaseType::Redis && (int) $this->port === self::SHARED_REDIS_PORT;
    }

    public function hasPassword(): bool
    {
        return !$this->onSharedRedis();
    }

    public function takesBackups(): bool
    {
        return $this->type !== DatabaseType::Redis;
    }

    public function credentialsLock(): Lock
    {
        return Cache::lock('database-credentials:' . $this->getKey(), self::CREDENTIALS_LOCK_SECONDS);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'db_password' => Vaulted::class,
            'type' => DatabaseType::class,
            'extensions' => 'array',
            'is_external' => 'boolean',
            'last_offsite_at' => 'datetime',
            'credentials_shown_at' => 'datetime',
        ];
    }
}
