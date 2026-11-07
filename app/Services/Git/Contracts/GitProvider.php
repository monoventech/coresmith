<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\Contracts;

use App\Services\Git\Repository;
use Illuminate\Support\Collection;

interface GitProvider
{
    /**
     * @return Collection<int, Repository>
     */
    public function listRepositories(): Collection;

    public function createWebhook(string $repository, string $url, string $secret): string;

    public function deleteWebhook(string $repository, string $hookId): void;

    public function createDeployKey(string $repository, string $publicKey, string $title): string;

    public function deleteDeployKey(string $repository, string $keyId): void;

    /**
     * @return array{sha: string, message: string, author: string}
     */
    public function latestCommit(string $repository, string $branch): array;

    public function updateCommitStatus(string $repository, string $sha, string $state, string $targetUrl, string $description): void;

    public function revoke(): void;
}
