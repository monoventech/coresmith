<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrialHistory
{
    public static function normalise(string $email): string
    {
        $email = Str::lower(trim($email));

        if (!str_contains($email, '@')) {
            return $email;
        }

        return Str::before(Str::beforeLast($email, '@'), '+') . '@' . Str::afterLast($email, '@');
    }

    public function used(string $email): bool
    {
        return DB::table('trial_history')->where('email', self::normalise($email))->exists();
    }

    public function claim(string $email, Team $team): bool
    {
        return DB::table('trial_history')->insertOrIgnore([
            'email' => self::normalise($email),
            'team_id' => $team->getKey(),
            'started_at' => now(),
        ]) === 1;
    }
}
