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
     * @return array<string, array{label: string, tone: string}>
     */
    public function summaries(Server $server, User $user): array
    {
        $applications = $this->access->applicationsOn($user, $server)->orderBy('name')->get();
        $processes = AppProcess::query()->whereIn('application_id', $applications->modelKeys())->get()->groupBy('application_id');
        $summaries = [];

        foreach ($applications as $application) {
            $states = collect($processes->get($application->getKey(), []))
                ->map(fn(AppProcess $process): string => $this->state($application, $process, $process->restartsToday())['key']);

            if ($states->isEmpty()) {
                continue;
            }

            $summaries[(string) $application->getKey()] = $this->summary($application, $states->countBy()->all(), $states->count());
        }

        return $summaries;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{label: string, tone: string}
     */
    private function summary(Application $application, array $counts, int $total): array
    {
        $failed = $counts['failed'] ?? 0;
        $restarting = $counts['restarting'] ?? 0;
        $running = $counts['running'] ?? 0;
        $trouble = array_filter([
            $failed > 0 ? "{$failed} failed" : null,
            $restarting > 0 ? $restarting . ($restarting === 1 ? ' keeps restarting' : ' keep restarting') : null,
        ]);

        return match (true) {
            $application->power_state === PowerState::Sleeping => ['label' => 'Sleeping', 'tone' => 'default'],
            $application->power_state === PowerState::Stopped => ['label' => 'Stopped', 'tone' => 'default'],
            $trouble !== [] => ['label' => implode(', ', $trouble), 'tone' => $failed > 0 ? 'destructive' : 'warning'],
            $running === $total => ['label' => "{$running} running", 'tone' => 'default'],
            default => ['label' => "{$running} of {$total} running", 'tone' => 'default'],
        };
    }

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
            $application->power_state === PowerState::Sleeping => ['key' => 'asleep', 'label' => 'Sleeping', 'tone' => 'default'],
            $application->power_state === PowerState::Stopped => ['key' => 'stopped', 'label' => 'Stopped', 'tone' => 'default'],
            default => ['key' => 'stopped', 'label' => 'Stopped', 'tone' => 'warning'],
        };
    }
}
