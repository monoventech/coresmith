<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GitProviderException;
use App\Models\SourceProvider;
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitHub\GitHubApps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class CheckGitHubInstallation implements ShouldQueue
{
    use Queueable;

    public const THROTTLE_SECONDS = 60;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(private readonly SourceProvider $provider)
    {
        $this->onQueue('default');
    }

    public static function throttled(SourceProvider $connection): void
    {
        if (!Cache::add('github:installation-check:' . config('app.env') . ':' . $connection->getKey(), true, self::THROTTLE_SECONDS)) {
            return;
        }

        rescue(function () use ($connection): void {
            self::dispatch($connection);
        }, report: true);
    }

    public function handle(GitHubApps $apps, GitHubAppApi $api): void
    {
        $connection = $this->provider->fresh();

        if ($connection === null || $connection->trashed() || $connection->suspended_at === null) {
            return;
        }

        $app = $apps->for($connection);

        if ($app === null) {
            return;
        }

        try {
            $installation = $api->installation($app, (string) $connection->installation_id);
        } catch (GitProviderException $e) {
            report_if(app()->hasDebugModeEnabled(), $e);

            return;
        }

        if ($installation === null) {
            $connection->markUninstalled();
        } elseif ($installation['suspended_at'] === null) {
            $connection->markSuspended(null);
        }
    }
}
