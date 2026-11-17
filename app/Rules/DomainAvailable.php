<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Rules;

use App\Models\Application;
use App\Services\Address\DomainClaims;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class DomainAvailable implements ValidationRule
{
    public function __construct(private Application $application) {}

    public static function normalize(mixed $domain): mixed
    {
        return is_string($domain) ? strtolower(rtrim(trim($domain), '.')) : $domain;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '' || in_array($value, array_map(strtolower(...), $this->application->allDomains()), true)) {
            return;
        }

        $claims = app(DomainClaims::class);

        if ($claims->byCoresmith([$value]) !== []) {
            $fail("Coresmith uses {$value} for its own address. Pick another domain.");
        } elseif ($claims->byApps($this->application, [$value]) !== []) {
            $fail("Another app already uses {$value}. Remove it from that app first, or pick another domain.");
        }
    }
}
