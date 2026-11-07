<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\Ability;
use App\Enums\CloudProviderType;
use App\Services\Teams\TeamContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCloudProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = app(TeamContext::class)->team();

        return $team !== null && $this->user()->hasTeamAbility($team, Ability::ManageProviders);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CloudProviderType::class)],
            'name' => ['required', 'string', 'max:60', Rule::unique('cloud_providers', 'name')->where('team_id', app(TeamContext::class)->team()?->getKey())->whereNull('deleted_at')],
            'api_token' => ['required', 'string', 'max:512', 'regex:/^\S+$/'],
            'return_to' => ['nullable', Rule::in(['settings', 'provision', 'setup'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $label = CloudProviderType::tryFrom((string) $this->input('type'))?->label() ?? 'the provider';

        return [
            'type.required' => 'Pick a provider.',
            'type.enum' => 'Pick a provider.',
            'name.required' => 'Name the account, like Hetzner production.',
            'name.unique' => 'This team already has an account with that name. Give this one another name.',
            'api_token.required' => "Paste the API token from {$label}.",
            'api_token.regex' => "That token has spaces in it. Copy it again from {$label}.",
        ];
    }
}
