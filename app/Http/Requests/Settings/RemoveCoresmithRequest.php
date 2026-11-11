<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\Edition;
use App\Models\User;
use App\Services\Address\InstallationAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

final class RemoveCoresmithRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_if(Edition::current()->isCloud(), 404);

        return $this->user()->managesInstance();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'address' => ['required', 'string', Rule::in([app(InstallationAddress::class)->host()])],
            'password' => ['required', 'string', 'current_password'],
            'code' => [
                Rule::requiredIf($this->twoFactor($user)),
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if ($this->twoFactor($user) && !$this->codeIsRight($user, (string) $value)) {
                        $fail("That code isn't right.");
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $address = 'Type ' . app(InstallationAddress::class)->host() . ' to confirm.';

        return [
            'address.required' => $address,
            'address.in' => $address,
            'password.required' => "That password isn't right.",
            'password.current_password' => "That password isn't right.",
            'code.required' => 'Enter the code from your authenticator app.',
        ];
    }

    private function twoFactor(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    private function codeIsRight(User $user, string $code): bool
    {
        try {
            if (app(TwoFactorAuthenticationProvider::class)->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code)) {
                return true;
            }
        } catch (Throwable) {
            return false;
        }

        $recovery = collect($user->recoveryCodes())->first(fn(string $known): bool => hash_equals($known, $code));

        if (!is_string($recovery)) {
            return false;
        }

        $user->replaceRecoveryCode($recovery);

        return true;
    }
}
