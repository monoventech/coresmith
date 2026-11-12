<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use Spatie\Activitylog\Models\Activity;

final class RollbackHistory
{
    public const ROLLED_BACK = 'application_rolled_back';

    public const DID_NOT_START = 'rollback_processes_failed';

    public const WORKER_DID_NOT_START = 'rollback_worker_failed';

    public function sinceLastLive(Application $application): ?Activity
    {
        return $this->after($this->latest($application), $this->lastWentLive($application));
    }

    public function previous(Application $application): ?Deployment
    {
        return $application->deployments()
            ->where('status', DeploymentStatus::Succeeded->value)
            ->whereNotNull('release')
            ->where('release', '!=', $application->current_release)
            ->latest('finished_at')
            ->first();
    }

    public function rolledBack(Application $application): bool
    {
        $live = $this->lastWentLive($application);

        return in_array($this->after($this->latest($application), $live)?->description, [self::ROLLED_BACK, self::WORKER_DID_NOT_START], true)
            && $application->current_release !== $live?->release;
    }

    private function latest(Application $application): ?Activity
    {
        return Activity::query()
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->whereIn('description', [self::ROLLED_BACK, self::DID_NOT_START, self::WORKER_DID_NOT_START])
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    private function lastWentLive(Application $application): ?Deployment
    {
        return $application->deployments()
            ->whereIn('status', [DeploymentStatus::Succeeded->value, DeploymentStatus::Failed->value])
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->cursor()
            ->first(fn(Deployment $deployment): bool => $deployment->status === DeploymentStatus::Succeeded || DeploymentPresenter::reachedUsers($deployment));
    }

    private function after(?Activity $rollback, ?Deployment $live): ?Activity
    {
        return $rollback !== null && ($live === null || $rollback->created_at?->greaterThan($live->finished_at) === true) ? $rollback : null;
    }
}
