<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

final readonly class PythonApp
{
    /**
     * @param  array{0: string, 1: string}|null  $problem
     */
    public function __construct(
        public string $server,
        public ?string $module,
        public ?array $problem = null,
        public bool $collectstatic = false,
        public ?string $migrate = null,
    ) {}

    public static function missing(string $server, string $reason, string $fix): self
    {
        return new self($server, null, [$reason, $fix]);
    }

    public function unsettled(): ?string
    {
        return $this->problem === null ? null : $this->problem[0] . ' ' . $this->problem[1];
    }
}
