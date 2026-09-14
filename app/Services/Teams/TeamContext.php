<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\Team;

final class TeamContext
{
    public const HOST = 'host';

    public const PATH = 'path';

    public const USER = 'user';

    private ?Team $team = null;

    private ?string $source = null;

    public function set(?Team $team, string $source): void
    {
        $this->team = $team;
        $this->source = $team === null ? null : $source;
    }

    public function team(): ?Team
    {
        return $this->team;
    }

    public function teamOrFail(): Team
    {
        abort_if($this->team === null, 404);

        return $this->team;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function explicit(): bool
    {
        return in_array($this->source, [self::HOST, self::PATH], true);
    }

    public function viaHost(): bool
    {
        return $this->source === self::HOST;
    }

    public function viaPath(): bool
    {
        return $this->source === self::PATH;
    }

    public function base(): string
    {
        return $this->viaPath() && $this->team !== null ? '/' . $this->team->slug : '';
    }
}
