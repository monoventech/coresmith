<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ReadServerLogs;
use App\Models\Server;
use App\Services\Logs\AppLogs;
use App\Services\Logs\LogQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

final class ServerLogsController extends Controller
{
    public const SOURCES = [
        'requests' => 'Requests',
        'apps' => 'Apps',
        'web' => 'Web server',
        'signins' => 'Sign-ins',
        'firewall' => 'Blocked addresses',
        'system' => 'System',
    ];

    public function show(Request $request, Server $server): Response
    {
        $this->authorize('update', $server);

        $query = LogQuery::from($request);

        return Inertia::render('servers/Logs', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'href' => route('servers.show', $server),
                'lines_href' => route('servers.logs.lines', $server),
            ],
            'sources' => self::sources([]),
            'windows' => array_map(fn(string $key, string $label): array => ['key' => $key, 'label' => $label], array_keys(ApplicationLogsController::WINDOWS), ApplicationLogsController::WINDOWS),
            'filters' => ['window' => $query->window, 'q' => $query->search, 'sources' => $query->sources, 'level' => $query->level, 'around' => $query->around],
        ]);
    }

    public function lines(Request $request, Server $server): JsonResponse
    {
        $this->authorize('update', $server);

        $query = LogQuery::from($request);
        $cached = Cache::get(ReadServerLogs::cacheKey($server, $query));

        if (!is_array($cached) || (int) ($cached['read_at'] ?? 0) < now()->subSeconds(ApplicationLogsController::FRESH_SECONDS)->timestamp) {
            ReadServerLogs::dispatch($server, $query);
        }

        if (!is_array($cached)) {
            return response()->json(['pending' => true, 'error' => null, 'entries' => [], 'capped' => false, 'sources' => self::sources([]), 'stats' => null, 'processes' => []], 202);
        }

        $entries = AppLogs::ordered($cached['entries'] ?? []);
        $shown = array_values(array_filter($entries, fn(array $entry): bool => $query->wants($entry['source']) && $query->allows($entry['level'])));

        return response()->json([
            'pending' => false,
            'error' => $cached['error'] ?? null,
            'read_at' => CarbonImmutable::createFromTimestamp((int) ($cached['read_at'] ?? 0))->toIso8601String(),
            'entries' => array_map(ApplicationLogsController::present(...), array_slice($shown, -ApplicationLogsController::SHOWN)),
            'capped' => ($cached['capped'] ?? false) || count($shown) > ApplicationLogsController::SHOWN,
            'sources' => self::sources($entries),
            'stats' => $cached['stats'] ?? null,
            'processes' => [],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{key: string, label: string, count: int}>
     */
    private static function sources(array $entries): array
    {
        $counts = array_count_values(array_map(fn(array $entry): string => (string) $entry['source'], $entries));

        return array_map(fn(string $key, string $label): array => ['key' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0], array_keys(self::SOURCES), self::SOURCES);
    }
}
