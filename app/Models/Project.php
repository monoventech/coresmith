<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['team_id', 'name'];

    protected static function booted(): void
    {
        self::deleting(function (Project $project): void {
            $project->environments()->get()->each(fn(Environment $environment) => $environment->release());
            AccessGrant::query()->where('project_id', $project->getKey())->delete();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class)->orderBy('position')->orderBy('id');
    }
}
