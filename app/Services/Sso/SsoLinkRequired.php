<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\Team;
use RuntimeException;

final class SsoLinkRequired extends RuntimeException
{
    /**
     * @param  array{team: string, issuer: string, connection_issuer: string, subject: string, email: string}  $link
     */
    public function __construct(public readonly Team $team, public readonly array $link)
    {
        parent::__construct('An existing account needs its password before single sign-on can use it.');
    }
}
