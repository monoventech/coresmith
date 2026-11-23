<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\Edition;
use App\Models\Team;

final class MoveSide
{
    public static function label(Edition $edition): string
    {
        return $edition->isCloud() ? 'Coresmith Cloud' : 'self-hosted Coresmith';
    }

    public static function destination(): string
    {
        return Edition::current()->isCloud() ? 'your self-hosted Coresmith' : 'the Coresmith the team is moving to';
    }

    public static function origin(): string
    {
        return Edition::current()->isCloud() ? 'your self-hosted Coresmith' : 'the Coresmith the team is leaving';
    }

    public static function to(Team $team): string
    {
        if ($team->moving_edition === Edition::Cloud->value) {
            return self::label(Edition::Cloud);
        }

        $host = parse_url((string) $team->moving_to, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'the Coresmith the team is moving to';
    }

    public static function towards(Team $team): string
    {
        return ($team->moving_edition === Edition::Cloud->value ? 'in ' : 'on ') . self::to($team);
    }
}
