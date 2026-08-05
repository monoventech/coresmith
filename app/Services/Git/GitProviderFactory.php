<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git;

use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Models\SourceProvider;
use App\Services\Git\Contracts\GitProvider;
use App\Services\Git\Providers\BitbucketClient;
use App\Services\Git\Providers\GitHubAppClient;
use App\Services\Git\Providers\GitLabClient;

final class GitProviderFactory
{
    public function for(SourceProvider $provider): GitProvider
    {
        return match ($provider->type) {
            SourceProviderType::GitHubApp => new GitHubAppClient($provider),
            SourceProviderType::GitLab => new GitLabClient($provider),
            SourceProviderType::Bitbucket => new BitbucketClient($provider),
            SourceProviderType::Custom => throw new GitProviderException('A custom provider is cloned over SSH and has no API to call.'),
        };
    }
}
