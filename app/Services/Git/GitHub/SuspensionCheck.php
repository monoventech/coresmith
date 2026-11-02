<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Exceptions\GitProviderException;
use App\Models\SourceProvider;

final class SuspensionCheck
{
    public function __construct(
        private readonly GitHubApps $apps,
        private readonly GitHubAppApi $api,
    ) {}

    public function recheck(SourceProvider $connection): void
    {
        if ($connection->trashed() || $connection->suspended_at === null) {
            return;
        }

        $app = $this->apps->for($connection);

        if ($app === null) {
            return;
        }

        try {
            $installation = $this->api->installation($app, (string) $connection->installation_id);
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
