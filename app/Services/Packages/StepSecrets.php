<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Packages;

final class StepSecrets
{
    /**
     * @param  array<string, string>  $environment
     * @param  array<string, string>  $files
     * @param  list<string>  $secrets
     */
    public function __construct(
        public readonly array $environment = [],
        public readonly array $files = [],
        public readonly array $secrets = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->environment === [] && $this->files === [];
    }
}
