<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AppCheckDay extends Model
{
    protected $table = 'app_checks_daily';

    protected $guarded = ['id'];

    public static function count(Application $application, bool $answered): void
    {
        $day = now()->toDateString();
        $now = now();

        self::query()->insertOrIgnore(['application_id' => $application->getKey(), 'day' => $day, 'checks' => 0, 'answered' => 0, 'created_at' => $now, 'updated_at' => $now]);

        self::query()->where('application_id', $application->getKey())->where('day', $day)->update([
            'checks' => DB::raw('checks + 1'),
            ...($answered ? ['answered' => DB::raw('answered + 1')] : ['last_failure_at' => $now]),
            'updated_at' => $now,
        ]);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'checks' => 'integer',
            'answered' => 'integer',
            'last_failure_at' => 'datetime',
        ];
    }
}
