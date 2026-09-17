<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditSeverity;
use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\Server\Audit\AuditFinding;
use App\Services\Server\Audit\PreProvisioningAudit;
use App\Services\Server\Audit\ServerAudit;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use phpseclib3\Exception\UnableToConnectException;
use Throwable;

final class AuditServer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('default');
    }

    public static function begin(Server $server): void
    {
        ServerAudit::start($server);

        self::dispatch($server);
    }

    public function handle(SSHConnectionManager $connections, PreProvisioningAudit $audit): void
    {
        try {
            $report = $audit->run($connections->for($this->server));

            ServerAudit::finish($this->server, $report);

            activity()
                ->performedOn($this->server)
                ->withProperties([
                    'blockers' => count(array_filter($report->findings, fn(AuditFinding $finding): bool => $finding->severity === AuditSeverity::Blocker)),
                    'warnings' => count(array_filter($report->findings, fn(AuditFinding $finding): bool => $finding->severity === AuditSeverity::Warning)),
                ])
                ->log('server_audited');
        } catch (Throwable $e) {
            ServerAudit::fail($this->server, self::explain($this->server, $e));

            throw $e;
        } finally {
            $connections->disconnect($this->server);
        }
    }

    public function failed(Throwable $e): void
    {
        ServerAudit::fail($this->server, self::explain($this->server, $e));
    }

    private const SUDO_REFUSALS = [
        'a terminal is required',
        'a password is required',
        'no tty present',
        'is not in the sudoers file',
        'sudo: command not found',
    ];

    public static function explain(Server $server, Throwable $e): string
    {
        return match (true) {
            $e instanceof HostKeyChangedException => 'The server presented a different host key. Review it on this page before checking again.',
            $e instanceof SSHAuthenticationException => "Coresmith could not sign in as {$server->ssh_user}. Check the public key above is in ~/.ssh/authorized_keys, then check again.",
            $e instanceof UnableToConnectException => "Coresmith could not reach {$server->ip_address} on port {$server->ssh_port}. Check the server is running and the port is open, then check again.",
            $e instanceof SSHCommandFailedException && Str::contains($e->output(), self::SUDO_REFUSALS) => "Coresmith could not run the check as root on {$server->name}. Give {$server->ssh_user} passwordless sudo, or connect as root, then check again.",
            $e instanceof SSHCommandFailedException && trim($e->output()) !== '' => 'The check stopped: ' . Str::limit(trim($e->output()), 300) . ' Fix that on the server, then check again.',
            default => 'The check stopped with an error: ' . Str::limit(trim($e->getMessage()), 300) . ' Check again, and if it keeps happening check the server.',
        };
    }
}
