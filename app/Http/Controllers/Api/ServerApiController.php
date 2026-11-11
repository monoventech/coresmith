<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Server;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ServerApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $servers = app(TeamAccess::class)->servers($request->user(), $this->team($request))->orderBy('name')->paginate(
            perPage: min((int) $request->integer('per_page', 25), 100),
        );

        return response()->json([
            'data' => $servers->getCollection()->map($this->present(...))->values(),
            'meta' => [
                'page' => $servers->currentPage(),
                'per_page' => $servers->perPage(),
                'total' => $servers->total(),
            ],
        ]);
    }

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->seenServer($request, $server);

        return response()->json(['data' => $this->present($server)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Server $server): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'ip_address' => $server->ip_address,
            'status' => $server->status?->value,
            'os' => trim(((string) $server->os_name) . ' ' . ((string) $server->os_version)) ?: null,
            'provisioning_phase' => $server->provisioning_phase,
            'host_key_change_pending' => $server->pending_host_key_fingerprint !== null,
            'last_connected_at' => $server->last_connected_at?->toIso8601String(),
            'created_at' => $server->created_at?->toIso8601String(),
        ];
    }
}
