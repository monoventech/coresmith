<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Environment;
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

    /**
     * @param  list<string>|null  $environments
     */
    private function __construct(private readonly ?string $project, private readonly ?string $environment, private readonly ?array $environments) {}

    public static function from(Request $request, ?User $user, ?Team $team): self
    {
        $project = $request->query('project');
        $environment = $request->query('environment');

        if (!$user instanceof User || !$team instanceof Team || !is_string($project) || $project === '') {
            return new self(null, null, null);
        }

        if ($project === self::NONE) {
            return new self(self::NONE, null, []);
        }

        $access = app(TeamAccess::class);
        $chosen = $access->projects($user, $team)->find($project);

        if (!$chosen instanceof Project) {
            return new self(null, null, null);
        }

        $visible = $access->environmentsOf($user, $chosen);
        $picked = is_string($environment) ? $visible->firstWhere('id', $environment) : null;

        return $picked instanceof Environment
            ? new self($chosen->getKey(), $picked->getKey(), [$picked->getKey()])
            : new self($chosen->getKey(), null, $visible->modelKeys());
    }

    /**
     * @template T of Builder|HasMany
     *
     * @param  T  $applications
     * @return T
     */
    public function apply(Builder|HasMany $applications): Builder|HasMany
    {
        return match (true) {
            $this->environments === null => $applications,
            $this->project === self::NONE => $applications->whereNull('environment_id'),
            default => $applications->whereIn('environment_id', $this->environments),
        };
    }

    /**
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter(['project' => $this->project, 'environment' => $this->environment], fn(?string $value): bool => $value !== null);
    }

    public function value(): string
    {
        return match (true) {
            $this->project === null => '',
            $this->environment === null => $this->project,
            default => $this->project . ':' . $this->environment,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(User $user, ?Team $team): array
    {
        if (!$team instanceof Team) {
            return [];
        }

        $access = app(TeamAccess::class);
        $projects = $access->projects($user, $team)->orderBy('name')->get();

        if ($projects->isEmpty()) {
            return [];
        }

        $options = [['value' => '', 'label' => 'All projects'], ['value' => self::NONE, 'label' => 'No project']];

        foreach ($projects as $project) {
            $options[] = ['value' => $project->getKey(), 'label' => $project->name];

            foreach ($access->environmentsOf($user, $project) as $environment) {
                $options[] = ['value' => $project->getKey() . ':' . $environment->getKey(), 'label' => $project->name . ' › ' . $environment->name];
            }
        }

        return $options;
    }
}
