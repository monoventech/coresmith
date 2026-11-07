<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

class InstanceSetting extends Model
{
    public const CACHE_KEY = 'coresmith:instance-settings';

    public const CACHE_SECONDS = 60;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn() => Cache::forget(self::cacheKey()));
        static::deleted(fn() => Cache::forget(self::cacheKey()));
    }

    public static function cacheKey(): string
    {
        // Environments that share a cache store must not share each other's settings.
        return self::CACHE_KEY . ':' . config('app.env');
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::values()[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public static function put(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * @return array<string, string|null>
     */
    public static function values(): array
    {
        return Cache::remember(self::cacheKey(), self::CACHE_SECONDS, function (): array {
            try {
                return self::query()->pluck('value', 'key')->all();
            } catch (QueryException) {
                return [];
            }
        });
    }
}
