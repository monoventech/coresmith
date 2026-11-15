<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorAlert extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public static function keyFor(Server $server, MonitorKind $kind, ?Application $application = null, ?string $job = null): string
    {
        return implode(':', array_filter([$server->getKey(), $kind->value, $application?->getKey(), $job]));
    }

    public static function open(Server $server, MonitorKind $kind, ?Application $application = null, ?string $level = null, ?float $peak = null, ?string $job = null): self
    {
        return self::query()->create([
            'team_id' => $server->team_id,
            'server_id' => $server->getKey(),
            'application_id' => $application?->getKey(),
            'job' => $job,
            'kind' => $kind,
            'level' => $level,
            'peak' => $peak,
            'opened_at' => now(),
            'open_key' => self::keyFor($server, $kind, $application, $job),
        ]);
    }

    public static function current(Server $server, MonitorKind $kind, ?Application $application = null, ?string $job = null): ?self
    {
        return self::query()->where('open_key', self::keyFor($server, $kind, $application, $job))->first();
    }

    public static function closeAllFor(Server|Application $subject): void
    {
        self::query()
            ->where($subject instanceof Server ? 'server_id' : 'application_id', $subject->getKey())
            ->whereNotNull('open_key')
            ->update(['closed_at' => now(), 'open_key' => null, 'updated_at' => now()]);
    }

    public function close(): bool
    {
        $now = now();
        $closed = self::query()->whereKey($this->getKey())->whereNotNull('open_key')->update(['closed_at' => $now, 'open_key' => null, 'updated_at' => $now]) === 1;

        $this->forceFill(['closed_at' => $now, 'open_key' => null])->syncOriginal();

        return $closed;
    }

    public function escalate(string $level): bool
    {
        $changed = self::query()
            ->whereKey($this->getKey())
            ->whereNotNull('open_key')
            ->where(fn($query) => $query->whereNull('level')->orWhere('level', '!=', $level))
            ->update(['level' => $level, 'updated_at' => now()]) === 1;

        if ($changed) {
            $this->forceFill(['level' => $level])->syncOriginal();
        }

        return $changed;
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => MonitorKind::class,
            'peak' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
