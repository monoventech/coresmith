<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Enums\CloudInstanceState;

final readonly class CloudInstance
{
    public function __construct(
        public string $id,
        public CloudInstanceState $state,
        public ?string $ipv4,
        public ?string $size = null,
    ) {}

    public function isReady(): bool
    {
        return $this->state === CloudInstanceState::Running && $this->ipv4 !== null;
    }
}
