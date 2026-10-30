<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Tasks;

use InvalidArgumentException;

final class CloudTaskHandlers
{
    /** @var array<string, class-string<CloudTaskHandler>> */
    public const DEFAULTS = [];

    /** @var array<string, CloudTaskHandler|class-string<CloudTaskHandler>> */
    private readonly array $handlers;

    /**
     * @param  array<string, CloudTaskHandler|class-string<CloudTaskHandler>>  $handlers
     */
    public function __construct(array $handlers = [])
    {
        $this->handlers = array_replace(self::DEFAULTS, $handlers);
    }

    public function for(string $type): CloudTaskHandler
    {
        $handler = $this->handlers[$type] ?? throw new InvalidArgumentException("No cloud task handler is registered for \"{$type}\".");

        return $handler instanceof CloudTaskHandler ? $handler : app($handler);
    }
}
