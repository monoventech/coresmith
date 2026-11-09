<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Database;

use App\Models\ManagedDatabase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DestroyDatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $database = $this->route('database');

        return $database instanceof ManagedDatabase && $this->user()->can('delete', $database);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string', Rule::in([$this->databaseName()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = "Type {$this->databaseName()} exactly to confirm.";

        return [
            'confirm.required' => $message,
            'confirm.in' => $message,
        ];
    }

    private function databaseName(): string
    {
        $database = $this->route('database');

        return $database instanceof ManagedDatabase ? (string) $database->name : '';
    }
}
