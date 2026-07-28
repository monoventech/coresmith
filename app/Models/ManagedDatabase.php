<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use App\Enums\DatabaseType;
use Database\Factories\ManagedDatabaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManagedDatabase extends Model
{
    /** @use HasFactory<ManagedDatabaseFactory> */
    use HasFactory, HasUlids, SoftDeletes;

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
        ];
    }
}
