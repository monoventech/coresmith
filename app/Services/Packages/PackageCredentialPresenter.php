<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Packages;

use App\Enums\PackageSource;
use App\Models\PackageCredential;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;

final class PackageCredentialPresenter
{
    public function __construct(private readonly TeamAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Team $team, User $user): array
    {
        $manages = $user->can('update', $team);
        $projects = $manages ? $team->projects()->orderBy('name')->get() : $this->access->projects($user, $team)->orderBy('name')->get();
        $visible = $projects->pluck('id')->all();

        return [
            'can_manage' => $manages,
            'sources' => array_map(fn(PackageSource $source): array => ['value' => $source->value, 'label' => $source->label()], PackageSource::cases()),
            'projects' => $projects->map(fn(Project $project): array => ['id' => $project->id, 'name' => $project->name])->values()->all(),
            'entries' => $team->packageCredentials()
                ->with(['project', 'creator'])
                ->where(fn($query) => $query->whereNull('project_id')->orWhereIn('project_id', $visible))
                ->orderBy('source')
                ->orderBy('target')
                ->get()
                ->map(fn(PackageCredential $credential): array => [
                    'id' => $credential->id,
                    'source' => $credential->source->value,
                    'source_label' => $credential->source->label(),
                    'target' => $credential->target,
                    'scope' => $credential->scope,
                    'username' => $manages ? $credential->username : null,
                    'hint' => $manages ? $credential->hint : null,
                    'project_id' => $credential->project_id,
                    'project' => $credential->project?->name,
                    'added' => $credential->created_at?->diffForHumans(),
                    'added_by' => $credential->creator?->name,
                ])
                ->values()
                ->all(),
        ];
    }
}
