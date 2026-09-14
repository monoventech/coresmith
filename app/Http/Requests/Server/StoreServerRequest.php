<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Enums\Ability;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = Team::find($this->input('team_id'));

        return $this->user()->hasTeamAbility($team, Ability::CreateServer);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'team_id' => ['required', 'string', Rule::exists('teams', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['nullable', 'string', 'max:32'],
            'region' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9 ._()-]+$/'],
            'ip_address' => ['required', 'ip'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ssh_user' => ['required', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'audit_logging_enabled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ssh_user.regex' => 'That isn\'t a Linux username. Try root or deploy.',
            'ip_address.ip' => 'That doesn\'t look like an IP address. Try 203.0.113.40.',
        ];
    }
}
