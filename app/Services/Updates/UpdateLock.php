<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

final class UpdateLock
{
    /**
     * @param  resource  $handle
     */
    public function __construct(private $handle) {}

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}
