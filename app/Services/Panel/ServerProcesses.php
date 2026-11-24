<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\PowerState;
use App\Models\Application;
use App\Models\AppProcess;
use App\Models\Server;
use App\Models\User;
use App\Services\Teams\TeamAccess;

final class ServerProcesses
{
    public const RESTARTS_WARNING = 3;

    public function __construct(private readonly TeamAccess $access) {}

    /**
     * @return list<array{app: string, href: string, process: string, state: array{key: string, label: string, tone: string}, memory: string|null, restarts: int, since: string|null}>
     */
    public function for(Server $server, User $user): array
    {
        $applications = $this->access->applicationsOn($user, $server)->get()->keyBy(fn(Application $application): string => (string) $application->getKey());

        $rows = AppProcess::query()
            ->whereIn('application_id', $applications->keys()->all())
            ->get()
            ->map(function (AppProcess $process) use ($server, $applications): array {
                $application = $applications->get((string) $process->application_id);
                $restarts = $process->restartsToday();
                $state = $this->state($application, $process, $restarts);

                return [
                    'app' => $application->name,
                    'href' => route('servers.applications.show', [$server, $application]),
                    'process' => $process->process,
                    'state' => $state,
                    'memory' => $process->memory_bytes === null ? null : Formats::size(intdiv($process->memory_bytes, 1048576)),
                    'restarts' => $restarts,
                    'since' => $process->active_state === 'active' ? Formats::when($process->started_at) : null,
                    'rank' => match ($state['key']) {
                        'failed' => 0,
                        'restarting' => 1,
                        default => 2,
                    },
                ];
            })
            ->sortBy([
                fn(array $a, array $b): int => $a['rank'] <=> $b['rank'],
                fn(array $a, array $b): int => $b['restarts'] <=> $a['restarts'],
                fn(array $a, array $b): int => $a['app'] <=> $b['app'],
                fn(array $a, array $b): int => $a['process'] <=> $b['process'],
            ])
            ->values();

        return $rows->map(fn(array $row): array => array_diff_key($row, ['rank' => true]))->all();
    }

    /**
     * @return list<array{process: string, state: array{key: string, label: string, tone: string}, memory: string|null, restarts: int, since: string|null}>
     */
    public function ofApplication(Application $application): array
    {
        return AppProcess::query()
            ->where('application_id', $application->getKey())
            ->orderBy('process')
            ->get()
            ->map(function (AppProcess $process) use ($application): array {
                $restarts = $process->restartsToday();

                return [
                    'process' => $process->process,
                    'state' => $this->state($application, $process, $restarts),
                    'memory' => $process->memory_bytes === null ? null : Formats::size(intdiv($process->memory_bytes, 1048576)),
                    'restarts' => $restarts,
                    'since' => $process->active_state === 'active' ? Formats::when($process->started_at) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, label: string, tone: string}
     */
    private function state(Application $application, AppProcess $process, int $restarts): array
    {
        return match (true) {
            $process->active_state === 'failed' => ['key' => 'failed', 'label' => 'Failed', 'tone' => 'destructive'],
            $restarts > self::RESTARTS_WARNING => ['key' => 'restarting', 'label' => "Restarted {$restarts} times today", 'tone' => 'warning'],
            $process->sub_state === 'auto-restart' => ['key' => 'restarting', 'label' => 'Crashed, restarting', 'tone' => 'warning'],
            in_array($process->active_state, ['activating', 'reloading'], true) => ['key' => 'starting', 'label' => 'Starting', 'tone' => 'accent'],
            $process->active_state === 'active' => ['key' => 'running', 'label' => 'Running', 'tone' => 'success'],
            $application->power_state === PowerState::Sleeping => ['key' => 'asleep', 'label' => 'Asleep', 'tone' => 'default'],
            $application->power_state === PowerState::Stopped => ['key' => 'stopped', 'label' => 'Stopped', 'tone' => 'default'],
            default => ['key' => 'stopped', 'label' => 'Stopped', 'tone' => 'warning'],
        };
    }
}
