<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Models\Application;
use App\Models\AppProcess;
use App\Models\Server;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Throwable;

final class ProcessStates
{
    public const PROPERTIES = 'Id,LoadState,ActiveState,SubState,NRestarts,MemoryCurrent,ActiveEnterTimestamp';

    public const KEEP_RESTARTS = 200;

    public const SECONDS = 15;

    public function __construct(private readonly ProcessManager $processes) {}

    public function read(SSHConnection $connection): void
    {
        $server = $connection->server();
        $units = $server->applications()->get()
            ->flatMap(fn(Application $application): array => $this->unitsOf($application))
            ->map(fn(string $unit): string => escapeshellarg($unit . '.service'))
            ->implode(' ');

        if ($units === '') {
            $this->record($server, []);

            return;
        }

        $output = $connection->execute('LC_ALL=C systemctl show --property=' . self::PROPERTIES . ' ' . $units . ' 2>/dev/null || true', timeout: self::SECONDS)->output;
        $readings = self::parse($output);

        if ($readings !== []) {
            $this->record($server, $readings);
        }
    }

    /**
     * @return list<array{unit: string, active: string, sub: string, restarts: int, memory: int|null, since: int|null}>
     */
    public static function parse(string $output): array
    {
        $readings = [];

        foreach (preg_split('/(?:\r\n|\n|\r){2,}/', trim($output)) ?: [] as $block) {
            $values = [];

            foreach (preg_split('/\r\n|\n|\r/', $block) ?: [] as $line) {
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $values[trim($key)] = trim($value);
            }

            $id = $values['Id'] ?? '';

            if (!str_starts_with($id, 'coresmith-app-') || !str_ends_with($id, '.service') || ($values['LoadState'] ?? 'loaded') === 'not-found') {
                continue;
            }

            $readings[] = [
                'unit' => substr($id, 0, -strlen('.service')),
                'active' => $values['ActiveState'] ?? 'unknown',
                'sub' => $values['SubState'] ?? 'unknown',
                'restarts' => (int) ($values['NRestarts'] ?? 0),
                'memory' => ctype_digit($values['MemoryCurrent'] ?? '') ? (int) $values['MemoryCurrent'] : null,
                'since' => self::moment($values['ActiveEnterTimestamp'] ?? ''),
            ];
        }

        return $readings;
    }

    /**
     * @param  list<array{unit: string, active: string, sub: string, restarts: int, memory: int|null, since: int|null}>  $readings
     */
    public function record(Server $server, array $readings): void
    {
        $applications = $server->applications()->get()->sortByDesc(fn(Application $application): int => strlen($application->slug))->values();
        $now = now();
        $kept = [];

        foreach ($readings as $reading) {
            $application = $applications->first(fn(Application $candidate): bool => str_starts_with($reading['unit'], 'coresmith-app-' . $candidate->slug . '-'));

            if (!$application instanceof Application) {
                continue;
            }

            $process = substr($reading['unit'], strlen('coresmith-app-' . $application->slug . '-'));
            $row = AppProcess::query()->firstOrNew(['unit' => $reading['unit'], 'application_id' => $application->getKey()]);
            $times = $row->exists ? $this->restartTimes($row, $reading['restarts'], $now->getTimestamp()) : [];

            $row->forceFill([
                'process' => $process === 'fpm' ? 'php' : $process,
                'active_state' => $reading['active'],
                'sub_state' => $reading['sub'],
                'memory_bytes' => $reading['memory'],
                'restarts' => $reading['restarts'],
                'restart_times' => $times,
                'started_at' => $reading['since'] === null ? null : CarbonImmutable::createFromTimestamp($reading['since']),
                'read_at' => $now,
            ])->save();

            $kept[] = $row->getKey();
        }

        AppProcess::query()
            ->whereIn('application_id', $applications->modelKeys())
            ->when($kept !== [], fn($query) => $query->whereNotIn('id', $kept))
            ->delete();
    }

    /**
     * @return list<string>
     */
    private function unitsOf(Application $application): array
    {
        try {
            return $this->processes->unitNames($application);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<int>
     */
    private function restartTimes(AppProcess $row, int $restarts, int $now): array
    {
        $since = $now - AppProcess::DAY_SECONDS;
        $times = array_values(array_filter(array_map('intval', (array) $row->restart_times), fn(int $time): bool => $time > $since));
        $new = $restarts >= $row->restarts ? $restarts - $row->restarts : $restarts;

        return array_slice([...$times, ...array_fill(0, min($new, self::KEEP_RESTARTS), $now)], -self::KEEP_RESTARTS);
    }

    private static function moment(string $value): ?int
    {
        if ($value === '' || $value === 'n/a') {
            return null;
        }

        if (str_starts_with($value, '@')) {
            return (int) substr($value, 1);
        }

        try {
            return CarbonImmutable::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
