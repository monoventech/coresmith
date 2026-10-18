<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

final class GitHubInstallationException extends GitProviderException
{
    public const UNINSTALLED = 'uninstalled';

    public const SUSPENDED = 'suspended';

    public const REPOSITORY = 'repository';

    public const UNREACHABLE = 'unreachable';

    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }
}
