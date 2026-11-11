<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Jobs\DeployApplication;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\DeployGuard;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplicationApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $applications = app(TeamAccess::class)->applications($request->user(), $this->team($request))->orderBy('name')->paginate(
            perPage: min((int) $request->integer('per_page', 25), 100),
        );

        return response()->json([
            'data' => $applications->getCollection()->map($this->present(...))->values(),
            'meta' => [
                'page' => $applications->currentPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    public function show(Request $request, Application $application): JsonResponse
    {
        $this->seenApplication($request, $application);

        return response()->json(['data' => $this->present($application)]);
    }

    public function deploy(Request $request, Application $application): JsonResponse
    {
        $this->seenApplication($request, $application);

        $refusal = app(DeployGuard::class)->refusal($application);

        if ($refusal !== null) {
            return response()->json(['message' => $refusal], 409);
        }

        $deployment = Deployment::create([
            'application_id' => $application->id,
            'triggered_by' => $request->user()->id,
        ]);

        DeployApplication::dispatch($deployment, now()->format('Ymd-His'));

        $deployment->refresh();

        return response()->json([
            'data' => [
                'id' => $deployment->id,
                'status' => $deployment->status->value,
                'application_id' => $application->id,
            ],
        ], 202);
    }

    public function maintenance(Request $request, Application $application): JsonResponse
    {
        $this->seenApplication($request, $application);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        $toggles = (array) $application->toggles;
        $toggles['maintenance_mode'] = $validated['enabled'];

        $application->forceFill(['toggles' => $toggles])->save();

        SyncApplicationRoute::dispatch($application);

        return response()->json(['data' => $this->present($application->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Application $application): array
    {
        return [
            'id' => $application->id,
            'name' => $application->name,
            'slug' => $application->slug,
            'server_id' => $application->server_id,
            'type' => $application->type?->value,
            'framework' => $application->framework,
            'domain' => $application->domain,
            'additional_domains' => $application->allDomains(),
            'repository_url' => $application->repository_url,
            'repository_branch' => $application->repository_branch,
            'status' => $application->status?->value,
            'power_state' => $application->power_state?->value,
            'maintenance_mode' => (bool) (((array) $application->toggles)['maintenance_mode'] ?? false),
            'created_at' => $application->created_at?->toIso8601String(),
        ];
    }
}
