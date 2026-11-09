<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Database;

use App\Enums\Ability;
use App\Enums\DatabaseType;
use App\Enums\PrivilegeLevel;
use App\Models\Server;
use App\Services\Database\CoresmithDatabase;
use App\Services\SafeName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreDatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && $this->user()->hasTeamAbility($server->team, Ability::CreateDatabase);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DatabaseType::class)],
            'name' => ['required', 'string', 'regex:' . SafeName::DATABASE_PATTERN, Rule::unique('databases', 'name')],
            'db_user' => ['required_unless:type,redis', 'nullable', 'string', 'regex:' . SafeName::DATABASE_PATTERN],
            'privilege_level' => ['required_unless:type,redis', 'nullable', Rule::enum(PrivilegeLevel::class)],
            'extensions' => ['nullable', 'array'],
            'extensions.*' => ['string', 'regex:/^[a-zA-Z0-9_-]{1,63}$/D'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $server = $this->route('server');
            $type = DatabaseType::tryFrom((string) $this->input('type'));
            $own = app(CoresmithDatabase::class);

            if (!$server instanceof Server || !$server->runs_coresmith || $type === null || !$own->sharesEngineOnThisMachine($type)) {
                return;
            }

            if ($own->named($this->string('name')->value())) {
                $validator->errors()->add('name', "That's the database Coresmith itself uses on this machine. Pick another name.");
            }

            if ($own->usedBy($this->string('db_user')->value())) {
                $validator->errors()->add('db_user', "That's the user Coresmith's own database uses on this machine. Pick another user.");
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Database names may only contain letters, digits and underscores.',
            'db_user.regex' => 'Database users may only contain letters, digits and underscores.',
        ];
    }
}
