<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Models\Project;
use App\Models\Team;
use App\Services\Teams\TeamContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ProjectNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->project();

        if ($project instanceof Project) {
            Gate::authorize('manage', $project);
        } else {
            Gate::authorize('create', [Project::class, $this->team()]);
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:64', $this->unique(...)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name the project.',
            'name.max' => 'Keep the name to 64 characters.',
        ];
    }

    private function unique(string $attribute, mixed $value, Closure $fail): void
    {
        $project = $this->project();
        $taken = $this->team()->projects()->get(['id', 'name'])->first(
            fn(Project $each): bool => mb_strtolower($each->name) === mb_strtolower((string) $value) && !($project instanceof Project && $each->is($project)),
        );

        if ($taken instanceof Project) {
            $fail("There's already a project called {$taken->name}.");
        }
    }

    private function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }

    private function team(): Team
    {
        $team = $this->project()?->team ?? app(TeamContext::class)->team();

        abort_unless($team instanceof Team, 404);

        return $team;
    }
}
