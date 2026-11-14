<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class VaultDecryptionException extends RuntimeException
{
    public static function forPayload(): self
    {
        return new self('Coresmith couldn\'t decrypt a stored value. The CORESMITH_VAULT_KEY may have changed, or the stored value is corrupt.');
    }

    public static function forAttribute(string $model, string $attribute): self
    {
        return new self("Coresmith couldn't decrypt [{$attribute}] on [{$model}]. The CORESMITH_VAULT_KEY may have changed since this value was stored.");
    }
}
