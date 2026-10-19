<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Exceptions\GitHubInstallationException;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Git\GitProviderFactory;
use App\Services\Git\Providers\GitHubAppClient;
use App\Services\Panel\DeploymentPresenter;
use App\Services\Panel\Formats;
use App\Services\Teams\TeamUrls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class PostCommitStatus implements ShouldQueue
{
    use Queueable;

    private const DESCRIPTION_LIMIT = 140;

    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 15;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(private readonly Deployment $deployment)
    {
        $this->onQueue('default');
    }

    public static function dispatchFor(Deployment $deployment): void
    {
        try {
            if ($deployment->application?->usesGitHubToken()) {
                self::dispatch($deployment);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(GitProviderFactory $providers, TeamUrls $urls): void
    {
        Cache::lock('commit-status:' . $this->deployment->getKey(), self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn() => $this->post($providers, $urls));
    }

    private function post(GitProviderFactory $providers, TeamUrls $urls): void
    {
        $deployment = $this->deployment->fresh();
        $application = $deployment?->application;
        $server = $application?->server;
        $repository = (string) $application?->repositoryFullName();
        $state = match ($deployment?->status) {
            DeploymentStatus::Running => 'pending',
            DeploymentStatus::Succeeded => 'success',
            DeploymentStatus::Failed => 'failure',
            default => null,
        };

        if ($state === null || $server === null || !$application->usesGitHubToken() || !GitHubAppClient::isFullName($repository) || preg_match(AutoDeploy::COMMIT, (string) $deployment->commit_hash) !== 1) {
            return;
        }

        $connection = $application->githubConnection();

        try {
            $providers->github($connection)->postDeployStatus(
                $repository,
                $deployment->commit_hash,
                $state,
                $urls->canonical(route('servers.applications.deployments.show', [$server, $application, $deployment], false)),
                Str::limit($this->description($deployment, $application, $server, $state), self::DESCRIPTION_LIMIT, ''),
                'coresmith/' . $application->slug,
            );
        } catch (GitHubInstallationException $refusal) {
            if (!$connection->markRefused($refusal)) {
                throw $refusal;
            }
        }
    }

    private function description(Deployment $deployment, Application $application, Server $server, string $state): string
    {
        return match (true) {
            $state === 'pending' => 'Deploying to ' . $server->name,
            $state === 'success' => 'Live on ' . $server->name . ' as ' . Formats::release($deployment->release),
            DeploymentPresenter::reachedUsers($deployment) => 'Deploy failed after going live, read the log',
            $application->current_release === null => 'Deploy failed, the app has no live release yet',
            default => 'Deploy failed, the previous release is still live',
        };
    }
}
