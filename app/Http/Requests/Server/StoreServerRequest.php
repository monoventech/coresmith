<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Enums\Ability;
use App\Enums\Edition;
use App\Models\Team;
use App\Services\Network\OwnAddress;
use App\Services\SafeName;
use App\Services\Server\ThisMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'ssh_user' => ['required', 'string', 'regex:' . SafeName::SYSTEM_USER_PATTERN],
            'audit_logging_enabled' => ['boolean'],
            'password' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('ip_address') || Edition::current()->isCloud() || !app(OwnAddress::class)->is((string) $this->input('ip_address'))) {
                return;
            }

            $validator->errors()->add('ip_address', $this->ownMachine());
        }];
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

    private function ownMachine(): string
    {
        if (!$this->user()->managesInstance()) {
            return "That's the machine Coresmith runs on. Add a different server for your apps.";
        }

        $team = app(ThisMachine::class)->server()?->team?->name;

        return $team === null
            ? "That's the machine Coresmith runs on. Choose This machine under Provider instead."
            : "That's the machine Coresmith runs on, and it's already a server in {$team}.";
    }
}
