<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CloudSignup
{
    public function __construct(private readonly TrialHistory $history) {}

    public function start(User $user): Team
    {
        return DB::transaction(function () use ($user): Team {
            $first = Str::of((string) $user->name)->squish()->before(' ')->toString();
            $team = Team::query()->create(['name' => "{$first}'s team", 'owner_id' => $user->getKey()]);

            $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
            $user->forceFill(['current_team_id' => $team->getKey()])->save();

            if ($this->history->claim((string) $user->email, $team)) {
                $team->forceFill(['trial_ends_at' => now()->addDays((int) config('coresmith.billing.trial_days', 14))])->save();
            }

            return $team;
        });
    }
}
