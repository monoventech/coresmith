<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\DB;

final class Projects
{
    public const FIRST_ENVIRONMENT = 'Production';

    public function __construct(private readonly TeamAccess $access) {}

    public function create(Team $team, string $name, User $by): Project
    {
        $project = DB::transaction(function () use ($team, $name, $by): Project {
            $project = $team->projects()->create(['name' => $name, 'created_by' => $by->getKey()]);
            $project->environments()->create(['name' => self::FIRST_ENVIRONMENT, 'position' => 0]);

            return $project;
        });

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

    public function addEnvironment(Project $project, string $name, User $by): Environment
    {
        $environment = $project->environments()->create(['name' => $name, 'position' => (int) $project->environments()->max('position') + 1]);
        activity()->performedOn($project->team)->causedBy($by)->withProperties(['project' => $project->name, 'environment' => $name])->log('environment_created');

        return $environment;
    }

    public function renameEnvironment(Environment $environment, string $name, User $by): void
    {
        $from = $environment->name;

        if ($from === $name) {
            return;
        }

        $environment->update(['name' => $name]);
        activity()->performedOn($environment->project->team)->causedBy($by)->withProperties(['project' => $environment->project->name, 'from' => $from, 'environment' => $name])->log('environment_renamed');
    }

    public function deleteEnvironment(Environment $environment, User $by): bool
    {
        $project = $environment->project;

        if ($project->environments()->count() <= 1) {
            return false;
        }

        DB::transaction(fn() => $environment->delete());
        activity()->performedOn($project->team)->causedBy($by)->withProperties(['project' => $project->name, 'environment' => $environment->name])->log('environment_deleted');

        return true;
    }

    /**
     * @param  iterable<Application>  $applications
     * @param  iterable<ManagedDatabase>  $databases
     */
    public function file(Environment $environment, iterable $applications, iterable $databases, User $by): void
    {
        $properties = ['project' => $environment->project->name, 'environment' => $environment->name];

        foreach ([...$applications, ...$databases] as $subject) {
            if ($subject->environment_id === $environment->getKey()) {
                continue;
            }

            $subject->update(['environment_id' => $environment->getKey()]);
            activity()->performedOn($subject)->causedBy($by)->withProperties($properties)->log($this->event($subject));
        }
    }

    public function unfile(Application|ManagedDatabase $subject, User $by): void
    {
        $from = $subject->environment?->project?->name;

        $subject->update(['environment_id' => null]);
        activity()->performedOn($subject)->causedBy($by)->withProperties(['project' => null, 'environment' => null, 'from' => $from])->log($this->event($subject));
    }

    private function event(Application|ManagedDatabase $subject): string
    {
        return $subject instanceof Application ? 'application_filed' : 'database_filed';
    }
}
