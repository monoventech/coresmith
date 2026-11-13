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
use App\Models\User;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Server\CertificateCheck;
use App\Services\Teams\TeamAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** @var list<array{severity: string, subject: string, summary: string, href: string}>|null */
    private ?array $attention = null;

    /** @var Collection<string, Collection<int, ServerMetric>>|null */
    private ?Collection $recent = null;

    public function __construct(private readonly Team $team, private readonly ?User $viewer = null) {}

    /**
     * @return Collection<int, Application>
     */
    public function sharedApplications(): Collection
    {
        if ($this->viewer === null || app(TeamAccess::class)->seesEverything($this->viewer, $this->team)) {
            return new Collection;
        }

        return $this->applicationsQuery()
            ->whereNotIn('server_id', $this->servers()->modelKeys())
            ->with('server')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Server>
     */
    public function servers(): Collection
    {
        return $this->servers ??= $this->serversQuery()
            ->with(['applications' => fn($query) => $query->whereIn('id', $this->applicationsQuery()->select('id'))->orderBy('name'), 'cloudProvider'])
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
            ->whereIn('application_id', $this->applicationsQuery()->select('id'))
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
            $server->status === ServerStatus::Moving && $this->team->moving_at !== null => ['key' => 'moving_away', 'label' => 'Moving out', 'tone' => 'default'],
            $server->status === ServerStatus::Moving && ($server->takeover['state'] ?? null) === 'failed' => ['key' => 'move_failed', 'label' => 'Move failed', 'tone' => 'destructive'],
            $server->status === ServerStatus::Moving => ['key' => 'moving', 'label' => 'Moving here', 'tone' => 'accent'],
            $server->status === ServerStatus::Creating && CloudTask::of($server)?->state === CloudTask::FAILED => ['key' => 'creation_failed', 'label' => 'Creation failed', 'tone' => 'destructive'],
            $server->status === ServerStatus::Creating => ['key' => 'creating', 'label' => 'Creating', 'tone' => 'accent'],
            CloudTask::running($server, CloudTask::DESTROY) => ['key' => 'destroying', 'label' => 'Destroying', 'tone' => 'default'],
            CloudTask::of($server)?->type === CloudTask::DESTROY && CloudTask::of($server)?->state === CloudTask::FAILED => ['key' => 'destroy_failed', 'label' => 'Destroy failed', 'tone' => 'destructive'],
            $server->status === ServerStatus::Failed => ['key' => 'failed', 'label' => 'Provisioning failed', 'tone' => 'destructive'],
            $server->status === ServerStatus::Disconnected => ['key' => 'disconnected', 'label' => 'Unreachable', 'tone' => 'destructive'],
            $server->unreachable_since !== null => ['key' => 'unreachable', 'label' => 'Unreachable', 'tone' => 'destructive'],
            $server->status === ServerStatus::Provisioning => ['key' => 'provisioning', 'label' => 'Provisioning', 'tone' => 'accent'],
            $server->status === ServerStatus::Pending && $server->last_connected_at === null => ['key' => 'pending', 'label' => 'Not connected', 'tone' => 'default'],
            $server->status === ServerStatus::Pending => ['key' => 'pending', 'label' => 'Not provisioned', 'tone' => 'default'],
            CloudTask::running($server, CloudTask::RESIZE) => ['key' => 'resizing', 'label' => 'Resizing', 'tone' => 'accent'],
            CloudTask::of($server)?->type === CloudTask::RESIZE && CloudTask::of($server)?->state === CloudTask::FAILED => ['key' => 'resize_failed', 'label' => 'Resize failed', 'tone' => 'warning'],
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
        $memory = Formats::memory(isset($meta['memory_mb']) ? (int) $meta['memory_mb'] : null);
        $cores = (int) ($meta['cpu_physical_cores'] ?? $meta['cpu_cores'] ?? 0);
        $disk = (int) ($meta['disk_gb'] ?? 0);

        return implode(' · ', array_values(array_filter([
            $server->environment,
            $server->region,
            $long && $cores > 0 ? $cores . ' ' . ($cores === 1 ? 'core' : 'cores') : null,
            $memory === null ? null : $memory . ' RAM',
            $long && $disk > 0 ? Formats::size($disk * 1024) . ' disk' : null,
            $long ? $this->system($server) : null,
            $long ? $server->ip_address : $count . ' ' . ($count === 1 ? 'app' : 'apps'),
            Formats::uptime($server->booted_at),
        ])));
    }

    private function system(Server $server): ?string
    {
        $pretty = ((array) $server->meta)['os_pretty'] ?? null;

        return match (true) {
            is_string($pretty) && $pretty !== '' => $pretty,
            (bool) $server->os_name => trim(ucfirst((string) $server->os_name) . ' ' . $server->os_version),
            default => null,
        };
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
            'cpu_trend' => $this->trend($server, 'cpu_percent'),
            'memory_trend' => $this->trend($server, 'memory_percent'),
            'href' => route('servers.show', $server),
            'logs_href' => route('servers.activity', $server),
            'watch_href' => $running === null ? null : route('servers.applications.deployments.show', [$server, $running->application_id, $running]),
            'applications' => $server->applications->map(fn(Application $application): array => [
                'id' => $application->getKey(),
                'name' => $application->name,
                'kind' => ApplicationPresenter::kind($application),
                'state' => ApplicationPresenter::state($application),
                'href' => route('servers.applications.show', [$server, $application]),
                'deploy_href' => route('servers.applications.deploy', [$server, $application]),
            ])->values()->all(),
            'cost' => Formats::monthly($server->provider_monthly_cost === null ? null : (string) $server->provider_monthly_cost, $server->provider_currency),
            'provider' => $server->cloudProvider?->type->label(),
        ];
    }

    /**
     * @return list<array{severity: string, subject: string, summary: string, href: string}>
     */
    public function attention(): array
    {
        return $this->attention ??= $this->gatherAttention();
    }

    /**
     * @return list<array{severity: string, subject: string, summary: string, href: string}>
     */
    private function gatherAttention(): array
    {
        $items = [];

        foreach ($this->servers() as $server) {
            $state = $this->state($server);

            if (in_array($state['key'], ['host_key', 'failed', 'disconnected', 'unreachable', 'creation_failed', 'destroy_failed'], true)) {
                $items[] = ['severity' => 'critical', 'subject' => $server->name, 'summary' => strtolower($state['label']), 'href' => route('servers.show', $server)];
            } elseif ($state['key'] === 'resize_failed') {
                $items[] = ['severity' => 'warning', 'subject' => $server->name, 'summary' => 'resize failed', 'href' => route('servers.show', $server)];
            } elseif ($state['key'] === 'memory' || ($state['key'] === 'deploying' && ($this->latestMetric($server)?->memory_percent ?? 0) >= self::MEMORY_WARNING)) {
                $items[] = ['severity' => 'warning', 'subject' => $server->name, 'summary' => 'memory ' . (int) round((float) $this->latestMetric($server)?->memory_percent) . '%', 'href' => route('servers.show', $server)];
            } elseif ($state['key'] === 'warning' || ($state['key'] === 'deploying' && ($this->latestMetric($server)?->disk_percent ?? 0) >= self::DISK_WARNING)) {
                $items[] = ['severity' => 'warning', 'subject' => $server->name, 'summary' => 'disk ' . (int) round((float) $this->latestMetric($server)?->disk_percent) . '%', 'href' => route('servers.show', $server)];
            }

            if ($server->sshLeftToOwner() && $server->sshPasswordsOn()) {
                $items[] = ['severity' => 'warning', 'subject' => $server->name, 'summary' => 'password login on', 'href' => route('servers.hardening', $server)];
            }

            foreach ($server->applications as $application) {
                if ($application->power_state === PowerState::Stopped) {
                    $items[] = ['severity' => 'warning', 'subject' => $application->name, 'summary' => 'stopped', 'href' => route('servers.applications.show', [$server, $application])];
                }
            }
        }

        foreach ($this->sharedApplications() as $application) {
            if ($application->power_state === PowerState::Stopped) {
                $items[] = ['severity' => 'warning', 'subject' => $application->name, 'summary' => 'stopped', 'href' => route('servers.applications.show', [$application->server_id, $application])];
            }
        }

        $failed = Deployment::query()
            ->whereIn('application_id', $this->applicationsQuery()->select('id'))
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

        $applications = $this->applicationsQuery()->with('server')->get()->keyBy('id');
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

        $expiring = Activity::query()
            ->where('subject_type', (new Application)->getMorphClass())
            ->whereIn('subject_id', $applications->keys())
            ->where('description', 'certificate_expiring')
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->get()
            ->unique('subject_id');

        foreach ($expiring as $alert) {
            $application = $applications->get($alert->subject_id);

            if ($application === null) {
                continue;
            }

            $days = (int) ($alert->properties['days'] ?? 0);

            $items[] = ['severity' => 'warning', 'subject' => $application->name, 'summary' => $days < 0 ? 'certificate expired' : 'certificate ' . CertificateCheck::expiry($days), 'href' => route('servers.applications.show', [$application->server_id, $application])];
        }

        usort($items, static fn(array $a, array $b): int => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));

        return $items;
    }

    public function deploysThisWeek(): int
    {
        return Deployment::query()
            ->whereIn('application_id', $this->applicationsQuery()->select('id'))
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNot('status', DeploymentStatus::Cancelled->value)
            ->count();
    }

    /**
     * @return list<int>
     */
    public function deploysPerDay(int $days = 7): array
    {
        $since = CarbonImmutable::now()->startOfDay()->subDays($days - 1);
        $counts = Deployment::query()
            ->whereIn('application_id', $this->applicationsQuery()->select('id'))
            ->where('created_at', '>=', $since)
            ->whereNot('status', DeploymentStatus::Cancelled->value)
            ->pluck('created_at')
            ->countBy(fn(mixed $at): string => CarbonImmutable::parse($at)->toDateString());

        return array_map(fn(int $offset): int => (int) ($counts[$since->addDays($offset)->toDateString()] ?? 0), range(0, $days - 1));
    }

    /**
     * @return list<?float>
     */
    public function uptimeByDay(): array
    {
        $now = CarbonImmutable::now();
        $start = $now->startOfDay()->subDays(self::UPTIME_DAYS - 1);
        $rows = ServerMetric::query()
            ->whereIn('server_id', $this->servers()->modelKeys())
            ->where('recorded_at', '>=', $start)
            ->selectRaw('server_id, date(recorded_at) as reading_day, count(*) as readings, min(recorded_at) as first_reading')
            ->groupBy('server_id', 'reading_day')
            ->get();
        $firsts = $rows->groupBy('server_id')->map(fn(Collection $days): CarbonImmutable => $days->map(fn(ServerMetric $row): CarbonImmutable => CarbonImmutable::parse($row->getAttribute('first_reading'))->startOfMinute())->min());
        $readings = $rows->mapWithKeys(fn(ServerMetric $row): array => [$row->getAttribute('server_id') . '|' . substr((string) $row->getAttribute('reading_day'), 0, 10) => (int) $row->getAttribute('readings')]);
        $days = [];

        foreach (range(0, self::UPTIME_DAYS - 1) as $offset) {
            $from = $start->addDays($offset);
            $until = $from->addDay()->min($now);
            $observed = 0;
            $expected = 0;

            foreach ($firsts as $server => $first) {
                $since = $from->max($first);

                if ($since >= $until) {
                    continue;
                }

                $minutes = (int) ceil($since->diffInMinutes($until, true));
                $observed += min($readings->get($server . '|' . $from->toDateString(), 0), $minutes);
                $expected += $minutes;
            }

            $days[] = $expected === 0 ? null : round($observed / $expected * 100, 2);
        }

        return $days;
    }

    /**
     * @return list<int>
     */
    public function trend(Server $server, string $column, int $points = 12): array
    {
        $since = CarbonImmutable::now()->subHour();
        $this->recent ??= ServerMetric::query()
            ->whereIn('server_id', $this->servers()->modelKeys())
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get()
            ->groupBy('server_id');
        $width = 60 / $points;

        return ($this->recent->get($server->getKey()) ?? new Collection)
            ->groupBy(fn(ServerMetric $metric): int => min($points - 1, (int) floor($since->diffInMinutes($metric->recorded_at, true) / $width)))
            ->sortKeys()
            ->map(fn(Collection $bucket): int => (int) round((float) $bucket->avg($column)))
            ->values()
            ->all();
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

    private function serversQuery(): HasMany
    {
        return $this->viewer === null ? $this->team->servers() : app(TeamAccess::class)->servers($this->viewer, $this->team);
    }

    private function applicationsQuery(): HasMany
    {
        return $this->viewer === null ? $this->team->applications() : app(TeamAccess::class)->applications($this->viewer, $this->team);
    }
}
