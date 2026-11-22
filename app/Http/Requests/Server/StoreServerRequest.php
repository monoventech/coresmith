<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Enums\Ability;
use App\Enums\Edition;
use App\Models\Server;
use App\Models\Team;
use App\Services\Network\OwnAddress;
use App\Services\Network\PublicAddress;
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
            'region' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9 ._()-]+$/'],
            'ip_address' => ['required', 'ip'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ssh_user' => ['required', 'string', 'regex:' . SafeName::SYSTEM_USER_PATTERN],
            'audit_logging_enabled' => ['boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('ip_address')) {
                return;
            }

            $address = (string) $this->input('ip_address');
            $cloud = Edition::current()->isCloud();

            $refusal = match (true) {
                $cloud && !PublicAddress::is($address) => "That's a private address, so Coresmith Cloud can't reach it. Use your server's public IP address.",
                !app(OwnAddress::class)->is($address) => null,
                $cloud => "That address belongs to Coresmith Cloud. Use your server's public IP address.",
                default => $this->ownMachine(),
            };

            if ($refusal !== null) {
                $validator->errors()->add('ip_address', $refusal);
            }
        }, function (Validator $validator): void {
            if ($validator->errors()->hasAny(['ip_address', 'ssh_port', 'team_id'])) {
                return;
            }

            $existing = Server::query()
                ->where('team_id', $this->input('team_id'))
                ->where('ssh_port', (int) $this->input('ssh_port'))
                ->get()
                ->first(fn(Server $server): bool => $server->hasAddress((string) $this->input('ip_address')));

            if ($existing !== null) {
                $validator->errors()->add('ip_address', "{$existing->name} already uses this address and SSH port. Open it from the fleet instead.");
            }
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
