<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Http\Requests\Application\StoreApplicationRequest;
use App\Http\Requests\Application\UpdateApplicationRequest;
use App\Models\Application;
use App\Models\Server;
use App\Services\Git\DeployKeyManager;
use App\Services\Server\PortAllocator;
use App\Services\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ApplicationController extends Controller
{
    public function index(Server $server): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('applications/Index', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'applications' => $server->applications()
                ->latest()
                ->get()
                ->map(fn (Application $application): array => $this->summarise($application)),
        ]);
    }

    public function create(Server $server): Response
    {
        $this->authorize('view', $server);
        $this->authorize('create', Application::class);

        return Inertia::render('applications/Create', [
            'server' => ['id' => $server->id, 'name' => $server->name],
        ]);
    }

    public function store(
        StoreApplicationRequest $request,
        Server $server,
        SlugGenerator $slugs,
        PortAllocator $ports,
    ): RedirectResponse {
        $slug = $slugs->fromName($request->string('name')->value(), $server);

        $application = Application::create([
            ...$request->safe()->except([]),
            'server_id' => $server->id,
            'team_id' => $server->team_id,
            'slug' => $slug,
            'system_user' => $slugs->systemUser($slug),
            'base_port' => $ports->allocate($server),
            'status' => ApplicationStatus::Pending,
        ]);

        return to_route('servers.applications.show', [$server, $application])
            ->with('success', 'Application created. Deploy it when you are ready.');
    }

    public function show(Server $server, Application $application): Response
    {
        $this->authorize('view', $application);
        $this->assertBelongsTo($server, $application);

        return Inertia::render('applications/Show', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'application' => [
                ...$this->summarise($application),
                'repository_url' => $application->repository_url,
                'repository_branch' => $application->repository_branch,
                'directory' => $application->directory,
                'web_directory' => $application->web_directory,
                'additional_domains' => $application->additional_domains,
                'php_version' => $application->php_version,
                'node_version' => $application->node_version,
                'ruby_version' => $application->ruby_version,
                'power_state' => $application->power_state->value,
                'toggles' => (object) ((array) $application->toggles),
                'uses_deploy_key' => app(DeployKeyManager::class)->usesDeployKey($application),
                'deploy_key_public' => app(DeployKeyManager::class)->usesDeployKey($application)
                    ? app(DeployKeyManager::class)->ensure($application)->deploy_key_public
                    : null,
            ],
            'deployments' => $application->deployments()
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn ($deployment): array => [
                    'id' => $deployment->id,
                    'status' => $deployment->status?->value,
                    'commit_hash' => $deployment->commit_hash,
                    'commit_message' => $deployment->commit_message,
                    'finished_at' => $deployment->finished_at?->toIso8601String(),
                ]),
            'can' => [
                'update' => auth()->user()?->can('update', $application) ?? false,
                'delete' => auth()->user()?->can('delete', $application) ?? false,
                'deploy' => auth()->user()?->can('deploy', $application) ?? false,
                'manageEnvironment' => auth()->user()?->can('manageEnvironment', $application) ?? false,
            ],
        ]);
    }

    public function edit(Server $server, Application $application): Response
    {
        $this->authorize('update', $application);
        $this->assertBelongsTo($server, $application);

        return Inertia::render('applications/Edit', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'application' => $this->summarise($application),
        ]);
    }

    public function update(UpdateApplicationRequest $request, Server $server, Application $application): RedirectResponse
    {
        $this->assertBelongsTo($server, $application);

        $application->update($request->validated());

        return to_route('servers.applications.show', [$server, $application])
            ->with('success', 'Application updated.');
    }

    public function destroy(Server $server, Application $application): RedirectResponse
    {
        $this->authorize('delete', $application);
        $this->assertBelongsTo($server, $application);

        $application->delete();

        return to_route('servers.applications.index', $server)->with('success', 'Application removed.');
    }

    private function assertBelongsTo(Server $server, Application $application): void
    {
        abort_unless($application->server_id === $server->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Application $application): array
    {
        return [
            'id' => $application->id,
            'name' => $application->name,
            'slug' => $application->slug,
            'type' => $application->type?->value,
            'framework' => $application->framework,
            'domain' => $application->domain,
            'status' => $application->status?->value,
            'power_state' => $application->power_state?->value,
            'app_size' => $application->app_size,
            'base_port' => $application->base_port,
            'run_migrations' => (bool) $application->run_migrations,
        ];
    }
}
