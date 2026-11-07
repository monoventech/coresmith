<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Enums\ReleaseSignature;

final class SignatureResult
{
    public function __construct(
        public readonly ReleaseSignature $status,
        public readonly string $message,
        public readonly ?string $fingerprint = null,
    ) {}

    public function passed(): bool
    {
        return $this->status !== ReleaseSignature::Invalid;
    }
}
