<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Billing\PlanGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class TeamInvitations
{
    public const ISSUED = 'invitation_issued';

    public function __construct(private readonly TeamUrls $urls, private readonly PlanGate $plans) {}

    /**
     * @return array{TeamInvitation, string}
     */
    public function invite(Team $team, string $email, TeamRole $role, User $inviter): array
    {
        $token = Str::random(TeamInvitation::TOKEN_LENGTH);

        $invitation = TeamInvitation::query()->updateOrCreate(
            ['team_id' => $team->getKey(), 'email' => Str::lower(trim($email))],
            [
                'role' => $role,
                'token_hash' => TeamInvitation::hash($token),
                'invited_by' => $inviter->getKey(),
                'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS),
                'accepted_at' => null,
            ],
        );

        activity()->performedOn($team)->causedBy($inviter)->withProperties(['email' => $invitation->email, 'role' => $role->value])->log('member_invited');

        Notification::route('mail', $invitation->email)->notify(new TeamInvitationNotification((string) $team->name, (string) $inviter->name, $this->link($token)));

        return [$invitation, $token];
    }

    public function link(string $token): string
    {
        return $this->urls->canonical(route('invitations.show', $token, false));
    }

    public function issuedLink(TeamInvitation $invitation, mixed $issued): ?string
    {
        if (!is_array($issued) || ($issued['id'] ?? null) !== $invitation->getKey() || !is_string($issued['token'] ?? null)) {
            return null;
        }

        return hash_equals((string) $invitation->token_hash, TeamInvitation::hash($issued['token'])) ? $this->link($issued['token']) : null;
    }

    public function join(TeamInvitation $invitation, User $user): bool
    {
        $team = $invitation->team;

        if (!$team instanceof Team) {
            return false;
        }

        return DB::transaction(function () use ($invitation, $user, $team): bool {
            $pending = TeamInvitation::query()
                ->whereKey($invitation->getKey())
                ->where('token_hash', $invitation->token_hash)
                ->pending()
                ->lockForUpdate()
                ->first();

            if (!$pending instanceof TeamInvitation) {
                return false;
            }

            if (!$user->belongsToTeam($team)) {
                $this->plans->ensureCanAddMember($team, $user);

                $team->members()->attach($user, ['role' => $pending->role->value]);
                $user->unsetRelation('teams');

                activity()->performedOn($team)->causedBy($user)->withProperties(['email' => $user->email, 'role' => $pending->role->value])->log('member_added');
            }

            $pending->forceFill(['accepted_at' => now()])->save();
            $user->forceFill(['current_team_id' => $team->getKey()])->save();

            DB::afterCommit(fn() => SyncAuthorizedKeys::dispatchForServers(SyncAuthorizedKeys::activeServersOf([$team->getKey()])));

            return true;
        });
    }
}
