<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Services\Server\Audit\ServerAudit;
use Carbon\CarbonImmutable;

final class ServerPresenter
{
    public const PROVISIONING_PHASES = [
        ['name' => 'Operating system', 'desc' => 'detected and checked'],
        ['name' => 'Base system', 'desc' => 'packages and build tools'],
        ['name' => 'Runtimes', 'desc' => 'asdf, shared across apps'],
        ['name' => 'Directories', 'desc' => 'apps, releases and logs, root-owned'],
        ['name' => 'Caddy', 'desc' => 'HTTP routing, 444 catch-all'],
        ['name' => 'Firewall', 'desc' => 'default-deny, SSH and web ports open'],
        ['name' => 'Hardening', 'desc' => 'SSH key-only, fail2ban, sysctls, auto-patching'],
        ['name' => 'Verification', 'desc' => 'admin API reachable from localhost only'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function header(Server $server, User $user): array
    {
        $server->loadMissing(['applications', 'team']);

        $fleet = new FleetSnapshot($server->team);
        $state = $fleet->state($server);
        $metrics = $this->metrics($server);
        $unreachable = $server->unreachable_since !== null && $server->pending_host_key_fingerprint === null;

        return [
            'id' => $server->getKey(),
            'name' => $server->name,
            'environment' => $server->environment,
            'region' => $server->region,
            'state' => $state,
            'meta' => $fleet->meta($server, long: true),
            'active' => $server->status === ServerStatus::Active,
            'metrics' => $metrics,
            'unreachable' => $unreachable ? [
                'last_answer' => Formats::when($server->last_connected_at),
                'reason' => (string) $server->last_connection_error,
            ] : null,
            'disk_warning' => $metrics['disk']['now'] !== null && $metrics['disk']['now'] >= FleetSnapshot::DISK_WARNING
                ? ['percent' => $metrics['disk']['now'], 'apps' => $server->applications->count()]
                : null,
            'host_key' => $server->pending_host_key_fingerprint === null ? null : [
                'expected' => $server->host_key_fingerprint,
                'observed' => $server->pending_host_key_fingerprint,
                'changed_at' => $server->host_key_changed_at?->toIso8601String(),
            ],
            'provisioning' => $server->status === ServerStatus::Active ? null : $this->provisioning($server),
            'tabs' => [
                'apps' => route('servers.show', $server),
                'databases' => route('servers.databases.index', $server),
                'hardening' => route('servers.hardening', $server),
                'activity' => route('servers.activity', $server),
            ],
            'can' => [
                'update' => $user->can('update', $server),
                'delete' => $user->can('delete', $server),
                'manageFirewall' => $user->can('manageFirewall', $server),
                'createApplication' => $user->can('create', Application::class),
            ],
        ];
    }

    /**
     * @return array<string, array{now: int|null, series: list<float>}>
     */
    public function metrics(Server $server): array
    {
        $readings = $server->metrics()
            ->where('recorded_at', '>=', now()->subDay())
            ->orderBy('recorded_at')
            ->get(['cpu_percent', 'memory_percent', 'disk_percent', 'recorded_at']);

        $latest = $readings->last();
        $buckets = $readings->groupBy(fn(ServerMetric $metric): int => (int) floor(now()->diffInMinutes($metric->recorded_at, true) / 60));

        $series = ['cpu' => [], 'memory' => [], 'disk' => []];

        foreach (range(23, 0) as $hour) {
            $bucket = $buckets->get($hour);

            if ($bucket === null) {
                continue;
            }

            $series['cpu'][] = round((float) $bucket->avg('cpu_percent'), 1);
            $series['memory'][] = round((float) $bucket->avg('memory_percent'), 1);
            $series['disk'][] = round((float) $bucket->avg('disk_percent'), 1);
        }

        $fresh = $latest !== null && $latest->recorded_at?->gte(now()->subMinutes(FleetSnapshot::FRESH_METRIC_MINUTES));

        return [
            'cpu' => ['now' => $fresh ? (int) round((float) $latest->cpu_percent) : null, 'series' => $series['cpu']],
            'memory' => ['now' => $fresh ? (int) round((float) $latest->memory_percent) : null, 'series' => $series['memory']],
            'disk' => ['now' => $fresh ? (int) round((float) $latest->disk_percent) : null, 'series' => $series['disk']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function provisioning(Server $server): array
    {
        $completed = (int) ($server->provisioning_phase ?? 0);
        $running = $server->status === ServerStatus::Provisioning;
        $failed = $server->status === ServerStatus::Failed;

        return [
            'status' => $server->status?->value,
            'completed' => $completed,
            'phases' => array_map(fn(array $phase, int $index): array => [
                ...$phase,
                'state' => match (true) {
                    $index < $completed => 'done',
                    $index === $completed && $running => 'running',
                    $index === $completed && $failed => 'failed',
                    default => 'pending',
                },
            ], self::PROVISIONING_PHASES, array_keys(self::PROVISIONING_PHASES)),
            'error' => $failed ? $server->provisioning_error : null,
            'failed_phase' => $failed ? $completed + 1 : null,
            'log' => $server->provisioning_log,
            'public_key' => $server->ssh_public_key,
            'ssh' => $server->ssh_user . '@' . $server->ip_address . ':' . $server->ssh_port,
            'ssh_user' => $server->ssh_user,
            'audit' => $this->audit($server),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function audit(Server $server): ?array
    {
        $audit = (array) $server->audit;

        if ($audit === []) {
            return null;
        }

        $report = ServerAudit::report($server);
        $findings = $report?->toArray() ?? [];
        $count = fn(string $severity): int => count(array_filter($findings, fn(array $finding): bool => $finding['severity'] === $severity));
        $ran = is_string($audit['ran_at'] ?? null) ? CarbonImmutable::parse($audit['ran_at']) : null;
        $state = (string) ($audit['state'] ?? 'done');
        $stalled = $state === 'running' && !ServerAudit::isRunning($server);

        return [
            'state' => $stalled ? 'failed' : $state,
            'ran' => $ran === null ? null : ($ran->isToday() ? 'at ' . $ran->format('H:i') : 'on ' . $ran->format('M j')),
            'error' => $stalled ? 'The check stopped before it finished. Check again.' : ($audit['error'] ?? null),
            'findings' => $findings,
            'blockers' => $count('blocker'),
            'warnings' => $count('warning'),
            'fresh' => ServerAudit::isFresh($server),
            'can_proceed' => ServerAudit::isFresh($server) && !ServerAudit::blocks($server),
        ];
    }
}
