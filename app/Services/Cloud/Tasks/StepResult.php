<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Tasks;

final readonly class StepResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public string $kind,
        public ?string $step = null,
        public int $seconds = 0,
        public array $data = [],
        public ?string $reason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function next(string $step, array $data = []): self
    {
        return new self('next', step: $step, data: $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function wait(int $seconds, array $data = []): self
    {
        return new self('wait', seconds: $seconds, data: $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function done(array $data = []): self
    {
        return new self('done', data: $data);
    }

    public static function fail(string $reason): self
    {
        return new self('fail', reason: $reason);
    }
}
