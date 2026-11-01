<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\Ability;
use App\Models\CloudProvider;
use App\Services\Teams\TeamContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCloudProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = app(TeamContext::class)->team();
        $account = $this->account();

        abort_unless($team !== null && $account->team_id === $team->getKey(), 404);

        return $this->user()->hasTeamAbility($team, Ability::ManageProviders);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->account();

        return [
            'name' => ['required_without:api_token', 'string', 'max:60', Rule::unique('cloud_providers', 'name')->where('team_id', $account->team_id)->whereNull('deleted_at')->ignore($account->getKey())],
            'api_token' => ['required_without:name', 'string', 'max:512', 'regex:/^\S+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $label = $this->account()->type->label();

        return [
            'name.required_without' => 'Name the account, like Hetzner production.',
            'name.unique' => 'This team already has an account with that name. Give this one another name.',
            'api_token.required_without' => "Paste the API token from {$label}.",
            'api_token.regex' => "That token has spaces in it. Copy it again from {$label}.",
        ];
    }

    private function account(): CloudProvider
    {
        $account = $this->route('cloudProvider');

        abort_unless($account instanceof CloudProvider, 404);

        return $account;
    }
}
