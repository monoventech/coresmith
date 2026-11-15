<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Enums\MonitorKind;
use App\Events\FleetChanged;
use App\Jobs\DeployApplication;
use App\Models\MonitorAlert;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Notifications\DiskPressureNotification;
use App\Notifications\MonitorAlertNotification;
use App\Notifications\MonitorRecoveredNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReadingMonitor
{
    public const KINDS = [MonitorKind::Disk, MonitorKind::Memory, MonitorKind::Cpu, MonitorKind::Load];

    public const RECOVERY_MINUTES = 5;

    public const LOOKBACK_SECONDS = 30;

    public const DRIFT_SECONDS = 5;

    public const ACTIVITY = [
        'disk' => 'disk_pressure',
        'memory' => 'memory_pressure',
        'cpu' => 'cpu_pressure',
        'load' => 'load_pressure',
    ];

    public function __construct(private readonly MonitorLimits $limits) {}

    public function evaluate(Server $server, ServerMetric $metric): void
    {
        $limits = $this->limits->forAll($server, self::KINDS);
        $alerts = MonitorAlert::query()
            ->where('server_id', $server->getKey())
            ->whereNull('application_id')
            ->whereNotNull('open_key')
            ->get()
            ->keyBy(fn(MonitorAlert $alert): string => $alert->kind->value);
        $longest = max(array_map(fn(MonitorLimit $limit): int => max($limit->duration, self::RECOVERY_MINUTES), $limits));
        $readings = ServerMetric::query()
            ->where('server_id', $server->getKey())
            ->where('recorded_at', '>=', $metric->recorded_at->subMinutes($longest)->subSeconds(self::LOOKBACK_SECONDS))
            ->get();

        foreach (self::KINDS as $kind) {
            $this->check($server, $limits[$kind->value], $alerts->get($kind->value), $metric, $readings);
        }
    }

    /**
     * @param  Collection<int, ServerMetric>  $readings
     */
    private function check(Server $server, MonitorLimit $limit, ?MonitorAlert $open, ServerMetric $metric, Collection $readings): void
    {
        $kind = $limit->kind;

        if (!$limit->enabled) {
            $open?->close();

            return;
        }

        $held = $this->values($server, $kind, $limit->duration, $metric, $readings);

        if ($held !== null && min($held) >= $limit->threshold) {
            $open === null ? $this->open($server, $limit, $held, $metric) : $this->hold($server, $open, $held);

            return;
        }

        if ($open === null) {
            return;
        }

        $clear = $this->values($server, $kind, max($limit->duration, self::RECOVERY_MINUTES), $metric, $readings);

        if ($clear !== null && max($clear) < $limit->threshold) {
            $this->recover($server, $open, $limit);
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function open(Server $server, MonitorLimit $limit, array $values, ServerMetric $metric): void
    {
        $kind = $limit->kind;
        $critical = $kind === MonitorKind::Disk && max($values) >= DeployApplication::DISK_ABORT_PERCENT;
        $level = $kind === MonitorKind::Disk ? ($critical ? 'critical' : 'warning') : null;
        $shown = $kind === MonitorKind::Disk ? max($values) : min($values);

        try {
            $alert = DB::transaction(fn(): MonitorAlert => MonitorAlert::open($server, $kind, level: $level, peak: max($values)));
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $sent = $this->notify($server, $kind, $kind === MonitorKind::Disk
            ? new DiskPressureNotification($server, (int) round($shown), critical: $critical)
            : new MonitorAlertNotification($server, $kind, $shown, $limit, latest: self::value($kind, $metric, self::cores($server))));

        if (!$sent) {
            $alert->delete();

            return;
        }

        $properties = match ($kind) {
            MonitorKind::Disk => ['percent' => (int) round($shown), 'level' => $level],
            MonitorKind::Load => ['load' => round($shown, 2), 'minutes' => $limit->duration],
            default => ['percent' => (int) floor($shown), 'minutes' => $limit->duration],
        };

        activity()->performedOn($server)->withProperties($properties)->log(self::ACTIVITY[$kind->value]);
        FleetChanged::forServer($server);
    }

    /**
     * @param  list<float>  $values
     */
    private function hold(Server $server, MonitorAlert $alert, array $values): void
    {
        $peak = max($values);

        if ($peak > (float) $alert->peak) {
            $alert->forceFill(['peak' => $peak])->save();
        }

        if ($alert->kind !== MonitorKind::Disk || $alert->level === 'critical' || $peak < DeployApplication::DISK_ABORT_PERCENT || !$alert->escalate('critical')) {
            return;
        }

        if (!$this->notify($server, MonitorKind::Disk, new DiskPressureNotification($server, (int) round($peak), critical: true))) {
            $alert->escalate('warning');

            return;
        }

        activity()->performedOn($server)->withProperties(['percent' => (int) round($peak), 'level' => 'critical'])->log('disk_pressure');
        FleetChanged::forServer($server);
    }

    private function recover(Server $server, MonitorAlert $alert, MonitorLimit $limit): void
    {
        if (!$alert->close()) {
            return;
        }

        $past = $alert->opened_at->diffInSeconds($alert->closed_at) + ($limit->duration - max($limit->duration, self::RECOVERY_MINUTES)) * 60;
        $minutes = max(1, (int) round($past / 60));

        $this->notify($server, $alert->kind, new MonitorRecoveredNotification($server, $alert->kind, $minutes));
        activity()->performedOn($server)->withProperties(['kind' => $alert->kind->value, 'minutes' => $minutes])->log('monitor_recovered');
        FleetChanged::forServer($server);
    }

    /**
     * @param  Collection<int, ServerMetric>  $readings
     * @return list<float>|null
     */
    private function values(Server $server, MonitorKind $kind, int $minutes, ServerMetric $metric, Collection $readings): ?array
    {
        $cores = self::cores($server);

        if ($kind === MonitorKind::Load && $cores < 1) {
            return null;
        }

        if ($minutes === 0) {
            return [self::value($kind, $metric, $cores)];
        }

        $start = $metric->recorded_at->subMinutes($minutes);
        $window = $readings->filter(fn(ServerMetric $reading): bool => $reading->recorded_at->gte($start->subSeconds(self::LOOKBACK_SECONDS)));
        $first = $window->min(fn(ServerMetric $reading): int => $reading->recorded_at->getTimestamp());

        if ($first === null || $first > $start->getTimestamp() + self::DRIFT_SECONDS || $window->count() < $minutes) {
            return null;
        }

        return $window->map(fn(ServerMetric $reading): float => self::value($kind, $reading, $cores))->values()->all();
    }

    private static function value(MonitorKind $kind, ServerMetric $reading, int $cores): float
    {
        return match ($kind) {
            MonitorKind::Disk => (float) $reading->disk_percent,
            MonitorKind::Memory => (float) $reading->memory_percent,
            MonitorKind::Cpu => (float) $reading->cpu_percent,
            MonitorKind::Load => (float) $reading->load_5m / $cores,
            MonitorKind::Apps, MonitorKind::Cron => 0.0,
        };
    }

    private static function cores(Server $server): int
    {
        return (int) (((array) $server->meta)['cpu_cores'] ?? 0);
    }

    private function notify(Server $server, MonitorKind $kind, Notification $notification): bool
    {
        try {
            app(TeamNotifier::class)->send($server->team, $kind === MonitorKind::Disk ? Preferences::DISK_PRESSURE : Preferences::SERVER_ALERTS, $notification, about: $server);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
