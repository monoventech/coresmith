<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\Edition;
use App\Rules\TwoFactorCode;
use App\Services\Address\InstallationAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'code' => [Rule::requiredIf(TwoFactorCode::required($user)), 'nullable', 'string', new TwoFactorCode($user)],
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
}
