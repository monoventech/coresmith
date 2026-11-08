<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Exceptions\HostKeyChangedException;
use App\Exceptions\ServerHasNoAddress;
use App\Exceptions\SSHAuthenticationException;
use App\Models\Server;
use App\Services\SafeName;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\KeyPairGenerator;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\UnableToConnectException;
use Throwable;

final class TakeOverServer implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly Server $server)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $ssh, KeyPairGenerator $keys, SecurityHardening $hardening): void
    {
        $server = $this->server->fresh();

        if ($server === null || $server->status !== ServerStatus::Moving) {
            return;
        }

        $state = (array) $server->takeover;
        $attempts = (int) ($state['attempts'] ?? 0) + 1;
        $cloudKey = (string) ($state['cloud_key'] ?? $server->ssh_public_key);
        $server->forceFill(['takeover' => [...$state, 'state' => 'running', 'error' => null, 'attempts' => $attempts]])->save();

        $pair = $keys->generate('coresmith');
        $user = '';
        $old = null;
        $added = false;

        try {
            $user = SafeName::assertSystemUser((string) $server->ssh_user);
            $old = $ssh->for($server)->connect();
            $old->execute($this->addKey($user, $pair->publicKey))->throw();
            $added = true;

            $probe = $ssh->probe($server, $pair->privateKey)->connect();
            $hardening->syncUserKeys($probe);
            $probe->execute($this->removeKey($user, $cloudKey))->throw();
            $probe->disconnect();
        } catch (Throwable $e) {
            $this->undo($old, $added, $user, $pair->publicKey);
            $ssh->disconnect($server);

            $failed = $server->fresh() ?? $server;
            $failed->forceFill(['takeover' => [...$state, 'state' => 'failed', 'error' => $this->reason($e, $server, $added), 'attempts' => $attempts]])->save();

            return;
        }

        $ssh->disconnect($server);

        $server->forceFill([
            'ssh_private_key' => $pair->privateKey,
            'ssh_public_key' => $pair->publicKey,
            'status' => ServerStatus::Active,
            'last_connected_at' => now(),
            'takeover' => [...$state, 'state' => 'moved', 'error' => null, 'attempts' => $attempts, 'moved_at' => now()->toIso8601String()],
        ])->save();

        activity()->performedOn($server)->log('server_taken_over');
    }

    private function addKey(string $user, string $publicKey): string
    {
        [$directory, $file] = $this->paths($user);
        $key = escapeshellarg(trim($publicKey));

        return implode(' && ', [
            "mkdir -p {$directory}",
            "touch {$file}",
            "{ grep -qxF -- {$key} {$file} || printf '%s\\n' {$key} >> {$file}; }",
            "chmod 600 {$file}",
            "chmod 700 {$directory}",
        ]);
    }

    private function removeKey(string $user, string $publicKey): string
    {
        [, $file] = $this->paths($user);
        $blob = escapeshellarg(explode(' ', trim($publicKey))[1] ?? '');

        return implode(' && ', [
            "tmp=\$(mktemp {$file}.XXXXXX)",
            "{ awk -v blob={$blob} '{for (i = 1; i <= NF; i++) if (\$i == blob) next} {print}' {$file} > \"\$tmp\" && chmod 600 \"\$tmp\" && mv -f \"\$tmp\" {$file} || { rm -f \"\$tmp\"; false; }; }",
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function paths(string $user): array
    {
        $home = $user === 'root' ? '/root' : '/home/' . $user;

        return [escapeshellarg($home . '/.ssh'), escapeshellarg($home . '/.ssh/authorized_keys')];
    }

    private function undo(?SSHConnection $old, bool $added, string $user, string $publicKey): void
    {
        if (!$added || $old === null) {
            return;
        }

        try {
            $old->execute($this->removeKey($user, $publicKey));
        } catch (Throwable) {
            return;
        }
    }

    private function reason(Throwable $e, Server $server, bool $added): string
    {
        $name = $server->name;

        return match (true) {
            $e instanceof HostKeyChangedException => "{$name} showed a different host key than Coresmith Cloud recorded, so nothing was changed. Check it's the right server, accept or reject the new key, then try again.",
            $e instanceof SSHAuthenticationException && $added => "{$name} didn't accept Coresmith's new key, so the move put its keys back as they were. Try again.",
            $e instanceof SSHAuthenticationException => "Coresmith Cloud's key no longer opens {$name}, so the move can't take it over. Add the server here again instead.",
            $e instanceof UnableToConnectException, $e instanceof ConnectionClosedException, $e instanceof ServerHasNoAddress => "Coresmith couldn't reach {$name} at {$server->ip_address}:{$server->ssh_port}. Check it's on and reachable, then try again.",
            default => "Taking over {$name} stopped: {$e->getMessage()} Try again.",
        };
    }
}
