<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\ProjectScope;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Collection;

final readonly class ReleasesOverview
{
    public function __construct(private TeamAccess $access) {}

    /**
     * @return list<array{name: string|null, apps: list<array<string, mixed>>}>
     */
    public function present(User $user, ?Team $team, ProjectScope $scope): array
    {
        if ($team === null) {
            return [];
        }

        $applications = $scope->apply($this->access->applications($user, $team))
            ->with(['server', 'environment.project'])
            ->orderBy('name')
            ->get();
        $live = $this->liveDeployments($applications);

        return $applications
            ->groupBy(fn(Application $application): string => (string) $application->environment?->project?->getKey())
            ->map(fn(Collection $apps): array => [
                'name' => $apps->first()?->environment?->project?->name,
                'apps' => $apps
                    ->sortBy([fn(Application $a, Application $b): int => ($a->environment->position ?? 0) <=> ($b->environment->position ?? 0), fn(Application $a, Application $b): int => strcmp((string) $a->environment_id, (string) $b->environment_id), fn(Application $a, Application $b): int => strcasecmp($a->name, $b->name)])
                    ->map(fn(Application $application): array => $this->row($application, $live->get($application->getKey()), $scope->query()))
                    ->values()
                    ->all(),
            ])
            ->sortBy([fn(array $a, array $b): int => ($a['name'] === null) <=> ($b['name'] === null), fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name'])])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return Collection<string, Deployment>
     */
    private function liveDeployments(Collection $applications): Collection
    {
        $releases = $applications->pluck('current_release')->filter()->unique()->values()->all();

        if ($releases === []) {
            return collect();
        }

        $current = $applications->mapWithKeys(fn(Application $application): array => [$application->getKey() => $application->current_release]);

        return Deployment::query()
            ->whereIn('application_id', $applications->modelKeys())
            ->whereIn('release', $releases)
            ->latest('finished_at')
            ->get()
            ->filter(fn(Deployment $deployment): bool => $deployment->release === $current->get($deployment->application_id))
            ->unique('application_id')
            ->keyBy('application_id');
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function row(Application $application, ?Deployment $live, array $query): array
    {
        return [
            'id' => $application->getKey(),
            'name' => $application->name,
            'server' => $application->server?->name,
            'environment' => $application->environment?->name,
            'href' => route('servers.applications.releases.index', [$application->server_id, $application, ...$query]),
            'release' => $application->current_release === null ? null : 'releases/' . Formats::release($application->current_release),
            'tag' => $live?->tag,
            'when' => Formats::when($live?->finished_at),
            'full' => Formats::absolute($live?->finished_at),
        ];
    }
}
