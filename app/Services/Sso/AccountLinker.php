<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\TeamRole;
use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\TeamSsoConnection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountLinker
{
    /**
     * @param  array{sub: string, email: string, email_verified: mixed, name: mixed}  $profile
     */
    public function resolve(TeamSsoConnection $connection, Team $team, string $issuer, array $profile): SsoIdentity
    {
        $email = Str::lower(trim($profile['email']));
        $domain = Str::lower($connection->email_domain);

        if ($profile['email_verified'] === false || $profile['email_verified'] === 'false') {
            throw new SsoException('Your identity provider has not verified that email address. Verify it there, then try again.', 'email.unverified', $team);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || Str::afterLast($email, '@') !== $domain) {
            throw new SsoException("That account's email address is not at {$domain}, so it cannot sign in to {$team->name}. Ask your administrator to check the email your identity provider sends.", 'email.domain', $team);
        }

        $identity = SsoIdentity::query()->where('issuer', $issuer)->where('subject', $profile['sub'])->with('user')->first();
        $user = $identity?->user ?? User::findByEmail($email);

        if (!$user instanceof User && !$connection->jit_provisioning) {
            throw new SsoException("There is no Coresmith account for {$email}. Ask an owner of {$team->name} to add you.", 'account.missing', $team);
        }

        if ($user instanceof User && !$user->belongsToTeam($team) && !$connection->jit_provisioning) {
            throw new SsoException("You are not a member of {$team->name} yet. Ask an owner to add you, then try again.", 'account.membership', $team);
        }

        if (!$identity instanceof SsoIdentity && $user instanceof User && !$this->linkedThrough($user, $team)) {
            throw new SsoLinkRequired($team, [
                'team' => (string) $team->getKey(),
                'issuer' => $issuer,
                'connection_issuer' => $connection->issuer,
                'subject' => $profile['sub'],
                'email' => $email,
            ]);
        }

        try {
            return DB::transaction(fn(): SsoIdentity => $this->link($team, $issuer, $profile['sub'], $email, $profile['name'], $user, $identity));
        } catch (UniqueConstraintViolationException) {
            throw new SsoException('Signing in did not finish. Start again from the sign-in page.', 'account.conflict', $team);
        }
    }

    /**
     * @param  array{team: string, issuer: string, connection_issuer: string, subject: string, email: string}  $link
     */
    public function confirm(User $user, array $link): ?SsoIdentity
    {
        $team = Team::query()->find($link['team']);
        $connection = $team === null ? null : TeamSsoConnection::query()->where('team_id', $team->getKey())->first();

        if (!$team instanceof Team || !$connection instanceof TeamSsoConnection || !$connection->isUsable() || $connection->issuer !== $link['connection_issuer']) {
            return null;
        }

        if (Str::afterLast($link['email'], '@') !== Str::lower($connection->email_domain) || (!$user->belongsToTeam($team) && !$connection->jit_provisioning)) {
            return null;
        }

        $identity = SsoIdentity::query()->where('issuer', $link['issuer'])->where('subject', $link['subject'])->first();

        if ($identity instanceof SsoIdentity && $identity->user_id !== $user->getKey()) {
            return null;
        }

        try {
            $identity = DB::transaction(fn(): SsoIdentity => $this->link($team, $link['issuer'], $link['subject'], $link['email'], null, $user, $identity));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        activity()
            ->performedOn($team)
            ->causedBy($user)
            ->withProperties(['issuer' => $identity->issuer, 'email' => $identity->email])
            ->log('sso_linked');

        return $identity;
    }

    private function linkedThrough(User $user, Team $team): bool
    {
        return SsoIdentity::query()->where('user_id', $user->getKey())->where('team_id', $team->getKey())->exists();
    }

    private function link(Team $team, string $issuer, string $subject, string $email, mixed $name, ?User $user, ?SsoIdentity $identity): SsoIdentity
    {
        if (!$user instanceof User) {
            $user = new User;
            $user->forceFill([
                'name' => $this->name($name, $email),
                'email' => $email,
                'password' => Str::password(48),
                'email_verified_at' => now(),
            ])->save();
        }

        if (!$user->belongsToTeam($team)) {
            $team->members()->attach($user->getKey(), ['role' => TeamRole::Member->value]);
            $user->unsetRelation('teams');
        }

        $identity ??= new SsoIdentity;
        $identity->forceFill([
            'user_id' => $user->getKey(),
            'team_id' => $team->getKey(),
            'issuer' => $issuer,
            'subject' => $subject,
            'email' => $email,
        ])->save();

        return $identity->setRelation('user', $user);
    }

    private function name(mixed $name, string $email): string
    {
        $name = is_string($name) ? trim($name) : '';

        return Str::limit($name !== '' ? $name : Str::before($email, '@'), 255, '');
    }
}
