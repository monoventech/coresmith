<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\DeploymentStatus;
use App\Enums\MonitorKind;
use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\MonitorAlert;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\AppTags;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

final class ProjectPresenter
{
    public const DEPLOYS = 10;

    public function __construct(private readonly TeamAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function card(Project $project, User $viewer): array
    {
        $applications = $this->applications($project, $viewer);
        $databases = $this->databases($project, $viewer)->count();
        $latest = Deployment::query()->whereIn('application_id', $applications->modelKeys())->with('application')->latest('created_at')->first();

        return [
            'id' => $project->getKey(),
            'name' => $project->name,
            'href' => route('projects.show', $project),
            'meta' => self::counts($applications->count(), $databases),
            'apps' => $applications->map(fn(Application $application): array => [
                'id' => $application->getKey(),
                'name' => $application->name,
                'tags' => $application->tags,
                'state' => ApplicationPresenter::state($application),
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
        $team = $project->team;
        $applications = $this->applications($project, $viewer)->load('server');
        $databases = $this->databases($project, $viewer)->load('server');
        $last = $this->lastDeploys($applications);
        $servers = Server::query()->whereIn('id', [...$applications->pluck('server_id'), ...$databases->pluck('server_id')])->orderBy('name')->get();
        $fleet = new FleetSnapshot($team, $viewer);
        $fill = Gate::forUser($viewer)->allows('fill', $project);
        $developer = $viewer->roleIn($team) === TeamRole::Developer;
        $attention = $this->attention($applications, $databases, $servers, $last, $fleet, $viewer);
        $shares = Gate::forUser($viewer)->allows('shareAccess', $team);

        return [
            'project' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'meta' => implode(' · ', array_filter([
                    self::counts($applications->count(), $databases->count()),
                    $servers->isEmpty() ? null : 'on ' . $servers->count() . ' ' . ($servers->count() === 1 ? 'server' : 'servers'),
                ])),
                'healthy' => $applications->isNotEmpty() && $attention === [],
            ],
            'attention' => $attention,
            'applications' => $applications->map(fn(Application $application): array => [
                'id' => $application->getKey(),
                'name' => $application->name,
                'tags' => $application->tags,
                'kind' => ApplicationPresenter::kind($application),
                'state' => $last->get($application->getKey())?->status === DeploymentStatus::Running ? ['key' => 'deploying', 'label' => 'Deploying', 'tone' => 'accent'] : ApplicationPresenter::state($application),
                'href' => route('servers.applications.show', [$application->server_id, $application]),
                'server' => $this->where($viewer, $application->server),
                'domain' => $application->domain === null || $application->domain === '' ? null : ['label' => $application->domain, 'href' => 'https://' . $application->domain],
                'last_deploy' => $this->lastDeploy($last->get($application->getKey())),
                'deploy_href' => Gate::forUser($viewer)->allows('deploy', $application) ? route('servers.applications.deploy', [$application->server_id, $application]) : null,
                'tags_href' => Gate::forUser($viewer)->allows('update', $application) ? route('servers.applications.tags.update', [$application->server_id, $application]) : null,
                'remove_href' => $fill && (!$developer || $application->addedBy($viewer)) ? route('projects.applications.destroy', [$project, $application]) : null,
            ])->values()->all(),
            'databases' => $databases->map(fn(ManagedDatabase $database): array => [
                'id' => $database->getKey(),
                'name' => $database->name,
                'type' => $database->type->value,
                'href' => route('servers.databases.show', [$database->server_id, $database]),
                'server' => $this->where($viewer, $database->server),
                'offsite' => $database->last_offsite_error !== null
                    ? ['label' => 'Offsite backup failing', 'tone' => 'destructive']
                    : ($database->last_offsite_at === null ? null : ['label' => 'Backed up offsite ' . Formats::when($database->last_offsite_at), 'tone' => 'success']),
                'remove_href' => $fill && (!$developer || $database->addedBy($viewer)) ? route('projects.databases.destroy', [$project, $database]) : null,
            ])->values()->all(),
            'deploys' => Deployment::query()
                ->whereIn('application_id', $applications->modelKeys())
                ->with('application.server')
                ->latest('created_at')
                ->limit(self::DEPLOYS)
                ->get()
                ->map(fn(Deployment $deployment): array => DeploymentPresenter::row($deployment))
                ->all(),
            'deploys_href' => route('deployments.index', ['project' => $project->getKey()]),
            'servers' => $servers->map(fn(Server $server): array => $this->server($fleet, $viewer, $server))->values()->all(),
            'people' => $shares ? $this->people($project) : null,
            'access_href' => $shares ? route('teams.index') : null,
            'add' => $fill && $viewer->can('create', Application::class) ? $this->addTargets($project, $viewer) : [],
            'can' => [
                'manage' => Gate::forUser($viewer)->allows('manage', $project),
                'fill' => $fill,
            ],
            'shared_part' => !$this->access->seesWholeProject($viewer, $project),
            'adds_stay_private' => $developer,
            'candidates' => $fill ? $this->candidates($project, $viewer) : ['applications' => [], 'databases' => []],
            'tag_suggestions' => AppTags::suggestions($viewer, $team),
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function choices(User $viewer, ?Team $team): array
    {
        if (!$team instanceof Team) {
            return [];
        }

        return $this->access->projects($viewer, $team)->orderBy('name')->get()
            ->filter(fn(Project $project): bool => Gate::forUser($viewer)->allows('fill', $project))
            ->map(fn(Project $project): array => ['id' => $project->getKey(), 'name' => $project->name])
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, href: string|null}|null
     */
    public function place(User $viewer, Application|ManagedDatabase $subject): ?array
    {
        $project = $subject->project;

        if (!$project instanceof Project) {
            return null;
        }

        return [
            'label' => $project->name,
            'href' => $this->access->canSeeProject($viewer, $project) ? route('projects.show', $project) : null,
        ];
    }

    public static function counts(int $applications, int $databases): string
    {
        return implode(' · ', array_filter([
            $applications . ' ' . ($applications === 1 ? 'app' : 'apps'),
            $databases > 0 ? $databases . ' ' . ($databases === 1 ? 'database' : 'databases') : null,
        ]));
    }

    /**
     * @return Collection<int, Application>
     */
    private function applications(Project $project, User $viewer): Collection
    {
        return $this->access->applications($viewer, $project->team)->where('project_id', $project->getKey())->orderBy('name')->get();
    }

    /**
     * @return Collection<int, ManagedDatabase>
     */
    private function databases(Project $project, User $viewer): Collection
    {
        return $this->access->databases($viewer, $project->team)->where('project_id', $project->getKey())->orderBy('name')->get();
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return Collection<string, Deployment>
     */
    private function lastDeploys(Collection $applications): Collection
    {
        if ($applications->isEmpty()) {
            return new Collection;
        }

        return Deployment::query()
            ->whereIn('id', Deployment::query()->selectRaw('max(id)')->whereIn('application_id', $applications->modelKeys())->groupBy('application_id'))
            ->with('triggeredBy')
            ->get()
            ->keyBy('application_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastDeploy(?Deployment $deployment): ?array
    {
        if (!$deployment instanceof Deployment) {
            return null;
        }

        $subject = trim(strtok((string) $deployment->commit_message, "\n") ?: '');

        return [
            'state' => DeploymentPresenter::state($deployment),
            'when' => Formats::when($deployment->started_at ?? $deployment->created_at),
            'full' => Formats::absolute($deployment->started_at ?? $deployment->created_at),
            'commit' => $subject === '' ? null : $subject,
            'by' => $deployment->triggeredBy?->name ?? ($deployment->commit_author === null || $deployment->commit_author === '' ? null : $deployment->commit_author),
            'href' => $deployment->application === null ? null : route('servers.applications.deployments.show', [$deployment->application->server_id, $deployment->application_id, $deployment]),
        ];
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, ManagedDatabase>  $databases
     * @param  Collection<int, Server>  $servers
     * @param  Collection<string, Deployment>  $last
     * @return list<array{severity: string, subject: string, summary: string, href: string|null}>
     */
    private function attention(Collection $applications, Collection $databases, Collection $servers, Collection $last, FleetSnapshot $fleet, User $viewer): array
    {
        $items = [];
        $down = MonitorAlert::query()->where('kind', MonitorKind::Apps)->whereNotNull('open_key')->whereIn('application_id', $applications->modelKeys())->pluck('application_id')->all();

        foreach ($servers as $server) {
            if (!$this->access->canSeeServer($viewer, $server)) {
                continue;
            }

            $state = $fleet->state($server);

            if (in_array($state['key'], ['host_key', 'failed', 'disconnected', 'unreachable', 'creation_failed', 'destroy_failed'], true)) {
                $items[] = ['severity' => 'critical', 'subject' => $server->name, 'summary' => strtolower($state['label']), 'href' => route('servers.show', $server)];
            }
        }

        foreach ($applications as $application) {
            $deployment = $last->get($application->getKey());
            $page = route('servers.applications.show', [$application->server_id, $application]);

            if (in_array($application->getKey(), $down, true)) {
                $items[] = ['severity' => 'critical', 'subject' => $application->name, 'summary' => "isn't answering", 'href' => $page];
            } elseif ($deployment?->status === DeploymentStatus::Failed) {
                $items[] = ['severity' => 'warning', 'subject' => $application->name, 'summary' => 'last deploy failed', 'href' => route('servers.applications.deployments.show', [$application->server_id, $application, $deployment])];
            } elseif ($application->power_state === PowerState::Stopped) {
                $items[] = ['severity' => 'warning', 'subject' => $application->name, 'summary' => 'stopped', 'href' => $page];
            }
        }

        foreach ($databases as $database) {
            if ($database->last_offsite_error !== null) {
                $items[] = ['severity' => 'warning', 'subject' => $database->name, 'summary' => 'offsite backup failing', 'href' => route('servers.databases.show', [$database->server_id, $database])];
            }
        }

        usort($items, static fn(array $a, array $b): int => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function server(FleetSnapshot $fleet, User $viewer, Server $server): array
    {
        if (!$this->access->canSeeServer($viewer, $server)) {
            return ['id' => $server->getKey(), 'name' => $server->name, 'href' => null, 'state' => null, 'cpu' => null, 'memory' => null, 'disk' => null];
        }

        $metric = $fleet->latestMetric($server);

        return [
            'id' => $server->getKey(),
            'name' => $server->name,
            'href' => route('servers.show', $server),
            'state' => $fleet->state($server),
            'cpu' => $metric === null ? null : (int) round((float) $metric->cpu_percent),
            'memory' => $metric === null ? null : (int) round((float) $metric->memory_percent),
            'disk' => $metric === null ? null : (int) round((float) $metric->disk_percent),
        ];
    }

    /**
     * @return list<array{name: string, role: string, reach: string}>
     */
    private function people(Project $project): array
    {
        $team = $project->team;

        return $team->members()->orderBy('name')->get()
            ->filter(fn(User $member): bool => $this->access->canSeeProject($member, $project))
            ->map(fn(User $member): array => [
                'name' => $member->name,
                'role' => (string) TeamRole::tryFrom((string) $member->pivot->role)?->label(),
                'reach' => match (true) {
                    $this->access->seesEverything($member, $team) => 'Sees everything',
                    $project->addedBy($member) => 'Created it',
                    $this->access->seesWholeProject($member, $project) => 'Shared with them',
                    default => 'Shared in part',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, href: string}>
     */
    private function addTargets(Project $project, User $viewer): array
    {
        return $this->access->servers($viewer, $project->team)->orderBy('name')->get()
            ->map(fn(Server $server): array => ['name' => $server->name, 'href' => route('servers.applications.create', [$server, 'project' => $project->getKey()])])
            ->values()
            ->all();
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
     * @return array{applications: list<array{id: string, name: string, where: string}>, databases: list<array{id: string, name: string, where: string}>}
     */
    private function candidates(Project $project, User $viewer): array
    {
        $place = fn(Application|ManagedDatabase $each): array => [
            'id' => $each->getKey(),
            'name' => $each->name,
            'where' => $each->project?->name ?? 'No project',
        ];

        $own = $viewer->roleIn($project->team) === TeamRole::Developer ? $viewer->getKey() : null;
        $outside = fn($query) => $query->where(fn($elsewhere) => $elsewhere->whereNull('project_id')->orWhere('project_id', '!=', $project->getKey()));

        return [
            'applications' => $this->access->applications($viewer, $project->team)->where($outside)->when($own !== null, fn($query) => $query->where('created_by', $own))->with('project')->orderBy('name')->get()->map($place)->values()->all(),
            'databases' => $this->access->databases($viewer, $project->team)->where($outside)->when($own !== null, fn($query) => $query->where('created_by', $own))->with('project')->orderBy('name')->get()->map($place)->values()->all(),
        ];
    }
}
