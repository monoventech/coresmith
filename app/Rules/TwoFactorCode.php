<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

final class TwoFactorCode implements ValidationRule, ValidatorAwareRule
{
    private ?Validator $validator = null;

    public function __construct(private readonly User $user) {}

    public static function required(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!self::required($this->user) || $this->validator?->errors()->has('password')) {
            return;
        }

        if (!$this->accepts((string) $value)) {
            $fail("That code isn't right.");
        }
    }

    private function accepts(string $code): bool
    {
        try {
            if (app(TwoFactorAuthenticationProvider::class)->verify(Fortify::currentEncrypter()->decrypt((string) $this->user->two_factor_secret), $code)) {
                return true;
            }
        } catch (Throwable) {
            return false;
        }

        $recovery = collect($this->user->recoveryCodes())->first(fn(string $known): bool => hash_equals($known, $code));

        if (!is_string($recovery)) {
            return false;
        }

        $this->user->replaceRecoveryCode($recovery);

        return true;
    }
}
