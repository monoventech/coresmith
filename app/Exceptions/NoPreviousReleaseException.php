<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class NoPreviousReleaseException extends RuntimeException
{
    public static function forApplication(string $slug): self
    {
        return new self("[{$slug}] has no previous release to roll back to.");
    }

    public static function releaseGone(string $release): self
    {
        return new self("Release {$release} is no longer on the server, so there is nothing to roll back to. Deploy again instead.");
    }
}
