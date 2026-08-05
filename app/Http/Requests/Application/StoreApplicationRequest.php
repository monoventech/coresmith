<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

use App\Enums\Ability;
use App\Enums\ApplicationType;
use App\Models\Server;
use App\Services\SafeName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && $this->user()->hasTeamAbility($server->team, Ability::CreateApplication);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ApplicationType::class)],
            'js_runtime' => ['nullable', Rule::in(['bun', 'node', 'deno'])],
            'framework' => ['nullable', 'string', 'max:64'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.SafeName::DOMAIN_PATTERN],
            'repository_url' => ['nullable', 'string', 'max:2048', 'regex:/^(https:\\/\\/[^\\s]+|git@[A-Za-z0-9.-]+:[^\\s]+)$/'],
            'repository_branch' => ['required', 'string', 'max:255'],
            'php_version' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]+$/'],
            'node_version' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]+$/'],
            'ruby_version' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]+$/'],
            'directory' => ['required', 'string', 'max:255'],
            'web_directory' => ['required', 'string', 'max:255'],
            'app_size' => ['required', Rule::in(['small', 'medium', 'large', 'custom'])],
            'run_migrations' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain name, for example app.example.com.',
            'repository_url.regex' => 'Use an https:// URL for public repositories, or git@host:owner/repo.git for private ones.',
        ];
    }
}
