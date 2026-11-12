<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Gate;

final class ProjectPresenter
{
    public const DEPLOYS = 3;

    public function __construct(private readonly TeamAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function card(Project $project, User $viewer): array
    {
        $environments = $this->access->environmentsOf($viewer, $project);
        $applications = Application::query()->whereIn('environment_id', $environments->modelKeys())->get(['id', 'environment_id']);
        $latest = Deployment::query()->whereIn('application_id', $applications->modelKeys())->with('application')->latest('created_at')->first();

        return [
            'id' => $project->getKey(),
            'name' => $project->name,
            'href' => route('projects.show', $project),
            'meta' => self::meta($environments->count(), $applications->count(), ManagedDatabase::query()->whereIn('environment_id', $environments->modelKeys())->count()),
            'environments' => $environments->map(fn(Environment $environment): array => [
                'id' => $environment->getKey(),
                'name' => $environment->name,
                'apps' => $applications->where('environment_id', $environment->getKey())->count(),
            ])->values()->all(),
            'latest' => $latest?->application === null ? null : [
                'application' => $latest->application->name,
                'when' => Formats::when($latest->started_at ?? $latest->created_at),
                'href' => route('servers.applications.deployments.show', [$latest->application->server_id, $latest->application, $latest]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function page(Project $project, User $viewer): array
    {
        $environments = $this->access->environmentsOf($viewer, $project);
        $applications = Application::query()->whereIn('environment_id', $environments->modelKeys())->with('server')->orderBy('name')->get();
        $databases = ManagedDatabase::query()->whereIn('environment_id', $environments->modelKeys())->with('server')->orderBy('name')->get();
        $running = Deployment::query()->whereIn('application_id', $applications->modelKeys())->where('status', DeploymentStatus::Running->value)->pluck('application_id')->all();
        $fills = $environments->mapWithKeys(fn(Environment $environment): array => [$environment->getKey() => Gate::forUser($viewer)->allows('fill', $environment)]);

        return [
            'project' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'meta' => self::meta($environments->count(), $applications->count(), $databases->count()),
            ],
            'environments' => $environments->map(function (Environment $environment) use ($project, $viewer, $applications, $databases, $running, $fills): array {
                $here = $applications->where('environment_id', $environment->getKey())->values();
                $stored = $databases->where('environment_id', $environment->getKey())->values();
                $fill = (bool) $fills->get($environment->getKey());

                return [
                    'id' => $environment->getKey(),
                    'name' => $environment->name,
                    'meta' => self::counts($here->count(), $stored->count()),
                    'applications' => $here->map(fn(Application $application): array => [
                        'id' => $application->getKey(),
                        'name' => $application->name,
                        'kind' => ApplicationPresenter::kind($application),
                        'state' => in_array($application->getKey(), $running, true) ? ['key' => 'deploying', 'label' => 'Deploying', 'tone' => 'accent'] : ApplicationPresenter::state($application),
                        'href' => route('servers.applications.show', [$application->server_id, $application]),
                        'server' => $this->where($viewer, $application->server),
                        'remove_href' => $fill ? route('projects.environments.applications.destroy', [$project, $environment, $application]) : null,
                    ])->all(),
                    'databases' => $stored->map(fn(ManagedDatabase $database): array => [
                        'id' => $database->getKey(),
                        'name' => $database->name,
                        'type' => $database->type->value,
                        'href' => route('servers.databases.show', [$database->server_id, $database]),
                        'server' => $this->where($viewer, $database->server),
                        'remove_href' => $fill ? route('projects.environments.databases.destroy', [$project, $environment, $database]) : null,
                    ])->all(),
                    'deploys' => Deployment::query()
                        ->whereIn('application_id', $here->modelKeys())
                        ->with('application.server')
                        ->latest('created_at')
                        ->limit(self::DEPLOYS)
                        ->get()
                        ->map(fn(Deployment $deployment): array => DeploymentPresenter::row($deployment))
                        ->all(),
                    'can' => ['fill' => $fill],
                    'fill_href' => route('projects.environments.contents.store', [$project, $environment]),
                    'rename_href' => route('projects.environments.update', [$project, $environment]),
                    'delete_href' => route('projects.environments.destroy', [$project, $environment]),
                ];
            })->values()->all(),
            'can' => ['manage' => Gate::forUser($viewer)->allows('manage', $project)],
            'candidates' => $fills->contains(true) ? $this->candidates($project, $viewer) : ['applications' => [], 'databases' => []],
        ];
    }

    /**
     * @return list<array{id: string, name: string, environments: list<array{id: string, name: string}>}>
     */
    public function choices(User $viewer, ?Team $team): array
    {
        if (!$team instanceof Team) {
            return [];
        }

        return $this->access->projects($viewer, $team)->orderBy('name')->get()
            ->map(fn(Project $project): array => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'environments' => $this->access->environmentsOf($viewer, $project)
                    ->filter(fn(Environment $environment): bool => Gate::forUser($viewer)->allows('fill', $environment))
                    ->map(fn(Environment $environment): array => ['id' => $environment->getKey(), 'name' => $environment->name])
                    ->values()
                    ->all(),
            ])
            ->filter(fn(array $project): bool => $project['environments'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, href: string|null}|null
     */
    public function place(User $viewer, Application|ManagedDatabase $subject): ?array
    {
        $project = $subject->environment?->project;

        if (!$project instanceof Project) {
            return null;
        }

        return [
            'label' => $project->name . ' › ' . $subject->environment->name,
            'href' => $this->access->canSeeProject($viewer, $project) ? route('projects.show', $project) : null,
        ];
    }

    public static function meta(int $environments, int $applications, int $databases): string
    {
        return implode(' · ', [$environments . ' ' . ($environments === 1 ? 'environment' : 'environments'), self::counts($applications, $databases)]);
    }

    public static function counts(int $applications, int $databases): string
    {
        return implode(' · ', array_filter([
            $applications . ' ' . ($applications === 1 ? 'app' : 'apps'),
            $databases > 0 ? $databases . ' ' . ($databases === 1 ? 'database' : 'databases') : null,
        ]));
    }

    /**
     * @return array{name: string, href: string|null}
     */
    private function where(User $viewer, ?Server $server): array
    {
        return [
            'name' => (string) $server?->name,
            'href' => $server !== null && $this->access->canSeeServer($viewer, $server) ? route('servers.show', $server) : null,
        ];
    }

    /**
     * @return array{applications: list<array{id: string, name: string, environment_id: string|null, where: string}>, databases: list<array{id: string, name: string, environment_id: string|null, where: string}>}
     */
    private function candidates(Project $project, User $viewer): array
    {
        $place = fn(Application|ManagedDatabase $each): array => [
            'id' => $each->getKey(),
            'name' => $each->name,
            'environment_id' => $each->environment_id,
            'where' => $each->environment === null ? 'No project' : $each->environment->project->name . ' › ' . $each->environment->name,
        ];

        return [
            'applications' => $this->access->applications($viewer, $project->team)->with('environment.project')->orderBy('name')->get()->map($place)->values()->all(),
            'databases' => $this->access->databases($viewer, $project->team)->with('environment.project')->orderBy('name')->get()->map($place)->values()->all(),
        ];
    }
}
