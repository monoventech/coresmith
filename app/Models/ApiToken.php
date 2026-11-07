<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IgnoresJsonKeyOrder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class ApiToken extends PersonalAccessToken
{
    use IgnoresJsonKeyOrder;

    protected $table = 'personal_access_tokens';

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
