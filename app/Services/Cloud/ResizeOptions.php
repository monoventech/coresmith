<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Enums\CloudProviderType;
use App\Enums\ServerStatus;
use App\Exceptions\CloudProviderException;
use App\Models\Server;
use Illuminate\Support\Collection;

final class ResizeOptions
{
    public function __construct(private readonly CloudCatalog $catalog) {}

    public static function resizable(Server $server): bool
    {
        return $server->cloudProvider !== null && $server->status === ServerStatus::Active && filled($server->provider_server_id);
    }

    public static function upOnly(Server $server): bool
    {
        return $server->cloudProvider?->type === CloudProviderType::Vultr;
    }

    /**
     * @return array{0: CloudSize|null, 1: Collection<int, CloudSize>}
     *
     * @throws CloudProviderException
     */
    public function for(Server $server): array
    {
        $catalog = $this->catalog->for($server->cloudProvider);
        $current = $catalog->size((string) $server->provider_size);

        return [$current, $current === null ? collect() : $catalog->resizeOptions($current, (string) $server->region, self::upOnly($server))];
    }
}
