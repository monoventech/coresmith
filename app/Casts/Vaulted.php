<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Casts;

use App\Exceptions\VaultDecryptionException;
use App\Services\VaultEncryptor;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<string|null, string|null>
 */
final class Vaulted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return app(VaultEncryptor::class)->decrypt((string) $value);
        } catch (VaultDecryptionException) {
            throw VaultDecryptionException::forAttribute($model::class, $key);
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(VaultEncryptor::class)->encrypt((string) $value);
    }
}
