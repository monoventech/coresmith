<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\DB;

final class Projects
{
    public function __construct(private readonly TeamAccess $access) {}

    public function create(Team $team, string $name, User $by): Project
    {
        $project = $team->projects()->create(['name' => $name, 'created_by' => $by->getKey()]);

        $this->access->grant($team, $by, $project);

        activity()->performedOn($team)->causedBy($by)->withProperties(['project' => $name])->log('project_created');

        return $project;
    }

    public function rename(Project $project, string $name, User $by): void
    {
        $from = $project->name;

        if ($from === $name) {
            return;
        }

        $project->update(['name' => $name]);

        activity()->performedOn($project->team)->causedBy($by)->withProperties(['from' => $from, 'project' => $name])->log('project_renamed');
    }

    public function delete(Project $project, User $by): void
    {
        DB::transaction(fn() => $project->delete());

        activity()->performedOn($project->team)->causedBy($by)->withProperties(['project' => $project->name])->log('project_deleted');
    }

    /**
     * @param  iterable<Application>  $applications
     * @param  iterable<ManagedDatabase>  $databases
     */
    public function file(Project $project, iterable $applications, iterable $databases, User $by): void
    {
        foreach ([...$applications, ...$databases] as $subject) {
            if ($subject->project_id === $project->getKey()) {
                continue;
            }

            $subject->update(['project_id' => $project->getKey()]);

            activity()->performedOn($subject)->causedBy($by)->withProperties(['project' => $project->name])->log($this->event($subject));
        }
    }

    public function unfile(Application|ManagedDatabase $subject, User $by): void
    {
        $from = $subject->project?->name;

        $subject->update(['project_id' => null]);

        activity()->performedOn($subject)->causedBy($by)->withProperties(['project' => null, 'from' => $from])->log($this->event($subject));
    }

    private function event(Application|ManagedDatabase $subject): string
    {
        return $subject instanceof Application ? 'application_filed' : 'database_filed';
    }
}
