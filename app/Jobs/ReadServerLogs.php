<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Logs\LogQuery;
use App\Services\Logs\ServerLogs;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ReadServerLogs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 30;

    public function __construct(private readonly Server $server, private readonly LogQuery $query)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->server->getKey() . ':' . $this->query->key();
    }

    public static function cacheKey(Server $server, LogQuery $query): string
    {
        return 'server-logs:' . $server->getKey() . ':' . $query->key();
    }

    public function handle(SSHConnectionManager $connections, ServerLogs $logs): void
    {
        $key = self::cacheKey($this->server, $this->query);

        try {
            $connection = $connections->for($this->server);

            try {
                $read = $logs->read($connection, $this->query);
            } finally {
                $connections->disconnect($this->server);
            }

            Cache::put($key, [...$read, 'error' => null, 'read_at' => now()->timestamp], ReadAppLogs::TTL_SECONDS);
        } catch (Throwable) {
            $previous = Cache::get($key);

            Cache::put($key, [
                'entries' => is_array($previous) ? ($previous['entries'] ?? []) : [],
                'stats' => is_array($previous) ? ($previous['stats'] ?? null) : null,
                'capped' => is_array($previous) && ($previous['capped'] ?? false),
                'error' => "Couldn't reach {$this->server->name} to read the logs. Coresmith tries again in a moment.",
                'read_at' => now()->timestamp,
            ], ReadAppLogs::TTL_SECONDS);
        }
    }
}
