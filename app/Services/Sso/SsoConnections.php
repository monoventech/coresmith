<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Services\Dns\DnsResolver;
use App\Services\SafeName;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamUrls;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SsoConnections
{
    public const RECORD_PREFIX = '_coresmith-sso.';

    public const NO_RECORD = 'no_record';

    public const WRONG_VALUE = 'wrong_value';

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly TeamUrls $urls,
        private readonly UrlGuard $guard,
        private readonly TeamContext $context,
        private readonly PendingSsoLink $links,
    ) {}

    public function forTeam(Team $team): ?TeamSsoConnection
    {
        return TeamSsoConnection::query()->where('team_id', $team->getKey())->first();
    }

    public function normaliseIssuer(string $issuer): string
    {
        $issuer = trim($issuer);

        if (preg_match('#^([a-z][a-z0-9+.-]*://)([^/?\#]*)(.*)$#i', $issuer, $matches) !== 1) {
            return $issuer;
        }

        return strtolower($matches[1] . $matches[2]) . $matches[3];
    }

    public function normaliseDomain(string $domain): string
    {
        return rtrim(ltrim(strtolower(trim($domain)), '@'), '.');
    }

    public function issuerRefusal(string $issuer): ?string
    {
        return $this->guard->issuerRefusal($issuer);
    }

    public function domainRefusal(string $domain, Team $team): ?string
    {
        if (!SafeName::isDomain($domain) || str_starts_with($domain, '*.')) {
            return "That doesn't look like an email domain. Try example.com.";
        }

        if ($this->claimedElsewhere($domain, $team)) {
            return 'Another team already signs in with that email domain.';
        }

        return null;
    }

    public function claimedElsewhere(string $domain, Team $team, ?CarbonInterface $verifiedBefore = null): bool
    {
        return TeamSsoConnection::query()
            ->where('email_domain', $domain)
            ->where('team_id', '!=', $team->getKey())
            ->whereNotNull('domain_verified_at')
            ->when($verifiedBefore !== null, fn(Builder $query) => $query->where('domain_verified_at', '<', $verifiedBefore))
            ->whereHas('team')
            ->exists();
    }

    public function newToken(): string
    {
        return Str::lower(Str::random(32));
    }

    public function verify(TeamSsoConnection $connection): ?string
    {
        $records = array_map(fn(string $record): string => trim($record, " \t\n\r\0\x0B\""), $this->dns->txt($this->recordName($connection)));

        if (!in_array($this->recordValue($connection), $records, true)) {
            return $records === [] ? self::NO_RECORD : self::WRONG_VALUE;
        }

        $connection->forceFill(['domain_verified_at' => $connection->domain_verified_at ?? now()])->save();

        return null;
    }

    public function recordName(TeamSsoConnection $connection): string
    {
        return self::RECORD_PREFIX . $connection->email_domain;
    }

    public function recordValue(TeamSsoConnection $connection): string
    {
        return 'coresmith-sso=' . $connection->domain_token;
    }

    /**
     * @return list<string>
     */
    public function redirectUris(Team $team): array
    {
        $uris = [$this->urls->canonical(SignIn::CALLBACK_PATH)];
        $domain = $team->domain()->first();

        if ($domain instanceof TeamDomain && $domain->isVerified()) {
            $uris[] = 'https://' . $domain->hostname . SignIn::CALLBACK_PATH;
        }

        $subdomain = $this->urls->subdomainUrl($team);

        if ($subdomain !== null) {
            $uris[] = $subdomain . SignIn::CALLBACK_PATH;
        }

        return array_values(array_unique($uris));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Team $team, User $user): array
    {
        $connection = $this->forTeam($team);

        return [
            'connection' => $connection === null ? null : [
                'issuer' => $connection->issuer,
                'client_id' => $connection->client_id,
                'email_domain' => $connection->email_domain,
                'verified' => $connection->isVerified(),
                'enabled' => $connection->enabled,
                'jit_provisioning' => $connection->jit_provisioning,
                'record' => ['type' => 'TXT', 'name' => $this->recordName($connection), 'value' => $this->recordValue($connection)],
            ],
            'redirect_uris' => $this->redirectUris($team),
            'can_manage' => $user->roleIn($team) === TeamRole::Owner,
        ];
    }

    /**
     * @return array{team: string|null, start: string|null, lookup: bool, link: string|null}
     */
    public function loginOptions(Request $request): array
    {
        $team = $this->context->explicit() ? $this->context->team() : null;
        $connection = $team === null ? null : $this->forTeam($team);
        $link = $this->links->email($request);

        if ($team !== null && $connection !== null && $connection->isUsable()) {
            return ['team' => $team->name, 'start' => $this->startUrl($request, $team), 'lookup' => false, 'link' => $link];
        }

        return ['team' => null, 'start' => null, 'lookup' => $this->usable()->exists(), 'link' => $link];
    }

    public function findForEmail(string $email): ?TeamSsoConnection
    {
        $email = Str::lower(trim($email));

        if (!str_contains($email, '@')) {
            return null;
        }

        return $this->usable()
            ->where('email_domain', Str::afterLast($email, '@'))
            ->orderBy('domain_verified_at')
            ->with('team')
            ->first();
    }

    public function startUrl(Request $request, Team $team): string
    {
        $root = $this->context->viaHost() && $this->context->team()?->getKey() !== $team->getKey()
            ? rtrim((string) config('app.url'), '/')
            : $request->getSchemeAndHttpHost();

        return $root . '/sso/' . $team->slug . '/start';
    }

    /**
     * @return Builder<TeamSsoConnection>
     */
    private function usable(): Builder
    {
        return TeamSsoConnection::query()->where('enabled', true)->whereNotNull('domain_verified_at')->whereHas('team');
    }
}
