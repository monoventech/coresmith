<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\AutoDeploy;

use App\Enums\DeploymentStatus;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

final class AutoDeploy
{
    public const COMMIT = '/^[0-9a-f]{40}$/D';

    public function observe(Application $application, string $sha, string $source): ?Deployment
    {
        if (preg_match(self::COMMIT, $sha) !== 1) {
            return null;
        }

        $application->forceFill([
            'repository_head' => $sha,
            'repository_head_source' => $source,
            'repository_head_at' => now(),
        ])->saveQuietly();

        return $this->reconcile($application);
    }

    public function archived(Deployment $deployment, string $hash): void
    {
        Application::query()
            ->whereKey($deployment->application_id)
            ->where(fn(Builder $head): Builder => $head
                ->whereNull('repository_head_at')
                ->orWhere('repository_head_at', '<', $deployment->started_at))
            ->update(['repository_head' => $hash, 'repository_head_at' => now()]);
    }

    public function reconcile(Application $application): ?Deployment
    {
        if (!$application->quick_deploy || $application->repository_head === null || $application->trashed()) {
            return null;
        }

        $created = Cache::lock('auto-deploy:' . $application->getKey(), 10)->get(function () use ($application): ?Deployment {
            $deployments = $application->deployments()->where('status', '!=', DeploymentStatus::Cancelled->value)->latest('created_at');
            $latest = $deployments->clone()->first();

            if ($latest !== null && in_array($latest->status, [DeploymentStatus::Pending, DeploymentStatus::Running], true)) {
                return null;
            }

            $known = $deployments->where(fn(Builder $commit): Builder => $commit->whereNotNull('commit_hash')->orWhereNotNull('requested_commit'))->first();

            if ($known !== null && in_array($application->repository_head, [$known->commit_hash, $known->requested_commit], true)) {
                return null;
            }

            $deployment = Deployment::create([
                'application_id' => $application->getKey(),
                'branch' => $application->repository_branch,
                'trigger' => $application->repository_head_source ?? 'poll',
                'requested_commit' => $application->repository_head,
            ]);

            DeployApplication::dispatch($deployment, now()->format('Ymd-His'));

            return $deployment;
        });

        return $created instanceof Deployment ? $created : null;
    }

    public function afterDeployment(Deployment $deployment): ?Deployment
    {
        $application = Application::query()->find($deployment->application_id);

        return $application === null ? null : $this->reconcile($application);
    }
}
