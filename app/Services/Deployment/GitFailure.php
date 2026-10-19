<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitProviderException;
use App\Models\Application;

final readonly class GitFailure
{
    private function __construct(
        public string $reason,
        public bool $retryable,
    ) {}

    public static function from(string $output, Application $application, ?string $advice = null): self
    {
        $url = self::displayUrl((string) $application->repository_url);
        $host = self::host($url);
        $branch = (string) $application->repository_branch;

        return match (true) {
            self::mentions($output, ['Repository not found', 'remote: Not Found', 'does not appear to be a git repository']) => new self("Repository not found at {$url}. Check the URL is right and the repository still exists, then deploy again.", false),
            self::mentions($output, ['Permission denied (publickey)', 'Could not read from remote repository', 'Authentication failed', 'could not read Username', 'terminal prompts disabled']) => new self($advice ?? self::unreadable($application, $host), false),
            self::mentions($output, ["Remote branch {$branch} not found", 'did not match any', 'not a valid object name', 'unknown revision', "couldn't find remote ref"]) => new self("Branch {$branch} is not in the repository. Check the branch name in the app's settings, then deploy again.", false),
            self::mentions($output, ['Could not resolve host']) => new self("The server could not look up {$host}. Check its DNS settings, then deploy again.", true),
            self::mentions($output, ['Connection timed out', 'Connection refused', 'Operation timed out', 'Failed to connect', 'early EOF', 'RPC failed', 'Connection reset', 'The remote end hung up unexpectedly']) => new self("The server could not reach {$host}. Check its firewall allows outbound HTTPS and SSH, then deploy again.", true),
            default => new self('Git failed with an error Coresmith does not recognise. Its full output is in the log.', false),
        };
    }

    public static function adviceFor(GitProviderException $refusal, Application $application): string
    {
        $account = (string) $application->githubConnection()?->name;

        return match ($refusal instanceof GitHubInstallationException ? $refusal->kind : null) {
            GitHubInstallationException::UNINSTALLED => "Coresmith's GitHub app is no longer installed on {$account}. Connect GitHub again from Settings, Git, then deploy.",
            GitHubInstallationException::SUSPENDED => self::suspended($account),
            GitHubInstallationException::REPOSITORY => 'GitHub no longer gives Coresmith access to ' . self::repository($application) . '. Add it under Manage repositories on GitHub, then deploy again.',
            GitHubInstallationException::UNREACHABLE => 'Coresmith could not get a clone token from GitHub. Try again in a minute.',
            default => $refusal->getMessage(),
        };
    }

    private static function unreadable(Application $application, string $host): string
    {
        if ($application->source_provider_id === null) {
            return "Coresmith could not read the repository on {$host}. If it is private, connect your Git provider in Settings, Git, or add the app's deploy key to the repository.";
        }

        $connection = $application->githubConnection();
        $repository = self::repository($application);

        return match (true) {
            $connection !== null && !$connection->trashed() && $connection->suspended_at !== null => self::suspended((string) $connection->name),
            !$application->usesGitHubToken() => "This app's GitHub connection was removed, so the private repository {$repository} could not be read. Connect GitHub again from Settings, Git, then deploy.",
            default => "GitHub did not let Coresmith read {$repository}. Check the repository is still selected for the Coresmith app on GitHub, then deploy again.",
        };
    }

    private static function suspended(string $account): string
    {
        return "Coresmith's GitHub app is suspended on {$account}. Unsuspend it on GitHub, then deploy.";
    }

    private static function repository(Application $application): string
    {
        return $application->repositoryFullName() ?? self::displayUrl((string) $application->repository_url);
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
