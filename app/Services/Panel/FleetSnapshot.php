<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

final class FleetSnapshot
{
    public const DISK_WARNING = 80;

    public const MEMORY_WARNING = 95;

    public const FRESH_METRIC_MINUTES = 15;

    public const UPTIME_DAYS = 30;

    /** @var Collection<int, Server>|null */
    private ?Collection $servers = null;

    /** @var Collection<string, ServerMetric>|null */
    private ?Collection $metrics = null;

    /** @var Collection<int, Deployment>|null */
    private ?Collection $running = null;

    public function __construct(private readonly Team $team) {}

    /**
     * @return Collection<int, Server>
     */
    public function servers(): Collection
    {
        return $this->servers ??= $this->team->servers()
            ->with(['applications' => fn($query) => $query->orderBy('name')])
            ->oldest()
            ->orderBy('name')
            ->get();
    }

    public function latestMetric(Server $server): ?ServerMetric
    {
        $this->metrics ??= ServerMetric::query()
            ->whereIn('server_id', $this->servers()->modelKeys())
            ->where('recorded_at', '>=', now()->subMinutes(self::FRESH_METRIC_MINUTES))
            ->orderByDesc('recorded_at')
            ->get()
            ->unique('server_id')
            ->keyBy('server_id');

        return $this->metrics->get($server->getKey());
    }

    /**
     * @return Collection<int, Deployment>
     */
    public function running(): Collection
    {
        return $this->running ??= Deployment::query()
            ->whereIn('application_id', $this->team->applications()->select('id'))
            ->where('status', DeploymentStatus::Running->value)
            ->with('application.server')
            ->latest('started_at')
            ->get();
    }

    public function runningOn(Server $server): ?Deployment
    {
        return $this->running()->first(fn(Deployment $deployment): bool => $deployment->application?->server_id === $server->getKey());
    }

    /**
     * @return array{key: string, label: string, tone: string}
     */
    public function state(Server $server): array
    {
        $metric = $this->latestMetric($server);
        $disk = $metric?->disk_percent;
        $memory = $metric?->memory_percent;

        return match (true) {
            $server->pending_host_key_fingerprint !== null => ['key' => 'host_key', 'label' => 'Host key changed', 'tone' => 'destructive'],
            $server->status === ServerStatus::Failed => ['key' => 'failed', 'label' => 'Provisioning failed', 'tone' => 'destructive'],
            $server->status === ServerStatus::Disconnected => ['key' => 'disconnected', 'label' => 'Unreachable', 'tone' => 'destructive'],
            $server->unreachable_since !== null => ['key' => 'unreachable', 'label' => 'Unreachable', 'tone' => 'destructive'],
            $server->status === ServerStatus::Provisioning => ['key' => 'provisioning', 'label' => 'Provisioning', 'tone' => 'accent'],
            $server->status === ServerStatus::Pending => ['key' => 'pending', 'label' => 'Not connected', 'tone' => 'default'],
            $this->runningOn($server) !== null => ['key' => 'deploying', 'label' => 'Deploying', 'tone' => 'accent'],
            $memory !== null && $memory >= self::MEMORY_WARNING => ['key' => 'memory', 'label' => 'Memory ' . (int) round($memory) . '%', 'tone' => 'warning'],
            $disk !== null && $disk >= self::DISK_WARNING => ['key' => 'warning', 'label' => 'Disk ' . (int) round($disk) . '%', 'tone' => 'warning'],
            default => ['key' => 'healthy', 'label' => 'Healthy', 'tone' => 'success'],
        };
    }

    public function meta(Server $server, bool $long = false): string
    {
        $count = $server->applications->count();
        $meta = (array) $server->meta;

        return implode(' · ', array_values(array_filter([
            $server->environment,
            $server->region,
            Formats::memory(isset($meta['memory_mb']) ? (int) $meta['memory_mb'] : null),
            $long && $server->os_name ? trim(ucfirst((string) $server->os_name) . ' ' . $server->os_version) : null,
            $long ? $server->ip_address : $count . ' ' . ($count === 1 ? 'app' : 'apps'),
            Formats::uptime($server->booted_at),
        ])));
    }

