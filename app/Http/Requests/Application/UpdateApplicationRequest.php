<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

use App\Enums\ApplicationType;
use App\Models\Application;
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
        $rules = [
            'type' => ['sometimes', 'required', Rule::enum(ApplicationType::class)],
            'js_runtime' => ['sometimes', 'nullable', Rule::in(['bun', 'node', 'deno'])],
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:' . SafeName::DOMAIN_PATTERN],
            'additional_domains' => ['sometimes', 'nullable', 'array'],
            'additional_domains.*' => ['string', 'max:253', 'regex:' . SafeName::DOMAIN_PATTERN],
            'repository_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:' . SafeName::GIT_URL_PATTERN],
            'repository_branch' => ['sometimes', 'required', 'string', 'max:255', 'regex:' . SafeName::BRANCH_PATTERN],
            'directory' => ['sometimes', 'required', 'string', 'max:255', 'regex:' . SafeName::RELATIVE_PATH_PATTERN],
            'web_directory' => ['sometimes', 'required', 'string', 'max:255', 'regex:' . SafeName::RELATIVE_PATH_PATTERN],
            'app_size' => ['sometimes', 'required', Rule::in(['small', 'medium', 'large', 'custom'])],
            'run_migrations' => ['sometimes', 'boolean'],
        ];

        if ($this->linkedToGitHub()) {
            $rules['repository_url'] = ['missing'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain name, for example app.example.com.',
            'repository_branch.regex' => 'That is not a branch name Git accepts. Use something like main or release/2.0.',
            'directory.regex' => 'Use a folder inside the repository, like / or apps/web.',
            'web_directory.regex' => 'Use a folder inside the app, like /public or dist.',
            'additional_domains.*.regex' => 'Each additional domain must be a valid domain name.',
            'repository_url.missing' => "This app's repository comes from its GitHub connection, so it can't be changed here.",
        ];
    }

    private function linkedToGitHub(): bool
    {
        $application = $this->route('application');

        return $application instanceof Application && $application->source_provider_id !== null;
    }
}
