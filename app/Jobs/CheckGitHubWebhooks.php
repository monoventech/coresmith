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
        $env = $apps->env();

        if ($env !== null) {
            $this->check($env, $api, $health);
        }

        GitHubApp::query()->orderBy('id')->each(fn(GitHubApp $app) => $this->check($apps->fromModel($app), $api, $health));

        SourceProvider::query()
            ->where('type', SourceProviderType::GitHubApp->value)
            ->whereNotNull('suspended_at')
            ->orderBy('id')
            ->each(function (SourceProvider $connection): void {
                CheckGitHubInstallation::throttled($connection);
            });
    }

    private function check(#[\SensitiveParameter] GitHubAppCredentials $app, GitHubAppApi $api, GitHubAppHealth $health): void
    {
        if (!$health->hasWebhook($app)) {
            return;
        }

        try {
            $hookUrl = $app->isEnv() ? $api->hookUrl($app) : null;

            $health->checked($app, $api->latestDelivery($app), $hookUrl);
        } catch (GitProviderException $e) {
            report_if(app()->hasDebugModeEnabled(), $e);
        }
    }
}
