<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IgnoresJsonKeyOrder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class ServerTunnel extends Model
{
    use HasUlids, IgnoresJsonKeyOrder;

    public const OPENING = 'opening';

    public const OPEN = 'open';

    public const FAILED = 'failed';

    public const CLOSING = 'closing';

    public const TARGET_SUFFIX = '.cfargotunnel.com';

    private const THIS_MACHINE_CACHE = 'server-tunnel:this-machine';

    protected $fillable = ['server_id', 'state', 'account_id', 'tunnel_id', 'hosts', 'skipped', 'diverted', 'error', 'opened_at', 'checked_at'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function target(): ?string
    {
        return $this->tunnel_id === null ? null : $this->tunnel_id . self::TARGET_SUFFIX;
    }

    public function carries(string $host): bool
    {
        return $this->state === self::OPEN && in_array(strtolower($host), (array) $this->hosts, true);
    }

    public function skipReason(string $host): ?string
    {
        if ($this->state !== self::OPEN) {
            return null;
        }

        foreach ((array) $this->skipped as $row) {
            if (is_array($row) && ($row['host'] ?? null) === strtolower($host) && is_string($row['reason'] ?? null)) {
                return $row['reason'];
            }
        }

        return null;
    }

    public static function forThisMachine(): ?self
    {
        return self::query()
            ->where('state', self::OPEN)
            ->whereHas('server', fn($query) => $query->where('runs_coresmith', true))
            ->first();
    }

    public static function usingToken(): ?self
    {
        return self::query()->whereHas('server')->with('server')->first();
    }

    public static function thisMachineOpen(): bool
    {
        return Cache::remember(self::THIS_MACHINE_CACHE, 60, fn(): bool => self::forThisMachine() instanceof self);
    }

    protected static function booted(): void
    {
        static::saved(fn() => Cache::forget(self::THIS_MACHINE_CACHE));
        static::deleted(fn() => Cache::forget(self::THIS_MACHINE_CACHE));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hosts' => 'array',
            'skipped' => 'array',
            'diverted' => 'array',
            'opened_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
