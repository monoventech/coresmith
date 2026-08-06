<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\PowerState;
use App\Jobs\WakeApplication;
use App\Models\Application;
use App\Models\ServerMetric;
use App\Services\SSH\SSHConnection;

final class MetricsCollector
{
    public const ACCESS_LOG = '/var/log/caddy/access.log';

    public function collect(SSHConnection $connection): ServerMetric
    {
        $output = $connection->execute($this->probe())->throw()->output;
        $values = $this->parse($output);

        return ServerMetric::create([
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

    public function probe(): string
    {
        return implode(' ; ', [
            "top -bn1 | awk '/Cpu\\(s\\)/ {print \"cpu=\" 100 - \$8}'",
            "free -m | awk '/^Mem:/ {printf \"memory=%.1f\\n\", (\$3/\$2)*100}'",
            "df -P / | awk 'NR==2 {gsub(/%/,\"\",\$5); print \"disk=\" \$5}'",
            "awk '{print \"load=\" \$1 \",\" \$2 \",\" \$3}' /proc/loadavg",
            "awk '/^ *(eth|ens|enp|eno)[0-9]/ {rx+=\$2; tx+=\$10} END {print \"net=\" rx+0 \",\" tx+0}' /proc/net/dev",
        ]);
    }

    /**
     * @return array{cpu: float, memory: float, disk: float, load1: float, load5: float, load15: float, rx: ?int, tx: ?int}
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

        return [
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'load1' => $load[0],
            'load5' => $load[1],
            'load15' => $load[2],
            'rx' => $rx,
            'tx' => $tx,
        ];
    }

    private function match(string $pattern, string $output): float
    {
        return preg_match($pattern, $output, $matches) === 1 ? (float) $matches[1] : 0.0;
    }
}
