<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Models\Environment;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class EnvironmentNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('manage', $this->project());

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:32', $this->unique(...)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name the environment.',
            'name.max' => 'Keep the name to 32 characters.',
        ];
    }

    private function unique(string $attribute, mixed $value, Closure $fail): void
    {
        $project = $this->project();
        $environment = $this->route('environment');
        $taken = $project->environments()->get(['id', 'name'])->first(
            fn(Environment $each): bool => mb_strtolower($each->name) === mb_strtolower((string) $value) && !($environment instanceof Environment && $each->is($environment)),
        );

        if ($taken instanceof Environment) {
            $fail("{$project->name} already has an environment called {$taken->name}.");
        }
    }

    private function project(): Project
    {
        $project = $this->route('project');

        abort_unless($project instanceof Project, 404);

        return $project;
    }
}
