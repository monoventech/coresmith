<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Enums\PlanFeature;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamSsoConnection;

final class PlanChangeGuard
{
    public function __construct(private readonly TeamBilling $billing) {}

    /**
     * @return list<array{message: string, links: list<array{label: string, href: string}>}>
     */
    public function blockers(Team $team, Plan $plan): array
    {
        return array_values(array_filter([
            $this->servers($team, $plan),
            $this->members($team, $plan),
            $this->domain($team, $plan),
            $this->singleSignOn($team, $plan),
        ]));
    }

    /**
     * @return list<string>
     */
    public function notes(Team $team, Plan $plan): array
    {
        $current = $this->billing->status($team);
        $loses = fn(PlanFeature $feature): bool => $current->allows($feature) && !$plan->includes($feature);

        return array_values(array_filter([
            $loses(PlanFeature::OffsiteBackups) ? 'Database backups to S3 stop.' : null,
            $loses(PlanFeature::AuditLogExport) ? 'Audit log export goes away.' : null,
        ]));
    }

    /**
     * @return array{message: string, links: list<array{label: string, href: string}>}|null
     */
    private function servers(Team $team, Plan $plan): ?array
    {
        $limit = $plan->serverLimit();
        $count = $limit === null ? 0 : $team->servers()->count();

        if ($limit === null || $count <= $limit) {
            return null;
        }

        $links = $team->servers()->orderBy('name')->get()
            ->map(fn(Server $server): array => ['label' => (string) $server->name, 'href' => route('servers.show', $server)])
            ->values()
            ->all();

        return [
            'message' => "{$plan->label()} includes {$limit} " . ($limit === 1 ? 'server' : 'servers') . " and this team has {$count}. Detach " . ($count - $limit) . ' first.',
            'links' => $links,
        ];
    }

    /**
     * @return array{message: string, links: list<array{label: string, href: string}>}|null
     */
    private function members(Team $team, Plan $plan): ?array
    {
        $others = $plan->includes(PlanFeature::TeamRoles) ? 0 : $team->members()->count() - 1;

        if ($others < 1) {
            return null;
        }

        return $this->teamSettings("{$plan->label()} is for one person. Remove the other " . ($others === 1 ? 'member' : "{$others} members") . ' first.');
    }

    /**
     * @return array{message: string, links: list<array{label: string, href: string}>}|null
     */
    private function domain(Team $team, Plan $plan): ?array
    {
        $hostname = $plan->includes(PlanFeature::CustomDomain) ? null : $team->domain()->value('hostname');

        return is_string($hostname) ? $this->teamSettings("{$plan->label()} has no custom domain. Remove {$hostname} first.") : null;
    }

    /**
     * @return array{message: string, links: list<array{label: string, href: string}>}|null
     */
    private function singleSignOn(Team $team, Plan $plan): ?array
    {
        if ($plan->includes(PlanFeature::SingleSignOn) || !TeamSsoConnection::query()->where('team_id', $team->getKey())->exists()) {
            return null;
        }

        return $this->teamSettings("{$plan->label()} has no single sign-on. Remove it first, once everyone can sign in with a password.");
    }

    /**
     * @return array{message: string, links: list<array{label: string, href: string}>}
     */
    private function teamSettings(string $message): array
    {
        return ['message' => $message, 'links' => [['label' => 'Team settings', 'href' => route('teams.index')]]];
    }
}
