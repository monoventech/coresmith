<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ReadAccessLog;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class ApplicationLogController extends Controller
{
    public const FRESH_SECONDS = 10;

    public function index(Server $server, Application $application): JsonResponse
    {
        $this->authorize('viewLogs', $application);
        abort_unless($application->server_id === $server->id, 404);

        $cached = Cache::get(ReadAccessLog::cacheKey($application));

        if (!is_array($cached) || (int) ($cached['read_at'] ?? 0) < now()->subSeconds(self::FRESH_SECONDS)->timestamp) {
            ReadAccessLog::dispatch($application);
        }

        if (!is_array($cached)) {
            return response()->json(['lines' => [], 'pending' => true], 202);
        }

        return response()->json([
            'lines' => $cached['lines'] ?? [],
            'error' => $cached['error'] ?? null,
            'pending' => false,
        ]);
    }
}
