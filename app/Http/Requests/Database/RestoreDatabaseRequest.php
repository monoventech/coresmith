<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Database;

use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class RestoreDatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('restore', $this->database());

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'stamp' => ['required', 'string', 'regex:/^\d{14}$/'],
            'name' => ['required', 'string', Rule::in([(string) $this->database()->name])],
            'stop' => ['array'],
            'stop.*' => ['string', Rule::exists('applications', 'id')->where('server_id', $this->database()->server_id), $this->seen(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $confirm = "Type {$this->database()->name} to confirm.";

        return [
            'name.required' => $confirm,
            'name.in' => $confirm,
            'stop.*.exists' => "Only apps on {$this->database()->server?->name} can be stopped.",
        ];
    }

    private function seen(string $attribute, mixed $value, Closure $fail): void
    {
        $application = is_string($value) ? Application::query()->find($value) : null;
        $user = $this->user();

        if ($application instanceof Application && $user instanceof User && !app(TeamAccess::class)->canSeeApplication($user, $application)) {
            $fail("One of those apps isn't one you can see. Reload the page and choose again.");
        }
    }

    private function database(): ManagedDatabase
    {
        $database = $this->route('database');

        abort_unless($database instanceof ManagedDatabase, 404);

        return $database;
    }
}
