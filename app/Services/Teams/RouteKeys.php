<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\Server;

final class RouteKeys
{
    /** @var array<string, string> */
    private array $servers = [];

    public function server(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return $id;
        }

        if (!array_key_exists($id, $this->servers)) {
            $this->servers[$id] = (string) (Server::withTrashed()->whereKey($id)->value('handle') ?? $id);
        }

        return $this->servers[$id];
    }
}
