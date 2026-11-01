<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

final readonly class CloudImage
{
    /**
     * @param  list<string>  $architectures
     */
    public function __construct(
        public string $id,
        public string $family,
        public string $version,
        public array $architectures,
        public string $label = '',
    ) {}

    public function supports(string $architecture): bool
    {
        return in_array($architecture, $this->architectures, true);
    }

    public function withLabel(string $label): self
    {
        return new self($this->id, $this->family, $this->version, $this->architectures, $label);
    }
}
