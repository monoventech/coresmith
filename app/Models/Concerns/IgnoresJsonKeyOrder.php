<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models\Concerns;

trait IgnoresJsonKeyOrder
{
    public function originalIsEquivalent($key): bool
    {
        if (!$this->hasCast($key, ['array', 'json']) || !isset($this->attributes[$key], $this->original[$key])) {
            return parent::originalIsEquivalent($key);
        }

        if ($this->attributes[$key] === $this->original[$key]) {
            return true;
        }

        return self::withSortedKeys($this->castAttribute($key, $this->attributes[$key])) === self::withSortedKeys($this->castAttribute($key, $this->original[$key]));
    }

    private static function withSortedKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::withSortedKeys(...), $value);

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
