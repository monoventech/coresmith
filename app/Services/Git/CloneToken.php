<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Application;
use App\Services\SafeName;

final class CloneToken
{
    public const SUFFIX = '.git-token';

    public function path(Application $application): string
    {
        return DeployKeyManager::KEY_DIR . '/' . SafeName::assertSlug($application->slug) . self::SUFFIX;
    }

    public function content(#[\SensitiveParameter] string $token): string
    {
        return "username=x-access-token\npassword={$token}\n";
    }

    public function reserveCommand(Application $application): string
    {
        return 'install -m 600 /dev/null ' . escapeshellarg($this->path($application));
    }

    public function removeCommand(Application $application): string
    {
        return 'rm -f ' . escapeshellarg($this->path($application));
    }

    public function wrap(Application $application, string $gitArguments): string
    {
        $helper = 'credential.helper=!f() { test "$1" = get && grep -qx host=github.com && cat ' . escapeshellarg($this->path($application)) . '; }; f';

        return 'GIT_TERMINAL_PROMPT=0 git -c credential.helper= -c ' . escapeshellarg($helper) . ' ' . $gitArguments;
    }
}
