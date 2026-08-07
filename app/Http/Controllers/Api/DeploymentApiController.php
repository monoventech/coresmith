<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeploymentApiController extends ApiController
{
    public function index(Request $request, Application $application): JsonResponse
    {
        abort_unless($application->team_id === $this->team($request)->getKey(), 404);

        $deployments = $application->deployments()->latest()->limit(
            min((int) $request->integer('limit', 20), 100),
        )->get();

        return response()->json([
            'data' => $deployments->map($this->present(...))->values(),
        ]);
    }

    public function show(Request $request, Application $application, Deployment $deployment): JsonResponse
    {
        abort_unless($application->team_id === $this->team($request)->getKey(), 404);
        abort_unless($deployment->application_id === $application->id, 404);

        return response()->json([
            'data' => $this->present($deployment) + ['log' => (string) $deployment->log],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'status' => $deployment->status->value,
            'commit_hash' => $deployment->commit_hash,
            'commit_message' => $deployment->commit_message,
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
        ];
    }
}
