<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git;

final readonly class Repository
{
    public function __construct(
        public string $fullName,
        public string $sshUrl,
        public string $defaultBranch,
        public bool $private,
    ) {}

    public static function slug(string $url): ?string
    {
        if (preg_match('~^(?:https://[^/\s]+/|git@[^:\s]+:)([A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)+)/?$~D', trim($url), $matches) !== 1) {
            return null;
        }

        return preg_replace('/\.git$/', '', $matches[1]);
    }

    /**
     * @return array{full_name: string, ssh_url: string, default_branch: string, private: bool}
     */
    public function toArray(): array
    {
        return [
            'full_name' => $this->fullName,
            'ssh_url' => $this->sshUrl,
            'default_branch' => $this->defaultBranch,
            'private' => $this->private,
        ];
    }
}
