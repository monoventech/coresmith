<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Rules;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

final class FillableProject implements ValidationRule
{
    public const MESSAGE = 'Pick a project you can add to, or No project.';

    public function __construct(private readonly ?Team $team, private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $project = is_string($value) ? Project::query()->find($value) : null;

        if (!$project instanceof Project
            || !$this->team instanceof Team
            || !$this->user instanceof User
            || $project->team_id !== $this->team->getKey()
            || !Gate::forUser($this->user)->allows('fill', $project)) {
            $fail(self::MESSAGE);
        }
    }
}
