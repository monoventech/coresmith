<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DestroyCloudServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server && $this->user()->can('delete', $server);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string', Rule::in([$this->serverName()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = "Type {$this->serverName()} exactly to confirm.";

        return [
            'confirm.required' => $message,
            'confirm.in' => $message,
        ];
    }

    private function serverName(): string
    {
        $server = $this->route('server');

        return $server instanceof Server ? (string) $server->name : '';
    }
}
