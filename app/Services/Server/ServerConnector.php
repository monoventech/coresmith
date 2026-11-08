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
use App\Exceptions\SSHPasswordRefused;
use App\Jobs\AuditServer;
use App\Models\Server;
use App\Services\Server\Audit\PreProvisioningAudit;
use App\Services\SSH\AuthorizedKeyCommand;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Str;
use Throwable;

final class ServerConnector
{
    public const COMMAND_SECONDS = 30;

    private const HOST_KEY_CHANGED = "The server's host key changed since Coresmith first saw it. Review the change before connecting again.";

    private const NO_SUDO = '~not in the sudoers|may not run sudo|is not allowed to execute|sudo: (?:command )?not found~i';

    public function __construct(private readonly SSHConnectionManager $connections) {}

    public function withKey(Server $server): void
    {
        $this->verify($server, "The server refused Coresmith's key for {$server->ssh_user}. Run the command below on the server, then test the connection again.");
    }

    public function withPassword(Server $server, string $user, #[\SensitiveParameter] string $password): void
    {
        $session = $this->connections->fresh($server);

        try {
            $refusal = $this->install($session->signInWithPassword($user, $password), $server, $user, $password);
        } catch (Throwable $e) {
            throw $this->failed($server, $this->passwordReason($server, $user, $e), $e);
        } finally {
            $session->disconnect();
        }

        if ($refusal !== null) {
            throw $this->failed($server, $refusal);
        }

        $server->forceFill(['ssh_user' => 'root'])->save();

        $this->verify($server, 'Coresmith added its key for root, but the server refused it. Let root sign in with a key (PermitRootLogin prohibit-password), then test the connection.');
    }

    private function install(SSHConnection $session, Server $server, string $user, #[\SensitiveParameter] string $password): ?string
    {
        if (trim($session->execute(PreProvisioningAudit::PANEL_CHECK, timeout: self::COMMAND_SECONDS)->output) !== '') {
            return 'This server runs Coresmith itself. Add a different server for your apps.';
        }

        $script = 'sh -c ' . escapeshellarg(AuthorizedKeyCommand::script('root', (string) $server->ssh_public_key));
        $result = $user === 'root'
            ? $session->execute($script, timeout: self::COMMAND_SECONDS)
            : $session->executeWithInput("sudo -S -p '' {$script}", $password . "\n", self::COMMAND_SECONDS);

        return match (true) {
            $result->successful() => null,
            preg_match(self::NO_SUDO, $result->output) === 1 => "{$user} can't use sudo on this server. Use a user who can, or run the command below as root.",
            default => "Coresmith signed in as {$user} but couldn't add its key for root. Run the command below on the server instead.",
        };
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

    private function passwordReason(Server $server, string $user, Throwable $e): string
    {
        return match (true) {
            $e instanceof SSHPasswordRefused && !$e->passwordsAllowed => "This server doesn't take passwords over SSH. Run the command below on it instead.",
            $e instanceof SSHPasswordRefused && $user === 'root' => 'The server refused the password for root. Many servers only let root sign in with a key: use your own user and password, or run the command below.',
            $e instanceof SSHPasswordRefused => "The server refused the password for {$user}. Check it and try again.",
            default => $this->reason($server, $e),
        };
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
