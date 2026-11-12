<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Http\Controllers\ServerController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateServerRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'environment' => ['sometimes', 'nullable', Rule::in(ServerController::ENVIRONMENTS)],
            'region' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9 ._()-]+$/'],
            'ssh_port' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'audit_logging_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['environment.in' => ServerController::PICK_ENVIRONMENT];
    }
}
