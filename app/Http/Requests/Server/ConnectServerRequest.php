<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Services\SafeName;
use Illuminate\Foundation\Http\FormRequest;

final class ConnectServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('server'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ssh_user' => ['required', 'string', 'regex:' . SafeName::SYSTEM_USER_PATTERN],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ssh_user.regex' => 'That isn\'t a Linux username. Try root or deploy.',
            'password.required' => 'Enter the password for that user.',
        ];
    }
}
