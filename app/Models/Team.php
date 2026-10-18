<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Services\Teams\TeamSlug;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'owner_id'];

    protected static function booted(): void
    {
        static::creating(function (Team $team): void {
            if (blank($team->slug)) {
                $team->slug = TeamSlug::generate((string) $team->name);
            }
        });
    }

    public function domain(): HasOne
    {
        return $this->hasOne(TeamDomain::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class);
    }

    public function sourceProviders(): HasMany
    {
        return $this->hasMany(SourceProvider::class);
    }

    public function githubApps(): HasMany
    {
        return $this->hasMany(GitHubApp::class);
    }

    public function cloudProviders(): HasMany
    {
        return $this->hasMany(CloudProvider::class);
    }
}
