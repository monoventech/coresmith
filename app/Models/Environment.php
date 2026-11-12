<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['project_id', 'name', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(fn(Environment $environment) => $environment->release());
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class);
    }

    public function release(): void
    {
        Application::withTrashed()->where('environment_id', $this->getKey())->update(['environment_id' => null]);
        ManagedDatabase::withTrashed()->where('environment_id', $this->getKey())->update(['environment_id' => null]);
        AccessGrant::query()->where('environment_id', $this->getKey())->delete();
    }
}
