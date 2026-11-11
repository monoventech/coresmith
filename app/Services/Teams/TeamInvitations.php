<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\Edition;
use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Billing\PlanGate;
use App\Support\Mailer;
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
    public function invite(Team $team, string $email, TeamRole $role, User $inviter, bool $seesEverything = true): array
    {
        $token = Str::random(TeamInvitation::TOKEN_LENGTH);

        $invitation = TeamInvitation::query()->updateOrCreate(
            ['team_id' => $team->getKey(), 'email' => Str::lower(trim($email))],
            [
                'role' => $role,
                'sees_everything' => $seesEverything,
                'token_hash' => TeamInvitation::hash($token),
                'link_offered' => $this->offersLinks(),
                'invited_by' => $inviter->getKey(),
                'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS),
                'accepted_at' => null,
            ],
        );

        activity()->performedOn($team)->causedBy($inviter)->withProperties(['email' => $invitation->email, 'role' => $role->value])->log('member_invited');

        if (!$invitation->link_offered) {
            Notification::route('mail', $invitation->email)->notify(new TeamInvitationNotification((string) $team->name, (string) $inviter->name, $this->link($token)));
        }

        return [$invitation, $token];
    }

    public function offersLinks(): bool
    {
        return Edition::current()->isSelfHosted() && !Mailer::delivers();
    }

    public function link(string $token): string
    {
        return $this->urls->canonical(route('invitations.show', $token, false));
    }

    public function issuedLink(TeamInvitation $invitation, mixed $issued): ?string
    {
        if (!$invitation->link_offered || !is_array($issued) || ($issued['id'] ?? null) !== $invitation->getKey() || !is_string($issued['token'] ?? null)) {
            return null;
        }

        return hash_equals((string) $invitation->token_hash, TeamInvitation::hash($issued['token'])) ? $this->link($issued['token']) : null;
    }

    public function join(TeamInvitation $invitation, User $user): bool
    {
        $team = $invitation->team;

        if (!$team instanceof Team || !$invitation->isFor($user)) {
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

                $team->members()->attach($user, ['role' => $pending->role->value, 'sees_everything' => $pending->sees_everything]);
                $user->unsetRelation('teams');

                activity()->performedOn($team)->causedBy($user)->withProperties(['email' => $user->email, 'role' => $pending->role->value])->log('member_added');
            }

            $pending->forceFill(['accepted_at' => now()])->save();
            $user->forceFill(['current_team_id' => $team->getKey()]);

            if (!$pending->link_offered && !$user->hasVerifiedEmail()) {
                $user->forceFill(['email_verified_at' => now()]);
            }

            $user->save();

            return true;
        });
    }

    public function syncKeys(TeamInvitation $invitation): void
    {
        rescue(fn() => SyncAuthorizedKeys::dispatchForServers(SyncAuthorizedKeys::activeServersOf([$invitation->team_id])));
    }
}
