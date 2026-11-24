<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum TerminalEndReason: string
{
    case Ended = 'ended';
    case Idle = 'idle';
    case Hour = 'hour';
    case Left = 'left';
    case SignedOut = 'signed_out';
    case PasswordChanged = 'password_changed';
    case TwoFactorOff = 'two_factor_off';
    case RoleChanged = 'role_changed';
    case LeftTeam = 'left_team';
    case RootOff = 'root_off';
    case ServerUnavailable = 'server_unavailable';
    case Updated = 'updated';
    case Failed = 'failed';

    public function sentence(): string
    {
        return match ($this) {
            self::Ended => 'You ended it.',
            self::Idle => 'It ended after 10 minutes without typing.',
            self::Hour => 'It ended after an hour, the longest a session stays open.',
            self::Left => 'It ended when the page stopped checking in, because the tab closed, the computer slept or the connection dropped.',
            self::SignedOut => 'It ended when you signed out.',
            self::PasswordChanged => 'It ended when your password changed.',
            self::TwoFactorOff => 'It ended when two-factor sign-in was turned off.',
            self::RoleChanged => 'It ended when your role in the team changed.',
            self::LeftTeam => 'It ended when you left the team.',
            self::RootOff => 'It ended when an owner turned Root terminals off.',
            self::ServerUnavailable => 'It ended when the server stopped being available.',
            self::Updated => 'It ended while Coresmith updated itself.',
            self::Failed => "It couldn't open a shell on the server.",
        };
    }
}
