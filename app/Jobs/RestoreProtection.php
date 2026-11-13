<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use RuntimeException;

final class RestoreProtection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const PROTECTIONS = ['ssh', 'fail2ban', 'kernel', 'patches', 'controls', 'unknown_hosts'];

    public const LEFT_TO_OWNER = ['ssh', 'unknown_hosts'];

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 1300;

    public function __construct(public readonly Server $server, public readonly string $protection)
    {
        $this->onQueue('server-tasks');
    }

    public function uniqueId(): string
    {
        return $this->server->getKey() . ':' . $this->protection;
    }

    public function handle(SSHConnectionManager $connections, SecurityHardening $hardening): void
    {
        try {
            match ($this->protection) {
                'ssh' => HardenSsh::dispatchSync($this->server),
                'controls' => MoveCaddyAdminToSocket::dispatchSync($this->server),
                'unknown_hosts' => CloseUnknownHosts::dispatchSync($this->server),
                default => $this->restore($connections, $hardening),
            };
        } finally {
            $this->settle(fn(array $meta): array => [...$meta, 'protections_restoring' => Arr::except((array) ($meta['protections_restoring'] ?? []), $this->protection)]);
        }

        CheckProtections::dispatchSync($this->server);
    }

    private function restore(SSHConnectionManager $connections, SecurityHardening $hardening): void
    {
        try {
            $hardening->restore($connections->for($this->server), $this->protection);
            $this->settle(fn(array $meta): array => [...$meta, 'protections_errors' => Arr::except((array) ($meta['protections_errors'] ?? []), $this->protection)]);
        } catch (RuntimeException $e) {
            report($e);
            $this->settle(fn(array $meta): array => [...$meta, 'protections_errors' => [...(array) ($meta['protections_errors'] ?? []), $this->protection => SSHCommandFailedException::reason($e)]]);
        } finally {
            $connections->disconnect($this->server);
        }
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function settle(callable $change): void
    {
        $server = $this->server->fresh() ?? $this->server;
        $server->forceFill(['meta' => $change((array) $server->meta)])->save();
    }
}
