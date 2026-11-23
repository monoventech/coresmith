<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\Edition;

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
}
