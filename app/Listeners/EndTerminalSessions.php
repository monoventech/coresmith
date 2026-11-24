<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TerminalEndReason;
use App\Models\User;
use App\Services\Terminal\TerminalSessions;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

final class EndTerminalSessions
{
    public function __construct(private readonly TerminalSessions $sessions) {}

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->sessions->endFor($event->user, TerminalEndReason::SignedOut);
        }
    }

    public function handleOtherDeviceLogout(OtherDeviceLogout $event): void
    {
        if ($event->user instanceof User) {
            $this->sessions->endFor($event->user, TerminalEndReason::SignedOut);
        }
    }

    public function handleTwoFactorOff(TwoFactorAuthenticationDisabled $event): void
    {
        if ($event->user instanceof User) {
            $this->sessions->endFor($event->user, TerminalEndReason::TwoFactorOff);
        }
    }
}
