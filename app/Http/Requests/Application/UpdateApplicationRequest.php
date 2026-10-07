<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

use App\Enums\ApplicationType;
use App\Services\SafeName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::enum(ApplicationType::class)],
            'js_runtime' => ['sometimes', 'nullable', Rule::in(['bun', 'node', 'deno'])],
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:' . SafeName::DOMAIN_PATTERN],
            'additional_domains' => ['sometimes', 'nullable', 'array'],
            'additional_domains.*' => ['string', 'max:253', 'regex:' . SafeName::DOMAIN_PATTERN],
            'repository_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:/^(https:\\/\\/[^\\s]+|git@[A-Za-z0-9.-]+:[^\\s]+)$/'],
            'repository_branch' => ['sometimes', 'required', 'string', 'max:255'],
            'directory' => ['sometimes', 'required', 'string', 'max:255'],
            'web_directory' => ['sometimes', 'required', 'string', 'max:255'],
            'app_size' => ['sometimes', 'required', Rule::in(['small', 'medium', 'large', 'custom'])],
            'run_migrations' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain name, for example app.example.com.',
            'additional_domains.*.regex' => 'Each additional domain must be a valid domain name.',
        ];
    }
}
