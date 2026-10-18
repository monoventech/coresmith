<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

final class RepositoryCache
{
    public const FETCH_REFSPEC = '+refs/heads/*:refs/heads/*';

    public function cloneArguments(string $url, string $repo): string
    {
        return 'clone --bare ' . escapeshellarg($url) . ' ' . $repo;
    }

    public function setUrlCommand(string $repo, string $url): string
    {
        return 'git --git-dir=' . $repo . ' remote set-url origin ' . escapeshellarg($url);
    }

    public function fetchArguments(string $repo): string
    {
        return '--git-dir=' . $repo . ' fetch --prune --tags origin ' . escapeshellarg(self::FETCH_REFSPEC);
    }
}
