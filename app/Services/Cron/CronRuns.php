<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cron;

use App\Jobs\SyncApplicationCron;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Panel\CronJobs;
use App\Services\SSH\SSHConnection;
use DateTimeZone;
use Illuminate\Support\Collection;

final class CronRuns
{
    public const LINES = 5;

    public const SECONDS = 20;

    public function __construct(private readonly CronJobs $jobs, private readonly CrontabBuilder $crontab) {}

    public static function command(): string
    {
        $root = CrontabBuilder::RECORDS;
        $quoted = escapeshellarg($root);

        return implode(' ', [
            "for record in {$quoted}/*/*.runs; do [ -e \"\$record\" ] || continue; printf '== %s\\n' \"\${record#{$root}/}\"; tail -n " . self::LINES . ' "$record"; done;',
            "for marker in {$quoted}/*/*.running; do [ -e \"\$marker\" ] || continue; pid=\${marker%.running}; pid=\${pid##*.};",
            "if [ -d \"/proc/\$pid\" ]; then printf '== %s\\n' \"\${marker#{$root}/}\"; head -n 1 \"\$marker\"; else rm -f \"\$marker\"; fi; done;",
            'zone=$(timedatectl show -p Timezone --value 2>/dev/null); [ -n "$zone" ] || zone=$(cat /etc/timezone 2>/dev/null);',
            "printf '== clock %s %s\\n' \"\$(date +%s)\" \"\${zone:-UTC}\"",
        ]);
    }

    /**
     * @return array{runs: array<string, array<string, list<array{started: int, seconds: int, exit: int}>>>, running: array<string, array<string, int>>, clock: array{now: int, timezone: string}|null}
     */
    public static function parse(string $output): array
    {
        $parsed = ['runs' => [], 'running' => [], 'clock' => null];
        $current = null;

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('#^== ([a-z0-9-]+)/([0-9a-f]{16})\.runs$#D', $line, $matches) === 1) {
                $current = ['runs', $matches[1], $matches[2]];

                continue;
            }

            if (preg_match('#^== ([a-z0-9-]+)/([0-9a-f]{16})\.\d+\.running$#D', $line, $matches) === 1) {
                $current = ['running', $matches[1], $matches[2]];

                continue;
            }

            if (preg_match('#^== clock (\d{1,12}) ([A-Za-z0-9_+/-]{1,64})$#D', $line, $matches) === 1) {
                $parsed['clock'] = ['now' => (int) $matches[1], 'timezone' => self::timezone($matches[2])];
                $current = null;

                continue;
            }

            if (str_starts_with($line, '== ')) {
                $current = null;
            }

            if ($current === null) {
                continue;
            }

            [$kind, $slug, $id] = $current;

            if ($kind === 'runs' && preg_match('/^(\d{1,12}) (\d{1,9}) (\d{1,3})$/D', $line, $matches) === 1) {
                $parsed['runs'][$slug][$id][] = ['started' => (int) $matches[1], 'seconds' => (int) $matches[2], 'exit' => (int) $matches[3]];
            }

            if ($kind === 'running' && preg_match('/^(\d{1,12})$/D', $line, $matches) === 1) {
                $parsed['running'][$slug][$id] = max((int) $matches[1], $parsed['running'][$slug][$id] ?? 0);
            }
        }

        return $parsed;
    }

    /**
     * @param  Collection<int, Application>  $applications
     */
    public function read(SSHConnection $connection, Collection $applications): void
    {
        $watched = $applications->filter(fn(Application $application): bool => $this->jobs->entries($application) !== []);

        if ($watched->isEmpty()) {
            return;
        }

        $parsed = self::parse($connection->execute(self::command(), sudo: true, timeout: self::SECONDS)->throw()->output);

        $server = $connection->server();
        $now = $parsed['clock']['now'] ?? now()->getTimestamp();
        $timezone = $parsed['clock']['timezone'] ?? (string) (((array) $server->meta)['timezone'] ?? 'UTC');

        if ($parsed['clock'] !== null) {
            $this->remember($server, $timezone);
        }

        foreach ($watched as $application) {
            if ($application->cron_written_at === null && $this->crontab->runs($application)) {
                SyncApplicationCron::dispatch($application);
            }

            $this->record($application, $parsed['runs'][$application->slug] ?? [], $parsed['running'][$application->slug] ?? [], $now);
            app(CronMonitor::class)->evaluate($server, $application, $now, $timezone);
        }
    }

    /**
     * @param  array<string, list<array{started: int, seconds: int, exit: int}>>  $runs
     * @param  array<string, int>  $running
     */
    public function record(Application $application, array $runs, array $running = [], ?int $now = null): void
    {
        $now ??= now()->getTimestamp();
        $before = (array) $application->cron_runs;
        $state = [];

        foreach ($this->jobs->entries($application) as $entry) {
            $id = $entry['id'];
            $last = ($runs[$id] ?? []) === [] ? null : $runs[$id][array_key_last($runs[$id])];

            $state[$id] = [
                'seen' => (int) ($before[$id]['seen'] ?? $now),
                'last' => $last ?? ($before[$id]['last'] ?? null),
                'running' => $running[$id] ?? null,
            ];
        }

        if ($state != $before) {
            $encoded = $state === [] ? null : json_encode($state, JSON_THROW_ON_ERROR);

            Application::query()->whereKey($application->getKey())->toBase()->update(['cron_runs' => $encoded]);
            $application->setRawAttributes([...$application->getAttributes(), 'cron_runs' => $encoded], true);
        }
    }

    private function remember(Server $server, string $timezone): void
    {
        $meta = (array) $server->meta;

        if (($meta['timezone'] ?? null) !== $timezone) {
            $server->forceFill(['meta' => [...$meta, 'timezone' => $timezone]])->save();
        }
    }

    private static function timezone(string $name): string
    {
        return in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) ? $name : 'UTC';
    }
}
