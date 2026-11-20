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

class ServerFrontDoor extends Model
{
    use HasUlids, IgnoresJsonKeyOrder;

    public const OPENING = 'opening';

    public const OPEN = 'open';

    public const FAILED = 'failed';

    public const CLOSING = 'closing';

    public const PORT = 51871;

    protected $fillable = ['server_id', 'door_server_id', 'state', 'subnet', 'door_address', 'home_address', 'door_public_key', 'home_public_key', 'hosts', 'skipped', 'failing', 'error', 'opened_at', 'checked_at'];

    public function home(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function door(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'door_server_id');
    }

    public function carries(string $host): bool
    {
        return $this->state === self::OPEN && in_array(strtolower($host), (array) $this->hosts, true);
    }

    public static function refusal(Server $door): ?string
    {
        $home = self::query()->where('door_server_id', $door->getKey())->with('home')->first()?->home;

        return $home === null ? null : "{$home->name} takes visitors through {$door->name}. Turn that off on {$home->name}'s page first.";
    }

    public static function forThisMachine(): ?self
    {
        return self::query()
            ->where('state', self::OPEN)
            ->whereHas('home', fn($query) => $query->where('runs_coresmith', true))
            ->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hosts' => 'array',
            'skipped' => 'array',
            'failing' => 'array',
            'opened_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
