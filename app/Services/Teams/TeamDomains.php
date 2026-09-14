<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\Team;
use App\Models\TeamDomain;
use App\Services\Dns\DnsResolver;
use App\Services\SafeName;
use Illuminate\Support\Str;

final class TeamDomains
{
    public const CHALLENGE_PREFIX = '_coresmith-challenge.';

    public const NO_RECORD = 'no_record';

    public const WRONG_VALUE = 'wrong_value';

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly TeamUrls $urls,
    ) {}

    public function normalise(string $hostname): string
    {
        return rtrim(strtolower(trim($hostname)), '.');
    }

    public function refusal(string $hostname, Team $team): ?string
    {
        if ($hostname === $this->urls->mainHost()) {
            return 'That is the address of this panel itself.';
        }

        if (!SafeName::isDomain($hostname) || str_starts_with($hostname, '*.') || !str_contains($hostname, '.')) {
            return "That doesn't look like a domain. Try panel.example.com.";
        }

        $tenant = $this->urls->tenantDomain();

        if ($tenant !== null && ($hostname === $tenant || str_ends_with($hostname, '.' . $tenant))) {
            return 'Addresses under ' . $tenant . ' already come with your team address.';
        }

        if (TeamDomain::query()->where('hostname', $hostname)->where('team_id', '!=', $team->getKey())->exists()) {
            return 'Another team already uses that domain.';
        }

        return null;
    }

    public function attach(Team $team, string $hostname): TeamDomain
    {
        $existing = $team->domain()->first();

        if ($existing instanceof TeamDomain && $existing->hostname === $hostname) {
            return $existing;
        }

        $domain = TeamDomain::query()->updateOrCreate(['team_id' => $team->getKey()], [
            'hostname' => $hostname,
            'verification_token' => Str::lower(Str::random(32)),
            'verified_at' => null,
            'checked_at' => null,
            'failure' => null,
        ]);

        $team->setRelation('domain', $domain);

        return $domain;
    }

    public function verify(TeamDomain $domain): bool
    {
        $records = array_map(trim(...), $this->dns->txt($this->challengeName($domain)));
        $found = in_array($this->challengeValue($domain), $records, true);

        $domain->forceFill([
            'checked_at' => now(),
            'verified_at' => $found ? ($domain->verified_at ?? now()) : null,
            'failure' => $found ? null : ($records === [] ? self::NO_RECORD : self::WRONG_VALUE),
        ])->save();

        return $found;
    }

    public function challengeName(TeamDomain $domain): string
    {
        return self::CHALLENGE_PREFIX . $domain->hostname;
    }

    public function challengeValue(TeamDomain $domain): string
    {
        return 'coresmith-verification=' . $domain->verification_token;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TeamDomain $domain): array
    {
        return [
            'hostname' => $domain->hostname,
            'url' => 'https://' . $domain->hostname,
            'verified' => $domain->isVerified(),
            'checked' => $domain->checked_at?->toIso8601String(),
            'failure' => $domain->failure,
            'records' => [
                ['type' => 'CNAME', 'name' => $domain->hostname, 'value' => $this->urls->mainHost()],
                ['type' => 'TXT', 'name' => $this->challengeName($domain), 'value' => $this->challengeValue($domain)],
            ],
        ];
    }
}