    public function compactMeta(Server $server): string
    {
        $count = $server->applications->count();

        return implode(' · ', array_values(array_filter([
            $server->environment,
            $server->region,
            $count . ' ' . ($count === 1 ? 'app' : 'apps'),
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    public function card(Server $server): array
    {
        $metric = $this->latestMetric($server);
        $running = $this->runningOn($server);

        return [
            'id' => $server->getKey(),
            'name' => $server->name,
            'state' => $this->state($server),
            'meta' => $this->meta($server),
            'compact_meta' => $this->compactMeta($server),
            'cpu' => $metric === null ? null : (int) round((float) $metric->cpu_percent),
            'memory' => $metric === null ? null : (int) round((float) $metric->memory_percent),
            'disk' => $metric === null ? null : (int) round((float) $metric->disk_percent),
            'href' => route('servers.show', $server),
            'logs_href' => route('servers.activity', $server),
            'watch_href' => $running === null ? null : route('servers.applications.deployments.show', [$server, $running->application_id, $running]),
            'applications' => $server->applications->map(fn(Application $application): array => [
                'id' => $application->getKey(),
                'name' => $application->name,
                'kind' => ApplicationPresenter::kind($application),
                'deploy_href' => route('servers.applications.deploy', [$server, $application]),
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array{severity: string, subject: string, summary: string, href: string}>
     */
    public function attention(): array
    {
        $items = [];

        foreach ($this->servers() as $server) {
            $state = $this->state($server);

            if (in_array($state['key'], ['host_key', 'failed', 'disconnected', 'unreachable'], true)) {
                $items[] = ['severity' => 'critical', 'subject' => $server->name, 'summary' => strtolower($state['label']), 'href' => route('servers.show', $server)];
            } elseif ($state['key'] === 'memory' || ($state['key'] === 'deploying' && ($this->latestMetric($server)?->memory_percent ?? 0) >= self::MEMORY_WARNING)) {
                $items[] = ['severity' => 'warning', 'subject' => $server->name, 'summary' => 'memory ' . (int) round((float) $this->latestMetric($server)?->memory_percent) . '%', 'href' => route('servers.show', $server)];
            } elseif ($state['key'] === 'warning' || ($state['key'] === 'deploying' && ($this->latestMetric($server)?->disk_percent ?? 0) >= self::DISK_WARNING)) {
                $items[] = ['severity' => 'warning', 'subject' => $server->name, 'summary' => 'disk ' . (int) round((float) $this->latestMetric($server)?->disk_percent) . '%', 'href' => route('servers.show', $server)];
            }

            foreach ($server->applications as $application) {
                if ($application->power_state === PowerState::Stopped) {
                    $items[] = ['severity' => 'warning', 'subject' => $application->name, 'summary' => 'stopped', 'href' => route('servers.applications.show', [$server, $application])];
                }
            }
        }

        $failed = Deployment::query()
            ->whereIn('application_id', $this->team->applications()->select('id'))
            ->whereIn('status', [DeploymentStatus::Succeeded->value, DeploymentStatus::Failed->value])
            ->with('application.server')
            ->latest('created_at')
            ->get()
            ->unique('application_id')
            ->filter(fn(Deployment $deployment): bool => $deployment->status === DeploymentStatus::Failed && $deployment->application !== null);

        foreach ($failed as $deployment) {
            $items[] = [
                'severity' => 'warning',
                'subject' => $deployment->application->name,
                'summary' => 'last deploy failed',
                'href' => route('servers.applications.deployments.show', [$deployment->application->server_id, $deployment->application_id, $deployment]),
            ];
        }

        $applications = $this->team->applications()->with('server')->get()->keyBy('id');
        $killed = Activity::query()
            ->where('subject_type', (new Application)->getMorphClass())
            ->whereIn('subject_id', $applications->keys())
            ->where('description', 'app_oom_killed')
            ->where('created_at', '>=', now()->subDay())
            ->pluck('subject_id')
            ->unique();

        foreach ($killed as $id) {
            $application = $applications->get($id);

            if ($application === null) {
                continue;
            }

            $items[] = ['severity' => 'warning', 'subject' => $application->name, 'summary' => 'killed for memory', 'href' => route('servers.applications.show', [$application->server_id, $application])];
        }

        usort($items, static fn(array $a, array $b): int => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));

        return $items;
    }

    public function deploysThisWeek(): int
    {
        return Deployment::query()
            ->whereIn('application_id', $this->team->applications()->select('id'))
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNot('status', DeploymentStatus::Cancelled->value)
            ->count();
    }

    public function uptime(): ?float
    {
        $observed = 0;
        $expected = 0;
        $since = now()->subDays(self::UPTIME_DAYS);

        foreach ($this->servers() as $server) {
            $window = ServerMetric::query()
                ->where('server_id', $server->getKey())
                ->where('recorded_at', '>=', $since)
                ->selectRaw('count(*) as readings, min(recorded_at) as first_reading')
                ->first();

            if ($window === null || (int) $window->readings === 0) {
                continue;
            }

            $minutes = max(1, (int) ceil(now()->diffInMinutes($window->first_reading, true)) + 1);

            $observed += min((int) $window->readings, $minutes);
            $expected += $minutes;
        }

        return $expected === 0 ? null : round($observed / $expected * 100, 2);
    }
}
