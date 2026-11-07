<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

final class TeamNotifier
{
    public function send(?Team $team, string $preference, Notification $notification, ?User $except = null): void
    {
        if ($team === null) {
            return;
        }

        $recipients = $team->members()->get()->filter(
            fn(User $member): bool => Preferences::wants($member, $preference) && ($except === null || $member->isNot($except)),
        );

        if ($recipients->isNotEmpty()) {
            Notifications::send($recipients, $notification);
        }
    }
}
