<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class SyncAuthorizedKeys implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public const LOCK_WAIT_SECONDS = 30;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('server-tasks');
    }

    /**
     * @param  iterable<int, string>  $teamIds
     * @return Collection<int, Server>
     */
    public static function activeServersOf(iterable $teamIds): Collection
    {
        return Server::query()
            ->whereIn('team_id', collect($teamIds)->all())
            ->where('status', ServerStatus::Active->value)
            ->get();
    }

    /**
     * @param  iterable<int, Server>  $servers
     */
    public static function dispatchForServers(iterable $servers): void
    {
        foreach ($servers as $server) {
            self::dispatch($server);
        }
    }

    public static function lockKey(Server $server): string
    {
        return 'authorized-keys-sync:' . $server->getKey();
    }

    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    public function handle(SSHConnectionManager $connections, SecurityHardening $hardening): void
    {
        if ($this->server->status !== ServerStatus::Active || $this->server->pending_host_key_fingerprint !== null || $this->server->trashed()) {
            return;
        }

        $count = Cache::lock(self::lockKey($this->server), $this->timeout)->block(self::LOCK_WAIT_SECONDS, function () use ($connections, $hardening): int {
            $connection = $connections->for($this->server);

            try {
                return $hardening->syncUserKeys($connection);
            } finally {
                $connections->disconnect($this->server);
            }
        });

        activity()
            ->performedOn($this->server)
            ->withProperties(['keys' => $count])
            ->log('authorized_keys_synced');

        if ($count > 0 && $this->server->runs_coresmith && $this->server->fresh()?->sshPasswordsKept()) {
            HardenSsh::dispatch($this->server);
        }
    }
}
