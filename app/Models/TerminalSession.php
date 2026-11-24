<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Enums\TerminalEndReason;
use App\Enums\TerminalMode;
use App\Enums\TerminalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TerminalSession extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @param  Builder<TerminalSession>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', [TerminalStatus::Starting->value, TerminalStatus::Open->value]);
    }

    public function live(): bool
    {
        return $this->status !== TerminalStatus::Closed;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => TerminalMode::class,
            'status' => TerminalStatus::class,
            'end_reason' => TerminalEndReason::class,
            'changes' => 'array',
            'started_at' => 'datetime',
            'last_input_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
