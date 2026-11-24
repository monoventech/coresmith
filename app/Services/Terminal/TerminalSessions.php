<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Enums\TerminalEndReason;
use App\Enums\TerminalMode;
use App\Enums\TerminalStatus;
use App\Events\TerminalEnded;
use App\Models\Team;
use App\Models\TerminalSession;
use App\Models\User;

final class TerminalSessions
{
    public function __construct(private readonly TerminalInbox $inbox) {}

    public function end(TerminalSession $session, TerminalEndReason $reason): bool
    {
        $closed = TerminalSession::query()
            ->whereKey($session->getKey())
            ->whereIn('status', [TerminalStatus::Starting->value, TerminalStatus::Open->value])
            ->update(['status' => TerminalStatus::Closed->value, 'ended_at' => now(), 'end_reason' => $reason->value]);

        if ($closed === 0) {
            return false;
        }

        $session->refresh();
        rescue(fn() => $this->inbox->push($session, ['type' => 'close', 'reason' => $reason->value]), report: false);
        rescue(fn() => event(new TerminalEnded((string) $session->getKey(), $reason)), report: false);

        activity()->performedOn($session->server)->causedBy($session->user)->withProperties([
            'mode' => $session->mode->value,
            'app' => $session->application?->name,
            'reason' => $reason->value,
            'minutes' => $session->started_at === null ? 0 : (int) round($session->started_at->diffInMinutes(now())),
        ])->log('terminal_ended');

        return true;
    }

    public function endFor(User $user, TerminalEndReason $reason, ?Team $team = null): int
    {
        return $this->endAll(TerminalSession::query()->live()->where('user_id', $user->getKey())->when($team !== null, fn($query) => $query->where('team_id', $team?->getKey()))->get()->all(), $reason);
    }

    public function endRoot(Team $team, TerminalEndReason $reason): int
    {
        return $this->endAll(TerminalSession::query()->live()->where('team_id', $team->getKey())->where('mode', TerminalMode::Root->value)->get()->all(), $reason);
    }

    /**
     * @param  list<TerminalSession>  $sessions
     */
    private function endAll(array $sessions, TerminalEndReason $reason): int
    {
        return count(array_filter($sessions, fn(TerminalSession $session): bool => $this->end($session, $reason)));
    }
}
