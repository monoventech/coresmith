<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory, HasUlids, IgnoresJsonKeyOrder;

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function commitMessage(): Attribute
    {
        return Attribute::get(fn(?string $value): ?string => $value === null ? null : mb_scrub($value, 'UTF-8'));
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function commitAuthor(): Attribute
    {
        return Attribute::get(fn(?string $value): ?string => $value === null ? null : mb_scrub($value, 'UTF-8'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'live_status' => 'integer',
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'tag_signed' => 'boolean',
            'stack' => 'array',
            'seeded_shared_storage' => 'boolean',
            'seeded_shared_database' => 'boolean',
            'start_failed' => 'boolean',
            'stale_after' => 'datetime',
            'number' => 'integer',
        ];
    }

    public function getRouteKey(): mixed
    {
        return $this->number ?? $this->release ?? $this->getKey();
    }

    protected static function booted(): void
    {
        static::creating(function (Deployment $deployment): void {
            if ($deployment->number !== null || $deployment->application_id === null) {
                return;
            }

            $deployment->number = DB::transaction(function () use ($deployment): int {
                Application::withTrashed()->whereKey($deployment->application_id)->toBase()->increment('deploy_count');

                return (int) Application::withTrashed()->whereKey($deployment->application_id)->value('deploy_count');
            });
        });
    }
}
