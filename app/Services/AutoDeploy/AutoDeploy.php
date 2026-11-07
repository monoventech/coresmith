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
use App\Services\Deployment\DeployGuard;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class AutoDeploy
{
    public const COMMIT = '/^[0-9a-f]{40}$/D';

    public const UNCHECKABLE_BRANCH = "This app's branch name is not one Coresmith can check. Change it in the app's settings.";

    public function checked(Application $application, ?string $sha, ?string $error): ?Deployment
    {
        $current = $application->fresh();

        if ($current === null) {
            return null;
        }

        $current->forceFill(['poll_error' => $error === null ? null : Str::limit($error, 255, '')])->saveQuietly();

        if ($sha !== null && $sha !== $current->repository_head && $current->repository_head === $application->repository_head) {
            return $this->observe($current, $sha, 'poll');
        }

        return $this->reconcile($current);
    }

    public function observe(Application $application, string $sha, string $source, ?CarbonInterface $pushedAt = null): ?Deployment
    {
        if (preg_match(self::COMMIT, $sha) !== 1 || $this->stale($application, $sha, $pushedAt)) {
            return null;
        }

        $application->forceFill([
            'repository_head' => $sha,
            'repository_head_source' => $source,
            'repository_head_at' => now(),
            ...($pushedAt === null ? [] : ['repository_pushed_at' => $pushedAt]),
        ])->saveQuietly();

        return $this->reconcile($application);
    }

    public function stale(Application $application, string $sha, ?CarbonInterface $pushedAt): bool
    {
        $last = $application->repository_pushed_at;

        if ($pushedAt === null || $last === null) {
            return false;
        }

        return $last->greaterThan($pushedAt) || ($last->equalTo($pushedAt) && $application->repository_head !== $sha);
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

        if (app(DeployGuard::class)->refusal($application) !== null) {
            return null;
        }

        $created = Cache::lock('auto-deploy:' . $application->getKey(), 10)->get(function () use ($application): ?Deployment {
            $deployments = $application->deployments()
                ->where(fn(Builder $attempt): Builder => $attempt
                    ->where('status', '!=', DeploymentStatus::Cancelled->value)
                    ->orWhereNotNull('cancelled_by'))
                ->latest('created_at');
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
