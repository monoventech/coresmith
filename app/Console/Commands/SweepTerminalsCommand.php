<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Enums\TerminalEndReason;
use App\Enums\TerminalMode;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Services\Terminal\TerminalSessions;
use Illuminate\Console\Command;

final class SweepTerminalsCommand extends Command
{
    public const STALE_SECONDS = 120;

    protected $signature = 'coresmith:sweep-terminals';

    protected $description = 'End terminal sessions whose server, rights or session job are gone';

    public function handle(TerminalSessions $sessions): int
    {
        $ended = 0;

        TerminalSession::query()->live()->with(['server' => fn($query) => $query->withTrashed(), 'server.team', 'user'])->get()->each(function (TerminalSession $session) use ($sessions, &$ended): void {
            $reason = $this->reason($session);

            if ($reason !== null && $sessions->end($session, $reason)) {
                $ended++;
            }
        });

        $this->info("Ended {$ended} " . ($ended === 1 ? 'session' : 'sessions') . '.');

        return self::SUCCESS;
    }

    private function reason(TerminalSession $session): ?TerminalEndReason
    {
        $server = $session->server;
        $user = $session->user;

        return match (true) {
            !$server instanceof Server || !$this->available($server) => TerminalEndReason::ServerUnavailable,
            $user === null => TerminalEndReason::LeftTeam,
            $session->mode === TerminalMode::Root && !$server->team?->root_terminals => TerminalEndReason::RootOff,
            !$user->can($session->mode === TerminalMode::Root ? 'openRootTerminal' : 'openTerminal', $server) => TerminalEndReason::RoleChanged,
            $session->updated_at === null || $session->updated_at->lt(now()->subSeconds(self::STALE_SECONDS)) => TerminalEndReason::Failed,
            default => null,
        };
    }

    private function available(Server $server): bool
    {
        return !$server->trashed()
            && $server->status === ServerStatus::Active
            && $server->team?->moving_at === null
            && $server->unreachable_since === null
            && $server->pending_host_key_fingerprint === null;
    }
}
