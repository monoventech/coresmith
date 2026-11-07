<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Services\Server\AccessLogReader;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ReadAccessLog implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public const TTL_SECONDS = 120;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 15;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->application->getKey();
    }

    public static function cacheKey(Application $application): string
    {
        return 'access-log:' . $application->getKey();
    }

    public function handle(SSHConnectionManager $connections, AccessLogReader $reader): void
    {
        $server = $this->application->server;

        try {
            $connection = $connections->for($server);

            try {
                $lines = $reader->recent($connection, $this->application);
            } finally {
                $connections->disconnect($server);
            }

            Cache::put(self::cacheKey($this->application), ['lines' => $lines, 'error' => null, 'read_at' => now()->timestamp], self::TTL_SECONDS);
        } catch (Throwable) {
            Cache::put(self::cacheKey($this->application), [
                'lines' => [],
                'error' => "Couldn't reach {$server->name} to read the access log.",
                'read_at' => now()->timestamp,
            ], self::TTL_SECONDS);
        }
    }
}
