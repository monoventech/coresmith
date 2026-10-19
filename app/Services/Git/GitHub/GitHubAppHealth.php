<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

final class GitHubAppHealth
{
    public function hasWebhook(#[\SensitiveParameter] GitHubAppCredentials $app): bool
    {
        return $app->isEnv() ? $app->webhookSecret !== null : $app->hookUrl !== null;
    }
}
