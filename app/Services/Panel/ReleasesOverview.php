<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\ProjectScope;
use App\Services\Teams\RouteKeys;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Collection;

final readonly class ReleasesOverview
{
    public const PER_PAGE = 50;

    public function __construct(private TeamAccess $access) {}

    /**
     * @return array{ids: list<string>, total: int}
     */
    public function listing(User $user, ?Team $team, ProjectScope $scope, string $search): array
    {
        if ($team === null) {
            return ['ids' => [], 'total' => 0];
        }

        $applications = $scope->apply($this->access->applications($user, $team))->get(['applications.id', 'applications.name', 'applications.tags', 'applications.server_id', 'applications.project_id']);
        $servers = Server::query()->whereIn('id', $applications->pluck('server_id')->filter()->unique()->values())->pluck('name', 'id');
        $projects = Project::query()->whereIn('id', $applications->pluck('project_id')->filter()->unique()->values())->pluck('name', 'id');
        $project = fn(Application $application): ?string => $projects->get((string) $application->project_id);
        $needle = mb_strtolower($search);

        $ids = $applications
            ->when($needle !== '', fn(Collection $all): Collection => $all->filter(fn(Application $application): bool => collect([$application->name, $servers->get((string) $application->server_id), ...$application->tags, $project($application)])
                ->contains(fn(?string $field): bool => $field !== null && str_contains(mb_strtolower($field), $needle))))
            ->sortBy([
                fn(Application $a, Application $b): int => ($project($a) === null) <=> ($project($b) === null),
                fn(Application $a, Application $b): int => strcasecmp((string) $project($a), (string) $project($b)),
                fn(Application $a, Application $b): int => strcmp((string) $a->project_id, (string) $b->project_id),
                fn(Application $a, Application $b): int => strcasecmp($a->name, $b->name),
                fn(Application $a, Application $b): int => strcasecmp(implode(' ', $a->tags), implode(' ', $b->tags)),
                fn(Application $a, Application $b): int => strcmp((string) $a->getKey(), (string) $b->getKey()),
            ])
            ->map(fn(Application $application): string => (string) $application->getKey())
            ->values()
            ->all();

        return ['ids' => $ids, 'total' => $applications->count()];
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{name: string|null, apps: list<array<string, mixed>>}>
     */
    public function groups(array $ids, ProjectScope $scope): array
    {
        if ($ids === []) {
            return [];
        }

        $order = array_flip($ids);
        $applications = Application::query()
            ->whereIn('id', $ids)
            ->with(['server', 'project'])
            ->get()
            ->sortBy(fn(Application $application): int => $order[(string) $application->getKey()])
            ->values();
        $live = $this->liveDeployments($applications);
        $groups = [];

        foreach ($applications as $application) {
            $key = (string) $application->project_id;

            if ($groups === [] || $groups[array_key_last($groups)]['key'] !== $key) {
                $groups[] = ['key' => $key, 'name' => $application->project?->name, 'apps' => []];
            }

            $groups[array_key_last($groups)]['apps'][] = $this->row($application, $live->get($application->getKey()), $scope->query());
        }

        return array_map(fn(array $group): array => ['name' => $group['name'], 'apps' => $group['apps']], $groups);
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
            'tags' => $application->tags,
            'href' => route('servers.applications.releases.index', [app(RouteKeys::class)->server($application->server_id), $application, ...$query]),
            'release' => $application->current_release === null ? null : 'releases/' . Formats::release($application->current_release),
            'tag' => $live?->tag,
            'when' => Formats::when($live?->finished_at),
            'full' => Formats::absolute($live?->finished_at),
        ];
    }
}
