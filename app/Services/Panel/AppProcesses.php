<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\PowerState;
use App\Models\Application;
use App\Models\User;
use App\Services\Process\ProcessManager;
use App\Services\Runtime\Catalog;
use App\Services\Teams\RouteKeys;

final readonly class AppProcesses
{
    private const SETTINGS = [SmartToggles::AUTO_DEPLOY, SmartToggles::MAINTENANCE];

    public function __construct(private SmartToggles $toggles, private ServerProcesses $live, private ProcessManager $processes, private Catalog $catalog) {}

    /**
     * @return list<array{key: string, label: string, description: string, toggle: ?string, state: array{key: string, label: string, tone: string}|null, memory: ?string, since: ?string, restarts: int, restart_href: ?string, log_href: ?string}>
     */
    public function rows(Application $application, User $user): array
    {
        $live = collect($this->live->ofApplication($application))->keyBy('process');
        $names = $this->processes->processNames($application);
        $web = array_values(array_intersect(['php', 'web'], $names))[0] ?? null;
        $rows = [];
        $covered = [];

        if ($web !== null) {
            $rows[] = $this->row($application, $user, 'web', 'Web', $this->serves($application), null, $web, $live->get($web), true);
            $covered[] = $web;
        }

        foreach ($this->toggles->describe($application) as $toggle) {
            if (in_array($toggle['key'], self::SETTINGS, true)) {
                continue;
            }

            $process = $this->toggles->process($toggle['key']);
            $rows[] = $this->row($application, $user, $toggle['key'], $toggle['label'], $toggle['description'], $toggle['key'], $process, $process === null ? null : $live->get($process), (bool) $toggle['on']);

            if ($process !== null) {
                $covered[] = $process;
            }
        }

        foreach ($live as $process => $reading) {
            if (!in_array($process, $covered, true)) {
                $rows[] = $this->row($application, $user, (string) $process, ucfirst((string) $process), '', null, (string) $process, $reading, true);
            }
        }

        return $rows;
    }

    /**
     * @param  array{process: string, state: array{key: string, label: string, tone: string}, memory: ?string, restarts: int, since: ?string}|null  $reading
     * @return array{key: string, label: string, description: string, toggle: ?string, state: array{key: string, label: string, tone: string}|null, memory: ?string, since: ?string, restarts: int, restart_href: ?string, log_href: ?string}
     */
    private function row(Application $application, User $user, string $key, string $label, string $description, ?string $toggle, ?string $process, ?array $reading, bool $on): array
    {
        $server = app(RouteKeys::class)->server($application->server_id);
        $running = $on && $reading !== null;
        $awake = !in_array($application->power_state, [PowerState::Stopped, PowerState::Sleeping], true);

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'toggle' => $toggle,
            'state' => $running ? $reading['state'] : ($on && $process === null ? ['key' => 'scheduled', 'label' => 'Runs every minute', 'tone' => 'success'] : ($on ? null : ['key' => 'off', 'label' => 'Off', 'tone' => 'default'])),
            'memory' => $running ? $reading['memory'] : null,
            'since' => $running ? $reading['since'] : null,
            'restarts' => $running ? $reading['restarts'] : 0,
            'restart_href' => $running && $awake && $user->can('update', $application) ? route('servers.applications.processes.restart', [$server, $application, $process]) : null,
            'log_href' => $running && $user->can('viewLogs', $application) ? route('servers.applications.logs', [$server, $application, 'sources' => ["process:{$process}"]]) : null,
        ];
    }

    private function serves(Application $application): string
    {
        $runtime = $application->type?->runtime($application->js_runtime);
        $version = $runtime === null ? null : ($application->runtimeVersions()[$runtime] ?? null);

        return $runtime === null || $version === null ? 'Serves requests' : 'Serves requests with ' . $this->catalog->label($runtime) . " {$version}";
    }
}
