<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Environment;
use App\Models\Project;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $projects = app(TeamAccess::class)->projects($request->user(), $this->team($request))->orderBy('name')->paginate(
            perPage: min((int) $request->integer('per_page', 25), 100),
        );

        return response()->json([
            'data' => $projects->getCollection()->map(fn(Project $project): array => $this->present($request, $project))->values(),
            'meta' => [
                'page' => $projects->currentPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->team_id === $this->team($request)->getKey() && app(TeamAccess::class)->canSeeProject($request->user(), $project), 404);

        return response()->json(['data' => $this->present($request, $project)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'environments' => app(TeamAccess::class)->environmentsOf($request->user(), $project)
                ->map(fn(Environment $environment): array => ['id' => $environment->id, 'name' => $environment->name])
                ->values()
                ->all(),
            'created_at' => $project->created_at?->toIso8601String(),
        ];
    }
}
