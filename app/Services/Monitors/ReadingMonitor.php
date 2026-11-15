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
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReadingMonitor
{
    public const KINDS = [MonitorKind::Disk, MonitorKind::Memory, MonitorKind::Cpu, MonitorKind::Load];

    public const RECOVERY_MINUTES = 5;

    public const ACTIVITY = [
        'disk' => 'disk_pressure',
        'memory' => 'memory_pressure',
        'cpu' => 'cpu_pressure',
        'load' => 'load_pressure',
    ];

    public function __construct(private readonly MonitorLimits $limits) {}

    public function evaluate(Server $server, ServerMetric $metric): void
    {
        foreach (self::KINDS as $kind) {
            $this->check($server, $kind, $metric);
        }
    }

    private function check(Server $server, MonitorKind $kind, ServerMetric $metric): void
    {
        $limit = $this->limits->for($server, $kind);
        $open = MonitorAlert::current($server, $kind);

        if (!$limit->enabled) {
            $open?->close();

            return;
        }

        $held = $this->values($server, $kind, $limit->duration, $metric);

        if ($held !== null && min($held) >= $limit->threshold) {
            $open === null ? $this->open($server, $kind, $limit, $held) : $this->hold($server, $open, $held);

            return;
        }

        if ($open === null) {
            return;
        }

        $clear = $this->values($server, $kind, max($limit->duration, self::RECOVERY_MINUTES), $metric);

        if ($clear !== null && max($clear) < $limit->threshold) {
            $this->recover($server, $open);
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function open(Server $server, MonitorKind $kind, MonitorLimit $limit, array $values): void
    {
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
            : new MonitorAlertNotification($server, $kind, $shown, $limit));

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

    private function recover(Server $server, MonitorAlert $alert): void
    {
        if (!$alert->close()) {
            return;
        }

        $minutes = max(1, (int) round($alert->opened_at->diffInMinutes($alert->closed_at)));

        $this->notify($server, $alert->kind, new MonitorRecoveredNotification($server, $alert->kind, $minutes));
        activity()->performedOn($server)->withProperties(['kind' => $alert->kind->value, 'minutes' => $minutes])->log('monitor_recovered');
        FleetChanged::forServer($server);
    }

    /**
     * @return list<float>|null
     */
    private function values(Server $server, MonitorKind $kind, int $minutes, ServerMetric $metric): ?array
    {
        $cores = (int) (((array) $server->meta)['cpu_cores'] ?? 0);

        if ($kind === MonitorKind::Load && $cores < 1) {
            return null;
        }

        $readings = $minutes === 0
            ? collect([$metric])
            : ServerMetric::query()
                ->where('server_id', $server->getKey())
                ->where('recorded_at', '>=', now()->subMinutes($minutes))
                ->get();

        if ($readings->count() < max(1, $minutes - 1)) {
            return null;
        }

        return $readings->map(fn(ServerMetric $reading): float => match ($kind) {
            MonitorKind::Disk => (float) $reading->disk_percent,
            MonitorKind::Memory => (float) $reading->memory_percent,
            MonitorKind::Cpu => (float) $reading->cpu_percent,
            MonitorKind::Load => (float) $reading->load_5m / $cores,
            MonitorKind::Apps => 0.0,
        })->values()->all();
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
