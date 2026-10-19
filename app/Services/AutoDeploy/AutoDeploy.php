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
use Illuminate\Support\Facades\Cache;

final class AutoDeploy
{
    public function observe(Application $application, string $sha, string $source): ?Deployment
    {
        if (preg_match('/^[0-9a-f]{40}$/D', $sha) !== 1) {
            return null;
        }

        $application->forceFill([
            'repository_head' => $sha,
            'repository_head_source' => $source,
            'repository_head_at' => now(),
        ])->saveQuietly();

        return $this->reconcile($application);
    }

    public function reconcile(Application $application): ?Deployment
    {
        if (!$application->quick_deploy || $application->repository_head === null || $application->trashed()) {
            return null;
        }

        $created = Cache::lock('auto-deploy:' . $application->getKey(), 10)->get(function () use ($application): ?Deployment {
            $latest = $application->deployments()->where('status', '!=', DeploymentStatus::Cancelled->value)->latest('created_at')->first();

            if ($latest !== null && in_array($latest->status, [DeploymentStatus::Pending, DeploymentStatus::Running], true)) {
                return null;
            }

            if ($latest !== null && in_array($application->repository_head, array_filter([$latest->commit_hash, $latest->requested_commit]), true)) {
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
