<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Models\SshKey;
use App\Models\User;

final class SshKeyPolicy
{
    public function view(User $user, SshKey $sshKey): bool
    {
        return $sshKey->user_id === $user->getKey();
    }

    public function delete(User $user, SshKey $sshKey): bool
    {
        return $sshKey->user_id === $user->getKey();
    }
}
