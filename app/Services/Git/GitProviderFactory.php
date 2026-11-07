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
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\Providers\BitbucketClient;
use App\Services\Git\Providers\GitHubAppClient;
use App\Services\Git\Providers\GitLabClient;

final class GitProviderFactory
{
    public function __construct(
        private readonly GitHubApps $apps,
        private readonly GitHubAppApi $api,
    ) {}

    public function for(SourceProvider $provider): GitProvider
    {
        return match ($provider->type) {
            SourceProviderType::GitHubApp => $this->github($provider),
            SourceProviderType::GitLab => new GitLabClient($provider),
            SourceProviderType::Bitbucket => new BitbucketClient($provider),
            SourceProviderType::Custom => throw new GitProviderException('A custom provider is cloned over SSH and has no API to call.'),
        };
    }

    public function github(SourceProvider $provider): GitHubAppClient
    {
        if ($provider->type !== SourceProviderType::GitHubApp) {
            throw new GitProviderException('This connection is not a GitHub App.');
        }

        return new GitHubAppClient(
            $provider,
            $this->apps->for($provider) ?? throw new GitProviderException('This GitHub connection was made through an app this panel no longer has. Connect GitHub again from Settings, Git.'),
            $this->api,
        );
    }
}
