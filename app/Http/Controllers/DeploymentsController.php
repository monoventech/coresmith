<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\Panel\DeploymentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DeploymentsController extends Controller
{
    public const LIMIT = 100;

    public const FILTERS = [
        'all' => null,
        'running' => DeploymentStatus::Running,
        'deployed' => DeploymentStatus::Succeeded,
        'failed' => DeploymentStatus::Failed,
    ];

    public function index(Request $request): Response
    {
        $team = $this->currentTeam();
        $filter = array_key_exists((string) $request->query('status'), self::FILTERS) ? (string) $request->query('status') : 'all';

        $base = fn(): Builder => Deployment::query()
            ->whereIn('application_id', $team === null ? [] : $team->applications()->select('id'));

        $status = self::FILTERS[$filter];

        $deployments = $base()
            ->when($status !== null, fn(Builder $query) => $query->where('status', $status->value))
            ->with('application.server')
            ->orderByRaw('case when status = ? then 0 else 1 end', [DeploymentStatus::Running->value])
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get();

        return Inertia::render('deployments/Index', [
            'filter' => $filter,
            'filters' => collect(self::FILTERS)->map(fn(?DeploymentStatus $each, string $key): array => [
                'key' => $key,
                'label' => ucfirst($key),
                'count' => $base()->when($each !== null, fn(Builder $query) => $query->where('status', $each->value))->count(),
                'href' => route('deployments.index', $key === 'all' ? [] : ['status' => $key]),
            ])->values(),
            'deployments' => $deployments->map(fn(Deployment $deployment): array => DeploymentPresenter::row($deployment))->values(),
        ]);
    }
}
