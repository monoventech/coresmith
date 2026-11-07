<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

final class CloudBilling
{
    public static function enable(): void
    {
        config(['coresmith.edition' => 'cloud']);
    }

    public static function owner(Team $team): User
    {
        $owner = User::query()->findOrFail($team->owner_id);

        if (!$team->members()->whereKey($owner->getKey())->exists()) {
            $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        }

        $owner->forceFill(['current_team_id' => $team->getKey()])->save();

        return $owner->fresh();
    }

    public static function member(Team $team, TeamRole $role = TeamRole::Member): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => $role->value]);

        return $user->fresh();
    }
}
