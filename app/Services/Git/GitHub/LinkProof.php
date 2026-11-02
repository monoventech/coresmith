<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitHubLinkProofException;
use App\Exceptions\GitProviderException;
use App\Models\SourceProvider;
use App\Services\Git\GitProviderFactory;
use Illuminate\Support\Facades\Log;

final class LinkProof
{
    public const NO_ANSWER = 'GitHub did not answer. Try again in a minute.';

    private const CAN_READ = ['admin', 'write', 'read'];

    public function __construct(private readonly GitProviderFactory $clients) {}

    public function check(SourceProvider $connection, string $repository): void
    {
        if ($connection->github_app_id !== null) {
            return;
        }

        $id = (string) $connection->proof_github_id;
        $login = (string) $connection->proof_github_login;

        if ($id === '' || $login === '') {
            throw new GitHubLinkProofException("Coresmith has no record of who connected this GitHub account, so this connection cannot deploy {$repository}. Someone who can read it connects GitHub again from Settings, Git.");
        }

        try {
            [$login, $answer] = $this->ask($connection, $repository, $id, $login);
        } catch (GitHubLinkProofException | GitHubInstallationException $refusal) {
            throw $refusal;
        } catch (GitProviderException $failure) {
            Log::warning('GitHub could not answer a repository permission check.', [
                'connection' => $connection->getKey(),
                'repository' => $repository,
                'status' => $failure->getCode(),
                'reason' => $failure->getMessage(),
            ]);

            throw new GitHubLinkProofException(self::NO_ANSWER);
        }

        if ($answer === null) {
            throw new GitHubInstallationException(RepositoryCatalog::NOT_VISIBLE, GitHubInstallationException::REPOSITORY);
        }

        if (!in_array($answer['permission'], self::CAN_READ, true) || !$this->isThePerson($answer, $id)) {
            throw new GitHubLinkProofException(self::cannotRead($login, $repository));
        }
    }

    private static function cannotRead(string $login, string $repository): string
    {
        return "{$login} can no longer read {$repository} on GitHub, so this connection cannot deploy it. Someone who can read it connects GitHub again from Settings, Git.";
    }

    /**
     * @return array{string, array{permission: string, user_id: ?string}|null}
     */
    private function ask(SourceProvider $connection, string $repository, string $id, string $login): array
    {
        $client = $this->clients->github($connection);
        $answer = $client->repositoryPermission($repository, $login);

        if ($answer !== null && $this->isThePerson($answer, $id)) {
            return [$login, $answer];
        }

        $current = $client->loginOfUser($id);

        if ($current === null) {
            throw new GitHubLinkProofException(self::cannotRead($login, $repository));
        }

        if ($current === $login) {
            return [$login, $answer];
        }

        $connection->forceFill(['proof_github_login' => $current])->save();

        return [$current, $client->repositoryPermission($repository, $current)];
    }

    /**
     * @param  array{permission: string, user_id: ?string}  $answer
     */
    private function isThePerson(array $answer, string $id): bool
    {
        return $answer['user_id'] === null || $answer['user_id'] === $id;
    }
}
