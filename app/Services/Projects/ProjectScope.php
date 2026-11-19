<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

final class ProjectScope
{
    public const NONE = 'none';

    private function __construct(private readonly ?string $project, private readonly ?string $address = null) {}

    public static function from(Request $request, ?User $user, ?Team $team): self
    {
        $project = $request->query('project');

        if (!$user instanceof User || !$team instanceof Team || !is_string($project) || $project === '') {
            return new self(null);
        }

        if ($project === self::NONE) {
            return new self(self::NONE);
        }

        $chosen = app(TeamAccess::class)->projects($user, $team)
            ->where(fn(Builder $match) => $match->where('handle', $project)->orWhere('id', strtolower($project)))
            ->first();

        return $chosen instanceof Project ? new self($chosen->getKey(), (string) ($chosen->handle ?? $chosen->getKey())) : new self(null);
    }

    /**
     * @template T of Builder|HasMany
     *
     * @param  T  $applications
     * @return T
     */
    public function apply(Builder|HasMany $applications): Builder|HasMany
    {
        return match ($this->project) {
            null => $applications,
            self::NONE => $applications->whereNull('project_id'),
            default => $applications->where('project_id', $this->project),
        };
    }

    /**
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->project === null ? [] : ['project' => $this->address ?? $this->project];
    }

    public function value(): string
    {
        return $this->address ?? $this->project ?? '';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(User $user, ?Team $team): array
    {
        if (!$team instanceof Team) {
            return [];
        }

        $projects = app(TeamAccess::class)->projects($user, $team)->orderBy('name')->get();

        if ($projects->isEmpty()) {
            return [];
        }

        return [
            ['value' => '', 'label' => 'All projects'],
            ['value' => self::NONE, 'label' => 'No project'],
            ...$projects->map(fn(Project $project): array => ['value' => (string) ($project->handle ?? $project->getKey()), 'label' => $project->name])->values()->all(),
        ];
    }
}
