<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\TerminalSession;
use App\Models\User;

final class TerminalChannel
{
    public function join(User $user, string $terminal): bool
    {
        return TerminalSession::query()->whereKey($terminal)->where('user_id', $user->id)->exists();
    }
}
