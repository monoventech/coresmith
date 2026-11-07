<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\AutoDeploy\AutoDeployStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\LazyCollection;

final class PollRepositories implements ShouldQueue
{
    use Queueable;

    private const EARLY_SECONDS = 5;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(AutoDeployStatus $status): void
    {
        $fromServers = [];

        $this->candidates()->each(function (Application $application) use ($status, &$fromServers): void {
            if ($this->deploying($application) || !$this->due($application, $status)) {
                return;
            }

            if ($status->source($application) === AutoDeployStatus::SERVER) {
                $fromServers[$application->server_id][] = $application;

                if (count($fromServers[$application->server_id]) === CheckRemoteHeads::APPLICATIONS_PER_JOB) {
                    $this->checkFromServer($fromServers[$application->server_id]);

                    unset($fromServers[$application->server_id]);
                }

                return;
            }

            $application->forceFill(['polled_at' => now()])->saveQuietly();

            CheckGitHubHead::dispatch($application);
        });

        foreach ($fromServers as $applications) {
            $this->checkFromServer($applications);
        }
    }

    /**
     * @param  non-empty-list<Application>  $applications
     */
    private function checkFromServer(array $applications): void
    {
        CheckRemoteHeads::dispatch($applications[0]->server, array_map(fn(Application $application): string => (string) $application->getKey(), $applications));
    }

    /**
     * @return LazyCollection<int, Application>
     */
    private function candidates(): LazyCollection
    {
        return Application::query()
            ->where('quick_deploy', true)
            ->whereNotNull('repository_url')
            ->whereHas('server', fn(Builder $server): Builder => $server->where('status', ServerStatus::Active->value))
            ->with(['server', 'sourceProvider'])
            ->addSelect(['latest_deployment_status' => Deployment::query()
                ->select('status')
                ->whereColumn('application_id', 'applications.id')
                ->where('status', '!=', DeploymentStatus::Cancelled->value)
                ->latest('created_at')
                ->limit(1)])
            ->lazyById();
    }

    private function deploying(Application $application): bool
    {
        return in_array($application->getAttribute('latest_deployment_status'), [DeploymentStatus::Pending->value, DeploymentStatus::Running->value], true);
    }

    private function due(Application $application, AutoDeployStatus $status): bool
    {
        return $application->polled_at === null
            || $application->polled_at->lte(now()->subSeconds($status->interval($application) * 60 - self::EARLY_SECONDS));
    }
}
