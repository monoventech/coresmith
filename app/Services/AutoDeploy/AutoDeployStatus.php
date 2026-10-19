<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\AutoDeploy;

use App\Models\Application;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\WebhookMode;
use App\Services\Panel\Formats;

final class AutoDeployStatus
{
    public const GITHUB = 'github';

    public const SERVER = 'server';

    private const NOT_LINKED = 'This app is not linked to a GitHub connection, so pushes are not delivered here.';

    private const REMOVED = 'Its GitHub connection was removed, so pushes are not delivered here.';

    private const SUSPENDED = 'Its GitHub installation is suspended, so pushes are not delivered here.';

    public function __construct(
        private readonly GitHubApps $apps,
        private readonly GitHubAppHealth $health,
    ) {}

    public function source(Application $application): string
    {
        return $application->usesGitHubToken() ? self::GITHUB : self::SERVER;
    }

    public function interval(Application $application): int
    {
        $mode = $this->mode($application);

        return match (true) {
            $mode === null => $this->minutes('server_poll_minutes'),
            $mode->instant => $this->minutes('safety_net_minutes'),
            default => $this->minutes('poll_minutes'),
        };
    }

    public function describe(Application $application): string
    {
        $branch = (string) $application->repository_branch;

        if (!$application->quick_deploy) {
            return "Deploy {$branch} whenever it changes";
        }

        $mode = $this->mode($application);
        $server = (string) $application->server?->name;

        $lead = match (true) {
            $mode === null => "Checks {$branch} for new commits {$this->every('server_poll_minutes')} from {$server}.",
            $mode->instant => "Deploys every push to {$branch}.",
            default => "Checks {$branch} for new commits {$this->every('poll_minutes')}.",
        };

        return $lead . ' ' . ($this->failure($application, $branch) ?? $mode?->sentence ?? $this->reason($application));
    }

    private function mode(Application $application): ?WebhookMode
    {
        $connection = $application->githubConnection();
        $app = $connection !== null && $application->usesGitHubToken() ? $this->apps->for($connection) : null;

        return $app === null ? null : $this->health->mode($app);
    }

    private function failure(Application $application, string $branch): ?string
    {
        if (!filled($application->poll_error)) {
            return null;
        }

        $clock = Formats::clock($application->polled_at);

        return "Could not check {$branch}" . ($clock === null ? '' : " at {$clock}") . ": {$application->poll_error}";
    }

    private function reason(Application $application): string
    {
        $connection = $application->githubConnection();

        return match (true) {
            $application->source_provider_id === null => self::NOT_LINKED,
            $connection !== null && !$connection->trashed() && $connection->suspended_at !== null => self::SUSPENDED,
            default => self::REMOVED,
        };
    }

    private function every(string $setting): string
    {
        $minutes = $this->minutes($setting);

        return $minutes === 1 ? 'every minute' : "every {$minutes} minutes";
    }

    private function minutes(string $setting): int
    {
        return max(1, (int) config("coresmith.auto_deploy.{$setting}"));
    }
}
