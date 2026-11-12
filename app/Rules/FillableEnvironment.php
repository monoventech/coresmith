<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Rules;

use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

final class FillableEnvironment implements ValidationRule
{
    public const MESSAGE = 'Pick a project environment you can see, or No project.';

    public function __construct(private readonly ?Team $team, private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $environment = is_string($value) ? Environment::query()->with('project')->find($value) : null;

        if (!$environment instanceof Environment
            || !$this->team instanceof Team
            || !$this->user instanceof User
            || $environment->project?->team_id !== $this->team->getKey()
            || !Gate::forUser($this->user)->allows('fill', $environment)) {
            $fail(self::MESSAGE);
        }
    }
}
