<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GitProviderException;
use App\Models\Application;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Deployment\GitFailure;
use App\Services\Git\GitProviderFactory;
use App\Services\SafeName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CheckGitHubHead implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('default');
    }

    public function handle(GitProviderFactory $providers, AutoDeploy $autoDeploy): void
    {
        $application = $this->application;
        $connection = $application->githubConnection();

        if (!$application->quick_deploy || $application->trashed() || $connection === null || !$application->usesGitHubToken()) {
            return;
        }

        if (!SafeName::isBranch($application->repository_branch)) {
            $autoDeploy->checked($application, null, AutoDeploy::UNCHECKABLE_BRANCH);

            return;
        }

        $sha = null;
        $error = null;

        try {
            $sha = $providers->github($connection)->branchHead((string) $application->repositoryFullName(), (string) $application->repository_branch, $application->repository_head);
        } catch (GitProviderException $refusal) {
            $error = $connection->markRefused($refusal) ? GitFailure::adviceFor($refusal, $application) : $refusal->getMessage();
        }

        $autoDeploy->checked($application, $sha, $error);
    }
}
