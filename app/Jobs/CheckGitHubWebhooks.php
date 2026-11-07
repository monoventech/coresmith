<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubApps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CheckGitHubWebhooks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(GitHubApps $apps, GitHubAppApi $api, GitHubAppHealth $health): void
    {
        SourceProvider::query()
            ->where('type', SourceProviderType::GitHubApp->value)
            ->whereNotNull('suspended_at')
            ->orderBy('id')
            ->each(function (SourceProvider $connection): void {
                CheckGitHubInstallation::throttled($connection);
            });

        $env = $apps->env();

        if ($env !== null) {
            $this->check($env, $api, $health);
        }

        GitHubApp::query()->orderBy('id')->each(fn(GitHubApp $app) => $this->check($apps->fromModel($app), $api, $health));
    }

    /**
     * @return list<string>|null
     */
    private function events(#[\SensitiveParameter] GitHubAppCredentials $app, GitHubAppApi $api): ?array
    {
        try {
            return $api->appEvents($app);
        } catch (GitProviderException $e) {
            report_if(app()->hasDebugModeEnabled(), $e);

            return null;
        }
    }

    private function check(#[\SensitiveParameter] GitHubAppCredentials $app, GitHubAppApi $api, GitHubAppHealth $health): void
    {
        if (!$health->hasWebhook($app)) {
            return;
        }

        try {
            $latest = $api->latestDelivery($app);

            $health->checked($app, $latest, $this->events($app, $api));
        } catch (GitProviderException $e) {
            report_if(app()->hasDebugModeEnabled(), $e);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
