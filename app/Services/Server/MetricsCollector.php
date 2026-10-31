<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\AppSize;
use App\Enums\PowerState;
use App\Jobs\DeployApplication;
use App\Jobs\WakeApplication;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Notifications\ApplicationOomKilledNotification;
use App\Notifications\DiskPressureNotification;
use App\Notifications\MemoryPressureNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class MetricsCollector
{
    public const ACCESS_LOG = '/var/log/caddy/access.log';

    public const DISK_PRESSURE_PERCENT = 80;

    public const MEMORY_PRESSURE_PERCENT = 95;

    public const MEMORY_PRESSURE_READINGS = 3;

    public const PROBE_SECONDS = 20;

    private const MEMORY_PRESSURE_WINDOW_MINUTES = 4;

    public function collect(SSHConnection $connection): ServerMetric
    {
        $output = $connection->execute($this->probe(), timeout: self::PROBE_SECONDS)->throw()->output;
        $values = $this->parse($output);
        $server = $connection->server();

        if ($values['uptime'] !== null) {
            $server->forceFill(['booted_at' => now()->subSeconds($values['uptime'])->startOfMinute()])->save();
        }

        if ($values['disk'] >= self::DISK_PRESSURE_PERCENT) {
            $this->recordDiskPressure($server, $values['disk']);
        }

        $metric = ServerMetric::create([
            'server_id' => $connection->server()->getKey(),
            'cpu_percent' => $values['cpu'],
            'memory_percent' => $values['memory'],
            'disk_percent' => $values['disk'],
            'load_1m' => $values['load1'],
            'load_5m' => $values['load5'],
            'load_15m' => $values['load15'],
            'network_rx_bytes' => $values['rx'],
            'network_tx_bytes' => $values['tx'],
            'recorded_at' => now(),
        ]);

        $this->recordMemoryPressure($server, $values['memory']);

        return $metric;
    }

    private function recordDiskPressure(Server $server, float $percent): void
    {
        $level = $percent >= DeployApplication::DISK_ABORT_PERCENT ? 'critical' : 'warning';

        $recent = Activity::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->getKey())
            ->where('description', 'disk_pressure')
            ->where('created_at', '>=', now()->subDay())
            ->where(fn($query) => $level === 'critical'
                ? $query->where('properties->level', 'critical')
                : $query->where('properties->level', 'warning')->orWhereNull('properties->level'))
            ->exists();

        if ($recent) {
            return;
        }

        activity()
            ->performedOn($server)
            ->withProperties(['percent' => (int) round($percent), 'level' => $level])
            ->log('disk_pressure');

        app(TeamNotifier::class)->send($server->team, Preferences::DISK_PRESSURE, new DiskPressureNotification($server, (int) round($percent), critical: $level === 'critical'));
    }

    private function recordMemoryPressure(Server $server, float $latest): void
    {
        if ($latest < self::MEMORY_PRESSURE_PERCENT) {
            return;
        }

        $readings = ServerMetric::query()
            ->where('server_id', $server->getKey())
            ->where('recorded_at', '>=', now()->subMinutes(self::MEMORY_PRESSURE_WINDOW_MINUTES))
            ->latest('recorded_at')
            ->limit(self::MEMORY_PRESSURE_READINGS)
            ->pluck('memory_percent');

        if ($readings->count() < self::MEMORY_PRESSURE_READINGS || $readings->contains(fn(mixed $reading): bool => (float) $reading < self::MEMORY_PRESSURE_PERCENT)) {
            return;
        }

        $recent = Activity::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->getKey())
            ->where('description', 'memory_pressure')
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($recent) {
            return;
        }

        $percent = (int) round($latest);

        activity()
            ->performedOn($server)
            ->withProperties(['percent' => $percent])
            ->log('memory_pressure');

        app(TeamNotifier::class)->send($server->team, Preferences::SERVER_ALERTS, new MemoryPressureNotification($server, $percent));
    }

    public function recordTraffic(SSHConnection $connection): void
    {
        $server = $connection->server();
        $sleeping = $server->applications()
            ->where('power_state', PowerState::Sleeping->value)
            ->get();

        if ($sleeping->isEmpty()) {
            return;
        }

        $hosts = $this->recentHosts($connection);

        foreach ($sleeping as $application) {
            if ($this->wasRequested($application, $hosts)) {
                WakeApplication::dispatch($application);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function recentHosts(SSHConnection $connection): array
    {
        $log = escapeshellarg(self::ACCESS_LOG);
        $result = $connection->execute(
            "test -f {$log} && tail -n 500 {$log} | grep -o '\"host\":\"[^\"]*\"' | cut -d'\"' -f4 | sort -u || true",
            timeout: self::PROBE_SECONDS,
        );

        return array_values(array_filter(array_map(trim(...), preg_split('/\R/', $result->output) ?: [])));
    }

    /**
     * @param  list<string>  $hosts
     */
    private function wasRequested(Application $application, array $hosts): bool
    {
        foreach ($application->allDomains() as $domain) {
            if (in_array($domain, $hosts, true)) {
                return true;
            }
        }

        return false;
    }

    public function recordOomKills(SSHConnection $connection): void
    {
        $server = $connection->server();
        $key = 'oom-since:' . $server->getKey();
        $lookback = now()->subSeconds(OomKillReader::LOOKBACK_SECONDS)->getTimestamp();
        $since = CarbonImmutable::createFromTimestamp(min((int) Cache::get($key, $lookback) ?: $lookback, $lookback));
        $kills = app(OomKillReader::class)->read($connection, $since, self::PROBE_SECONDS);

        Cache::put($key, now()->getTimestamp(), now()->addDay());

        if ($kills === []) {
            return;
        }

        $applications = $server->applications()->get()->sortByDesc(fn(Application $application): int => strlen($application->slug))->values();

        foreach ($kills as $kill) {
            $application = $applications->first(fn(Application $candidate): bool => str_starts_with($kill['unit'], 'coresmith-app-' . $candidate->slug . '-'));

            if ($application === null) {
                continue;
            }

            $at = $kill['at']->toIso8601String();

            $seen = Activity::query()
                ->where('subject_type', $application->getMorphClass())
                ->where('subject_id', $application->getKey())
                ->where('description', 'app_oom_killed')
                ->where('properties->at', $at)
                ->exists();

            if ($seen) {
                continue;
            }

            $firstToday = !Activity::query()
                ->where('subject_type', $application->getMorphClass())
                ->where('subject_id', $application->getKey())
                ->where('description', 'app_oom_killed')
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            $process = Str::after($kill['unit'], 'coresmith-app-' . $application->slug . '-');
            $limitMb = (AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small)->memoryMaxMb();

            activity()
                ->performedOn($application)
                ->withProperties(['process' => $process, 'at' => $at, 'limit_mb' => $limitMb])
                ->log('app_oom_killed');

            if ($firstToday) {
                app(TeamNotifier::class)->send($server->team, Preferences::SERVER_ALERTS, new ApplicationOomKilledNotification($application, $process, $limitMb, $kill['at']));
            }
        }
    }

    public function probe(): string
    {
        return implode(' ; ', [
            "top -bn1 | awk '/Cpu\\(s\\)/ {print \"cpu=\" 100 - \$8}'",
            "free -m | awk '/^Mem:/ {printf \"memory=%.1f\\n\", (\$3/\$2)*100}'",
            "df -P / | awk 'NR==2 {gsub(/%/,\"\",\$5); print \"disk=\" \$5}'",
            "awk '{print \"load=\" \$1 \",\" \$2 \",\" \$3}' /proc/loadavg",
            "awk '/^ *(eth|ens|enp|eno)[0-9]/ {rx+=\$2; tx+=\$10} END {print \"net=\" rx+0 \",\" tx+0}' /proc/net/dev",
            "awk '{print \"uptime=\" int(\$1)}' /proc/uptime",
        ]);
    }

    /**
     * @return array{cpu: float, memory: float, disk: float, load1: float, load5: float, load15: float, rx: ?int, tx: ?int, uptime: ?int}
     */
    public function parse(string $output): array
    {
        $cpu = $this->match('/cpu=([0-9.]+)/', $output);
        $memory = $this->match('/memory=([0-9.]+)/', $output);
        $disk = $this->match('/disk=([0-9.]+)/', $output);

        $load = [0.0, 0.0, 0.0];

        if (preg_match('/load=([0-9.]+),([0-9.]+),([0-9.]+)/', $output, $matches) === 1) {
            $load = [(float) $matches[1], (float) $matches[2], (float) $matches[3]];
        }

        $rx = null;
        $tx = null;

        if (preg_match('/net=([0-9]+),([0-9]+)/', $output, $matches) === 1) {
            $rx = (int) $matches[1];
            $tx = (int) $matches[2];
        }

        $uptime = preg_match('/uptime=([0-9]+)/', $output, $matches) === 1 ? (int) $matches[1] : null;

        return [
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'load1' => $load[0],
            'load5' => $load[1],
            'load15' => $load[2],
            'rx' => $rx,
            'tx' => $tx,
            'uptime' => $uptime,
        ];
    }

    private function match(string $pattern, string $output): float
    {
        return preg_match($pattern, $output, $matches) === 1 ? (float) $matches[1] : 0.0;
    }
}
