<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\Edition;
use App\Jobs\SyncStripeCustomerEmail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class AccountClosing
{
    public function refusal(User $user): ?string
    {
        foreach ($this->teams($user) as $team) {
            if ($this->successor($team, $user) !== null) {
                continue;
            }

            return match (true) {
                Edition::current()->isSelfHosted() => "Make someone else an owner of {$team->name} first. To remove the panel instead, run coresmith:uninstall on its server.",
                $team->members()->whereKeyNot($user->getKey())->exists() => "Make someone else an owner of {$team->name} first, remove the other members, or delete the team.",
                default => "Delete {$team->name} from Settings, General first, then close your account.",
            };
        }

        return null;
    }

    public function handOver(User $user): void
    {
        foreach (Team::query()->where('owner_id', $user->getKey())->get() as $team) {
            $successor = $this->successor($team, $user);

            if ($successor === null) {
                continue;
            }

            $team->forceFill(['owner_id' => $successor->getKey()])->save();

            if (Edition::current()->isCloud() && $team->hasStripeId()) {
                SyncStripeCustomerEmail::dispatch($team)->afterCommit();
            }
        }
    }

    /**
     * @return Collection<int, Team>
     */
    private function teams(User $user): Collection
    {
        return Team::query()
            ->where(fn(Builder $query) => $query
                ->where('owner_id', $user->getKey())
                ->orWhereHas('owners', fn(Builder $owners) => $owners->whereKey($user->getKey())))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    private function successor(Team $team, User $user): ?User
    {
        return $team->owners()
            ->whereKeyNot($user->getKey())
            ->orderBy('team_user.created_at')
            ->orderBy('users.id')
            ->first();
    }
}
