<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\ServerStatus;
use App\Jobs\RebootServer;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Services\Server\Audit\ServerAudit;
use App\Services\SSH\AuthorizedKeyCommand;
use Carbon\CarbonImmutable;

final class ServerPresenter
{
    private const INSTALLED_BEYOND_USABLE_MB = 512;

    public const PROVISIONING_PHASES = [
        ['name' => 'Operating system', 'desc' => 'detected and checked'],
        ['name' => 'Base system', 'desc' => 'packages and build tools'],
        ['name' => 'Runtimes', 'desc' => 'asdf, shared across apps'],
        ['name' => 'Directories', 'desc' => 'apps, releases and logs, root-owned'],
        ['name' => 'Caddy', 'desc' => 'HTTP routing, automatic HTTPS'],
        ['name' => 'Firewall', 'desc' => 'default-deny, SSH and web ports open'],
        ['name' => 'Hardening', 'desc' => 'SSH key-only, fail2ban, sysctls, auto-patching'],
        ['name' => 'Verification', 'desc' => 'admin API reachable from localhost only'],
    ];

    public function __construct(private readonly CloudPresenter $cloud) {}

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
            'this_machine' => (bool) $server->runs_coresmith,
            'metrics' => $metrics,
            'unreachable' => $unreachable ? [
                'last_answer' => Formats::when($server->last_connected_at),
                'reason' => (string) $server->last_connection_error,
                'can_reboot' => RebootServer::viaProvider($server),
            ] : null,
            'disk_warning' => $metrics['disk']['now'] !== null && $metrics['disk']['now'] >= FleetSnapshot::DISK_WARNING
                ? ['percent' => $metrics['disk']['now'], 'apps' => $server->applications->count()]
                : null,
            'host_key' => $server->pending_host_key_fingerprint === null ? null : [
                'expected' => $server->host_key_fingerprint,
                'observed' => $server->pending_host_key_fingerprint,
                'changed_at' => $server->host_key_changed_at?->toIso8601String(),
            ],
            'provisioning' => in_array($server->status, [ServerStatus::Active, ServerStatus::Moving], true) ? null : $this->provisioning($server),
            'move' => $server->status !== ServerStatus::Moving ? null : [
                'away' => $server->team?->moving_at !== null,
                'state' => (string) ($server->takeover['state'] ?? 'waiting'),
                'error' => $server->takeover['error'] ?? null,
                'can_retry' => $server->team?->moving_at === null && ($server->takeover['state'] ?? null) === 'failed' && $user->can('update', $server),
            ],
            'cloud' => $this->cloud->server($server, $user),
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
     * @return array<string, array{now: int|null, series: list<float>, detail: string|null}>
     */
    public function metrics(Server $server): array
    {
        $readings = $server->metrics()
            ->where('recorded_at', '>=', now()->subDay())
            ->orderBy('recorded_at')
            ->get(['cpu_percent', 'memory_percent', 'disk_percent', 'load_1m', 'memory_used_mb', 'memory_total_mb', 'disk_used_mb', 'disk_free_mb', 'disk_total_mb', 'recorded_at']);

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
        $now = $fresh ? $latest : null;

        return [
            'cpu' => ['now' => $now === null ? null : (int) round((float) $now->cpu_percent), 'series' => $series['cpu'], 'detail' => $this->cpuDetail($server, $now)],
            'memory' => ['now' => $now === null ? null : (int) round((float) $now->memory_percent), 'series' => $series['memory'], 'detail' => $this->memoryDetail($server, $now)],
            'disk' => ['now' => $now === null ? null : (int) round((float) $now->disk_percent), 'series' => $series['disk'], 'detail' => $this->diskDetail($server, $now)],
        ];
    }

    private function cpuDetail(Server $server, ?ServerMetric $now): ?string
    {
        $meta = (array) $server->meta;
        $threads = (int) ($meta['cpu_cores'] ?? 0);
        $cores = (int) ($meta['cpu_physical_cores'] ?? 0);

        return $this->detail([
            is_string($meta['cpu_model'] ?? null) ? $meta['cpu_model'] : null,
            match (true) {
                $cores > 0 && $threads > $cores => "{$cores}\u{00A0}cores, {$threads}\u{00A0}threads",
                $threads > 0 => $threads . "\u{00A0}" . ($threads === 1 ? 'core' : 'cores'),
                default => null,
            },
            $now === null ? null : "load\u{00A0}" . number_format((float) $now->load_1m, 2),
        ]);
    }

    private function memoryDetail(Server $server, ?ServerMetric $now): ?string
    {
        $meta = (array) $server->meta;
        $total = $now?->memory_total_mb ?? (isset($meta['memory_mb']) ? (int) $meta['memory_mb'] : 0);
        $installed = (int) ($meta['memory_installed_mb'] ?? 0);

        return $this->detail([
            $this->amount($now?->memory_used_mb, $total),
            $installed - $total >= self::INSTALLED_BEYOND_USABLE_MB ? Formats::size($installed) . ' installed' : null,
        ]);
    }

    private function diskDetail(Server $server, ?ServerMetric $now): ?string
    {
        $meta = (array) $server->meta;

        return $this->detail([
            $this->amount($now?->disk_used_mb, $now?->disk_total_mb ?? (int) ($meta['disk_gb'] ?? 0) * 1024),
            $now?->disk_free_mb === null ? null : Formats::size($now->disk_free_mb) . ' free',
        ]);
    }

    private function amount(?int $used, int $total): ?string
    {
        return match (true) {
            $total <= 0 => null,
            $used === null => Formats::size($total) . ' total',
            default => Formats::size($used) . ' of ' . Formats::size($total) . ' used',
        };
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function detail(array $parts): ?string
    {
        $kept = array_values(array_filter($parts, fn(?string $part): bool => $part !== null && $part !== ''));

        return $kept === [] ? null : implode(' · ', $kept);
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
            'connect_command' => AuthorizedKeyCommand::forPerson($server->ssh_user, (string) $server->ssh_public_key),
            'connect_error' => $server->last_connection_error,
            'connected' => $server->last_connected_at !== null && $server->last_connection_error === null,
            'password_sign_in' => $server->status->isBeforeProvisioning() && !$server->runs_coresmith,
            'ssh' => $server->sshAddress(),
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
