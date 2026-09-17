<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;

final readonly class GitFailure
{
    private function __construct(
        public string $reason,
        public bool $retryable,
    ) {}

    public static function from(string $output, Application $application): self
    {
        $url = self::displayUrl((string) $application->repository_url);
        $host = self::host($url);
        $branch = (string) $application->repository_branch;

        return match (true) {
            self::mentions($output, ['Repository not found', 'remote: Not Found', 'does not appear to be a git repository']) => new self("Repository not found at {$url}. Check the URL is right and the repository still exists, then deploy again.", false),
            self::mentions($output, ['Permission denied (publickey)', 'Could not read from remote repository', 'Authentication failed', 'could not read Username', 'terminal prompts disabled']) => new self("Coresmith could not read the repository on {$host}. If it is private, connect your Git provider in Settings, Git, or add the app's deploy key to the repository.", false),
            self::mentions($output, ["Remote branch {$branch} not found", 'did not match any', 'not a valid object name', 'unknown revision', "couldn't find remote ref"]) => new self("Branch {$branch} is not in the repository. Check the branch name in the app's settings, then deploy again.", false),
            self::mentions($output, ['Could not resolve host']) => new self("The server could not look up {$host}. Check its DNS settings, then deploy again.", true),
            self::mentions($output, ['Connection timed out', 'Connection refused', 'Operation timed out', 'Failed to connect', 'early EOF', 'RPC failed', 'Connection reset', 'The remote end hung up unexpectedly']) => new self("The server could not reach {$host}. Check its firewall allows outbound HTTPS and SSH, then deploy again.", true),
            default => new self('Git failed with an error Coresmith does not recognise. Its full output is in the log.', false),
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private static function mentions(string $output, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function displayUrl(string $url): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*://)(?:[^@/]+@)?(.*)$#i', $url, $matches) === 1) {
            return $matches[1] . $matches[2];
        }

        return $url;
    }

    private static function host(string $url): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://(?:[^@/]+@)?([^/:]+)#i', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[^@\s]+@([^:\s]+):/', $url, $matches) === 1) {
            return $matches[1];
        }

        return 'the Git host';
    }
}
