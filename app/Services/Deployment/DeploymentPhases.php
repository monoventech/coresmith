<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;

final class DeploymentPhases
{
    public const CLONE = 'clone';

    public const DEPENDENCIES = 'deps';

    public const BUILD = 'build';

    public const MIGRATIONS = 'migrate';

    public const HEALTH = 'health';

    public const SWAP = 'swap';

    public const WATCH = 'watch';

    public const STORAGE = 'storage';

    public const ORDER = [self::CLONE, self::DEPENDENCIES, self::BUILD, self::MIGRATIONS, self::HEALTH, self::SWAP];

    public const LABELS = [
        self::CLONE => 'Clone release',
        self::DEPENDENCIES => 'Install dependencies',
        self::BUILD => 'Build assets',
        self::MIGRATIONS => 'Run migrations',
        self::HEALTH => 'Health check',
        self::SWAP => 'Symlink swap',
    ];

    public const PROGRESS = [
        self::CLONE => 'cloning the release',
        self::DEPENDENCIES => 'installing dependencies',
        self::BUILD => 'building assets',
        self::MIGRATIONS => 'running migrations',
        self::HEALTH => 'health check',
        self::SWAP => 'symlink swap',
        self::WATCH => 'watching the processes',
    ];

    private const MARKER = '/^\[(\d{2}):(\d{2}):(\d{2})\] ([a-z]+):/';

    private const END = '/^\[(\d{2}):(\d{2}):(\d{2})\] (deployed\.|deploy failed\b)/';

    /**
     * @return list<array{key: string, label: string, state: string, duration: string}>
     */
    public function states(Deployment $deployment): array
    {
        $log = (string) $deployment->log;
        $status = $deployment->status;
        $starts = $this->starts($log);
        $end = $this->end($log);
        $outlived = $this->outlivedTheRail($log);

        $lastIndex = -1;

        foreach (self::ORDER as $index => $key) {
            if (isset($starts[$key])) {
                $lastIndex = $index;
            }
        }

        $phases = [];

        foreach (self::ORDER as $index => $key) {
            $state = $this->state($index, isset($starts[$key]), $lastIndex, $status, $outlived);

            $phases[] = [
                'key' => $key,
                'label' => self::LABELS[$key],
                'state' => $state,
                'duration' => $this->duration($key, $index, $state, $starts, $end),
            ];
        }

        return $phases;
    }

    public function progress(Deployment $deployment): ?string
    {
        $trailing = $this->trailingMarker((string) $deployment->log);

        if ($deployment->status === DeploymentStatus::Running && $trailing !== null && isset(self::PROGRESS[$trailing])) {
            return self::PROGRESS[$trailing];
        }

        foreach (array_reverse($this->states($deployment)) as $phase) {
            if ($phase['state'] === 'running') {
                return self::PROGRESS[$phase['key']];
            }
        }

        return null;
    }

    private function outlivedTheRail(string $log): bool
    {
        return $this->trailingMarker($log) !== null;
    }

    private function trailingMarker(string $log): ?string
    {
        $last = null;

        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (preg_match(self::MARKER, $line, $matches) === 1) {
                $last = $matches[4];
            }
        }

        return $last !== null && !in_array($last, self::ORDER, true) ? $last : null;
    }

    private function state(int $index, bool $started, int $lastIndex, DeploymentStatus $status, bool $outlived = false): string
    {
        $finished = in_array($status, [DeploymentStatus::Succeeded, DeploymentStatus::Failed, DeploymentStatus::Cancelled], true);

        if ($lastIndex === -1) {
            return match (true) {
                $status === DeploymentStatus::Succeeded => 'done',
                $finished => 'skipped',
                $status === DeploymentStatus::Running && $index === 0 => 'running',
                default => 'pending',
            };
        }

        if ($started) {
            return match (true) {
                $index < $lastIndex, $status === DeploymentStatus::Succeeded, $outlived => 'done',
                $finished => 'failed',
                default => 'running',
            };
        }

        if ($index < $lastIndex || $finished) {
            return 'skipped';
        }

        return 'pending';
    }

    /**
     * @param  array<string, int>  $starts
     */
    private function duration(string $key, int $index, string $state, array $starts, ?int $end): string
    {
        if ($state === 'skipped') {
            return '—';
        }

        if ($state === 'running') {
            return '…';
        }

        if (!in_array($state, ['done', 'failed'], true) || !isset($starts[$key])) {
            return '';
        }

        $next = $end;

        foreach (array_slice(self::ORDER, $index + 1) as $later) {
            if (isset($starts[$later])) {
                $next = $starts[$later];
                break;
            }
        }

        if ($next === null) {
            return '';
        }

        $seconds = $next - $starts[$key];

        if ($seconds < 0) {
            $seconds += 86400;
        }

        return $seconds < 60 ? $seconds . 's' : intdiv($seconds, 60) . 'm ' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
    }

    /**
     * @return array<string, int>
     */
    private function starts(string $log): array
    {
        $starts = [];

        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (preg_match(self::MARKER, $line, $matches) === 1 && in_array($matches[4], self::ORDER, true) && !isset($starts[$matches[4]])) {
                $starts[$matches[4]] = (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
            }
        }

        return $starts;
    }

    private function end(string $log): ?int
    {
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (preg_match(self::END, $line, $matches) === 1) {
                return (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
            }
        }

        return null;
    }
}
