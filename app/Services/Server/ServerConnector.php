<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\ServerStatus;
use App\Exceptions\HostKeyChangedException;
use App\Exceptions\ServerConnectionFailed;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\AuditServer;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Str;
use Throwable;

final class ServerConnector
{
    public const COMMAND_SECONDS = 30;

    private const HOST_KEY_CHANGED = "The server's host key changed since Coresmith first saw it. Review the change before connecting again.";

    public function __construct(private readonly SSHConnectionManager $connections) {}

    public function withKey(Server $server): void
    {
        $this->verify($server, "The server refused Coresmith's key for {$server->ssh_user}. Run the command below on the server, then test the connection again.");
    }

    private function verify(Server $server, string $keyRefused): void
    {
        $connection = $this->connections->fresh($server);

        try {
            $connection->execute('whoami', timeout: self::COMMAND_SECONDS)->throw();
            $connection->execute('uname -a', timeout: self::COMMAND_SECONDS)->throw();
        } catch (Throwable $e) {
            throw $this->failed($server, match (true) {
                $e instanceof SSHAuthenticationException => $keyRefused,
                $e instanceof SSHCommandFailedException => "Coresmith signed in, but a test command failed with exit code {$e->exitCode()}. Try again, and check the server if it keeps failing.",
                default => $this->reason($server, $e),
            }, $e);
        } finally {
            $connection->disconnect();
        }

        $server->forceFill(['last_connected_at' => now(), 'last_connection_error' => null])->save();

        if ($server->status === ServerStatus::Pending) {
            AuditServer::begin($server);
        }
    }

    private function reason(Server $server, Throwable $e): string
    {
        if ($e instanceof HostKeyChangedException) {
            return self::HOST_KEY_CHANGED;
        }

        if (!ServerReachability::isConnectionFailure($e)) {
            throw $e;
        }

        return ServerReachability::reason($server, $e);
    }

    private function failed(Server $server, string $reason, ?Throwable $previous = null): ServerConnectionFailed
    {
        $server->forceFill(['last_connection_error' => Str::limit($reason, 252)])->save();

        return new ServerConnectionFailed($reason, previous: $previous);
    }
}
