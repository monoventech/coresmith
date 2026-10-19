<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\AutoDeploy;

use App\Models\Application;
use App\Services\Git\DeployKeyManager;
use App\Services\SafeName;
use InvalidArgumentException;

final class LsRemote
{
    public const TIMEOUT_SECONDS = 45;

    private const NOT_A_GIT_URL = "This app's repository address is not a git URL Coresmith can check. Fix it in the app's settings.";

    private const HEADS = 'refs/heads/';

    public function __construct(private readonly DeployKeyManager $keys) {}

    public function command(Application $application): string
    {
        $url = (string) $application->repository_url;

        if (preg_match(SafeName::GIT_URL_PATTERN, $url) !== 1) {
            throw new InvalidArgumentException(self::NOT_A_GIT_URL);
        }

        $command = 'GIT_TERMINAL_PROMPT=0 timeout ' . self::TIMEOUT_SECONDS . ' git ls-remote --exit-code -- '
            . escapeshellarg($url) . ' '
            . escapeshellarg(self::HEADS . SafeName::assertBranch($application->repository_branch));

        return $this->keys->usesDeployKey($application)
            ? $this->keys->withKey($application, $command, $this->keys->pollKeyPath($application))
            : $command;
    }

    public function parse(string $output, string $branch): ?string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $sha = strstr($line, "\t", true);

            if ($sha !== false && $line === $sha . "\t" . self::HEADS . $branch && preg_match(AutoDeploy::COMMIT, $sha) === 1) {
                return $sha;
            }
        }

        return null;
    }
}
