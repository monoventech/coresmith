<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Ability;
use App\Enums\Edition;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamBilling;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Teams\RouteKeys;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

final class ExplorerPresenter
{
    public function __construct(
        private readonly TeamAccess $access,
        private readonly DeploymentPhases $phases,
        private readonly TeamBilling $billing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user, Team $team, ?FleetSnapshot $fleet = null): array
    {
        $fleet ??= new FleetSnapshot($team, $user);
        $applications = $this->access->applications($user, $team)->with('server')->orderBy('name')->get();
        $databases = $this->access->databases($user, $team)->orderBy('name')->get();
        $running = $fleet->running();
        $deploying = $running->pluck('application_id')->all();
        $visible = $this->access->projects($user, $team)->orderBy('name')->get();
        $projects = $visible->map(fn(Project $project): array => $this->project($project, $applications, $databases, $deploying))->values()->all();
        $shown = $visible->modelKeys();

        return [
            'team' => ['name' => $team->name, 'detail' => $this->detail($user, $team)],
            'projects' => $projects,
            'unfiled' => $applications
                ->reject(fn(Application $application): bool => in_array($application->project_id, $shown, true))
                ->map(fn(Application $application): array => $this->application($application))
                ->values()
                ->all(),
            'servers' => $fleet->servers()->map(fn(Server $server): array => $this->server($fleet, $server))->values()->all(),
            'running' => $this->running($running),
            'attention' => $fleet->attention(),
            'can' => [
                'createProject' => $user->hasTeamAbility($team, Ability::ManageProjects),
                'createServer' => $user->hasTeamAbility($team, Ability::CreateServer),
                'createApplication' => $user->hasTeamAbility($team, Ability::CreateApplication),
            ],
        ];
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, ManagedDatabase>  $databases
     * @param  list<string>  $deploying
     * @return array<string, mixed>
     */
    private function project(Project $project, Collection $applications, Collection $databases, array $deploying): array
    {
        $apps = $applications->where('project_id', $project->getKey())->map(fn(Application $application): array => $this->application($application))->values();

        return [
            'id' => $project->getKey(),
            'name' => $project->name,
            'href' => route('projects.show', $project),
            'deploying' => $apps->contains(fn(array $app): bool => in_array($app['id'], $deploying, true)),
            'apps' => $apps->all(),
            'databases' => $databases->where('project_id', $project->getKey())->map(fn(ManagedDatabase $database): array => [
                'id' => $database->getKey(),
                'name' => $database->name,
                'href' => route('servers.databases.show', [app(RouteKeys::class)->server($database->server_id), $database]),
                'engine' => $database->type->value,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function application(Application $application): array
    {
        return [
            'id' => $application->getKey(),
            'name' => $application->name,
            'tags' => $application->tags,
            'href' => route('servers.applications.show', [app(RouteKeys::class)->server($application->server_id), $application]),
            'state' => ApplicationPresenter::state($application),
            'server' => $application->server?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function server(FleetSnapshot $fleet, Server $server): array
    {
        $state = $fleet->state($server);
        $apps = $server->applications->count();

        return [
            'id' => $server->getKey(),
            'name' => $server->name,
            'href' => route('servers.show', $server),
            'state' => $state,
            'note' => $state['key'] === 'healthy' ? $apps . ' ' . ($apps === 1 ? 'app' : 'apps') : strtolower($state['label']),
            'tags' => $server->tags,
        ];
    }

    /**
     * @param  BaseCollection<int, Deployment>  $running
     * @return array<string, mixed>|null
     */
    private function running(BaseCollection $running): ?array
    {
        $deployment = $running->first();
        $application = $deployment?->application;

        if (!$deployment instanceof Deployment || !$application instanceof Application) {
            return null;
        }

        return [
            'application' => $application->name,
            'server' => $application->server?->name,
            'href' => route('servers.applications.deployments.show', [app(RouteKeys::class)->server($application->server_id), $application, $deployment]),
            'phase' => $this->phases->progress($deployment) ?? 'starting',
            'states' => array_column($this->phases->states($deployment), 'state'),
            'started_at' => $deployment->started_at?->toISOString(),
            'more' => $running->count() - 1,
        ];
    }

    private function detail(User $user, Team $team): string
    {
        $people = $team->members()->count();
        $plan = Edition::current()->isCloud() ? $this->billing->status($team)->plan?->label() : null;
        $standing = $plan ?? $user->roleIn($team)?->label();

        return implode(' · ', array_filter([$standing, $people . ' ' . ($people === 1 ? 'person' : 'people')]));
    }
}
