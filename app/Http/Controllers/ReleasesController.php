<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\ReleasePaths;
use App\Services\Panel\Formats;
use App\Services\Panel\RollbackHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReleasesController extends Controller
{
    public function latest(Request $request): RedirectResponse|Response
    {
        $team = $this->currentTeam();

        $deployment = $team === null ? null : Deployment::query()
            ->whereIn('application_id', $team->applications()->select('id'))
            ->where('status', DeploymentStatus::Succeeded->value)
            ->whereNotNull('release')
            ->with('application')
            ->latest('finished_at')
            ->first();

        if ($deployment?->application !== null) {
            return to_route('servers.applications.releases.index', [$deployment->application->server_id, $deployment->application]);
        }

        return Inertia::render('releases/Index', [
            'application' => null,
            'applications' => [],
            'releases' => [],
            'can' => ['deploy' => false],
        ]);
    }

    public function index(Request $request, Server $server, Application $application, RollbackHistory $history): Response
    {
        $this->authorize('view', $application);
        abort_unless($application->server_id === $server->id, 404);

        $team = $application->team;
        $deployments = $application->deployments()
            ->where(fn(Builder $query) => $query
                ->where('status', DeploymentStatus::Succeeded->value)
                ->when($application->current_release, fn(Builder $query, string $live) => $query->orWhere(fn(Builder $query) => $query
                    ->where('status', DeploymentStatus::Failed->value)
                    ->where('release', $live))))
            ->whereNotNull('release')
            ->with('triggeredBy')
            ->latest('finished_at')
            ->limit(20)
            ->get();

        $onDisk = $deployments->take(ReleasePaths::KEEP_RELEASES)->pluck('release')->push($application->current_release)->filter()->all();
        $current = $application->current_release ?? $deployments->first()?->release;
        $rolledBack = $history->rolledBack($application);

        return Inertia::render('releases/Index', [
            'application' => [
                'id' => $application->id,
                'name' => $application->name,
                'server' => $server->name,
                'href' => route('servers.applications.show', [$server, $application]),
                'deploy_href' => route('servers.applications.deploy', [$server, $application]),
                'rollback_href' => route('servers.applications.rollback', [$server, $application]),
                'rolled_back' => $rolledBack ? Formats::release($current) : null,
            ],
            'applications' => $team?->applications()->orderBy('name')->get()->map(fn(Application $each): array => [
                'id' => $each->id,
                'name' => $each->name,
                'href' => route('servers.applications.releases.index', [$each->server_id, $each]),
                'current' => $each->id === $application->id,
            ])->values() ?? [],
            'releases' => $deployments->map(fn(Deployment $deployment): array => [
                'id' => $deployment->id,
                'release' => $deployment->release,
                'directory' => 'releases/' . Formats::release($deployment->release),
                'commit' => $deployment->commit_hash === null ? null : substr($deployment->commit_hash, 0, 7),
                'author' => $deployment->commit_author ?? $deployment->triggeredBy?->name,
                'tag' => $deployment->tag,
                'signed' => $deployment->tag_signed,
                'branch' => $deployment->branch ?? $application->repository_branch,
                'when' => Formats::when($deployment->finished_at),
                'full' => Formats::absolute($deployment->finished_at),
                'current' => $deployment->release === $current,
                'on_disk' => in_array($deployment->release, $onDisk, true),
            ])->values(),
            'rollback' => $request->query('rollback'),
            'can' => ['deploy' => $request->user()->can('deploy', $application)],
        ]);
    }
}
