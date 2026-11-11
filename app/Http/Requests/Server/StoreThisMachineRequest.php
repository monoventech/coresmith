<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Enums\Ability;
use App\Http\Controllers\ServerController;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreThisMachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = Team::find($this->input('team_id'));

        return $this->user()->managesInstance() && $this->user()->hasTeamAbility($team, Ability::CreateServer);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'team_id' => ['required', 'string', Rule::exists('teams', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['nullable', Rule::in(ServerController::ENVIRONMENTS)],
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
