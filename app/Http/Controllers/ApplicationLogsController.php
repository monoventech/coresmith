<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Jobs\ReadAppLogs;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Logs\AppLogs;
use App\Services\Logs\LogQuery;
use App\Services\Panel\Formats;
use App\Services\Panel\ServerProcesses;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

final class ApplicationLogsController extends Controller
{
    public const FRESH_SECONDS = 2;

    public const SHOWN = AppLogs::KEEP;

    public const DEPLOYS = 50;

    public const WINDOWS = [
        '15m' => 'Last 15 minutes',
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        'all' => 'Everything',
    ];

    public function show(Request $request, Server $server, Application $application, ServerProcesses $processes, AppLogs $logs): Response
    {
        $this->authorize('viewLogs', $application);
        abort_unless($application->server_id === $server->id, 404);

        $query = LogQuery::from($request);

        return Inertia::render('applications/Logs', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'application' => [
                'id' => $application->id,
                'name' => $application->name,
                'href' => route('servers.applications.show', [$server, $application]),
                'lines_href' => route('servers.applications.logs.lines', [$server, $application]),
            ],
            'sources' => $this->sources($application, $logs, []),
            'processes' => $processes->ofApplication($application),
            'windows' => array_map(fn(string $key, string $label): array => ['key' => $key, 'label' => $label], array_keys(self::WINDOWS), self::WINDOWS),
            'filters' => ['window' => $query->window, 'q' => $query->search, 'sources' => $query->sources, 'level' => $query->level, 'around' => $query->around],
        ]);
    }

    public function lines(Request $request, Server $server, Application $application, ServerProcesses $processes, AppLogs $logs): JsonResponse
    {
        $this->authorize('viewLogs', $application);
        abort_unless($application->server_id === $server->id, 404);

        $query = LogQuery::from($request);
        $cached = Cache::get(ReadAppLogs::cacheKey($application, $query));

        if (!is_array($cached) || (int) ($cached['read_at'] ?? 0) < now()->subSeconds(self::FRESH_SECONDS)->timestamp) {
            ReadAppLogs::dispatch($application, $query);
        }

        if (!is_array($cached)) {
            return response()->json([
                'pending' => true,
                'error' => null,
                'entries' => [],
                'capped' => false,
                'sources' => $this->sources($application, $logs, []),
                'stats' => null,
                'processes' => $processes->ofApplication($application),
            ], 202);
        }

        $entries = AppLogs::ordered([...$cached['entries'] ?? [], ...$this->deploys($server, $application, $query)]);
        $shown = array_values(array_filter($entries, fn(array $entry): bool => $query->wants($entry['source']) && $query->allows($entry['level'])));

        return response()->json([
            'pending' => false,
            'error' => $cached['error'] ?? null,
            'read_at' => CarbonImmutable::createFromTimestamp((int) ($cached['read_at'] ?? 0))->toIso8601String(),
            'entries' => array_map(self::present(...), array_slice($shown, -self::SHOWN)),
            'capped' => AppLogs::leftOut($cached, $query) || count($shown) > self::SHOWN,
            'sources' => $this->sources($application, $logs, $entries),
            'stats' => $cached['stats'] ?? null,
            'processes' => $processes->ofApplication($application),
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function present(array $entry): array
    {
        return [
            'id' => $entry['id'],
            'at' => $entry['at'] === null ? null : (int) round($entry['at'] * 1000),
            'source' => $entry['source'],
            'process' => $entry['process'],
            'level' => $entry['level'],
            'text' => $entry['text'],
            'more' => $entry['more'],
            'request' => $entry['request'] === null ? null : array_diff_key($entry['request'], ['at' => true]),
            'href' => $entry['href'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{key: string, label: string, count: int}>
     */
    private function sources(Application $application, AppLogs $logs, array $entries): array
    {
        $labels = ['requests' => 'Requests'];

        foreach ($logs->unitSources($application) as [$source, $process]) {
            $labels[$source] = $process ?? 'PHP errors';
        }

        if ($application->framework === 'laravel') {
            $labels['framework'] = 'Laravel log';
        }

        $labels['scheduler'] = 'Scheduler';
        $labels['deploys'] = 'Deploys';
        $counts = array_count_values(array_map(fn(array $entry): string => (string) $entry['source'], $entries));

        return array_map(fn(string $key, string $label): array => ['key' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0], array_keys($labels), $labels);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deploys(Server $server, Application $application, LogQuery $query): array
    {
        $since = $query->since(now()->getTimestamp());
        $until = $query->until();

        return Deployment::query()
            ->where('application_id', $application->getKey())
            ->when($since !== null, fn($deployments) => $deployments->where('created_at', '>=', CarbonImmutable::createFromTimestamp((int) $since)))
            ->when($until !== null, fn($deployments) => $deployments->where('created_at', '<=', CarbonImmutable::createFromTimestamp((int) $until)))
            ->latest()
            ->limit(self::DEPLOYS)
            ->get()
            ->map(function (Deployment $deployment) use ($server, $application): array {
                $moment = ($deployment->finished_at ?? $deployment->started_at ?? $deployment->created_at)?->getTimestamp() ?? now()->getTimestamp();
                $release = Formats::release($deployment->release);
                $deploy = $release === null ? 'A queued deploy' : "The deploy of release {$release}";

                return [
                    'id' => 'deploy-' . $deployment->getKey(),
                    'at' => (float) $moment,
                    'sort' => (float) $moment,
                    'source' => 'deploys',
                    'process' => null,
                    'level' => match ($deployment->status) {
                        DeploymentStatus::Failed => 'error',
                        DeploymentStatus::Cancelled => 'warning',
                        default => 'info',
                    },
                    'text' => match ($deployment->status) {
                        DeploymentStatus::Succeeded => "Release {$release} went live" . ($deployment->commit_hash === null ? '' : ', ' . substr($deployment->commit_hash, 0, 7) . ($deployment->commit_message === null ? '' : ' ' . $deployment->commit_message)),
                        DeploymentStatus::Failed => "{$deploy} failed",
                        DeploymentStatus::Cancelled => "{$deploy} was cancelled",
                        default => $release === null ? 'A deploy is waiting to start' : "Deploying release {$release}",
                    },
                    'more' => [],
                    'request' => null,
                    'href' => route('servers.applications.deployments.show', [$server, $application, $deployment]),
                ];
            })
            ->filter(fn(array $entry): bool => $query->search === '' || mb_stripos($entry['text'], $query->search) !== false)
            ->values()
            ->all();
    }
}
