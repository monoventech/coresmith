<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Models\Team;
use App\Models\User;

final class InstallationChoices
{
    /**
     * @param  list<array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string, user_login: ?string, user_repositories: ?int}>  $installations
     */
    public function offer(Team $team, User $user, string $app, array $installations): void
    {
        session()->put(GitHubConnector::CHOICES_KEY, [
            'team' => (string) $team->getKey(),
            'user' => (string) $user->getKey(),
            'app' => $app,
            'installations' => $installations,
            'at' => now()->getTimestamp(),
        ]);
    }

    /**
     * @return array{team: string, app: string, installations: list<array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string, user_login: ?string, user_repositories: ?int}>}|null
     */
    public function pending(User $user): ?array
    {
        $choices = session()->get(GitHubConnector::CHOICES_KEY);

        if (!is_array($choices) || (int) ($choices['at'] ?? 0) <= now()->subMinutes(GitHubConnector::CHOICES_MINUTES)->getTimestamp()) {
            return null;
        }

        foreach (['team', 'user', 'app'] as $field) {
            if (!is_string($choices[$field] ?? null) || $choices[$field] === '') {
                return null;
            }
        }

        if ($choices['user'] !== (string) $user->getKey() || !is_array($choices['installations'] ?? null)) {
            return null;
        }

        $installations = [];

        foreach ($choices['installations'] as $installation) {
            if (!is_array($installation)) {
                return null;
            }

            foreach (['id', 'account', 'account_type', 'repository_selection'] as $field) {
                if (!is_string($installation[$field] ?? null)) {
                    return null;
                }
            }

            $installations[] = [
                'id' => $installation['id'],
                'account' => $installation['account'],
                'account_type' => $installation['account_type'],
                'repository_selection' => $installation['repository_selection'],
                'suspended_at' => is_string($installation['suspended_at'] ?? null) ? $installation['suspended_at'] : null,
                'user_login' => is_string($installation['user_login'] ?? null) ? $installation['user_login'] : null,
                'user_repositories' => is_int($installation['user_repositories'] ?? null) ? $installation['user_repositories'] : null,
            ];
        }

        return ['team' => $choices['team'], 'app' => $choices['app'], 'installations' => $installations];
    }

    public function forget(): void
    {
        session()->forget(GitHubConnector::CHOICES_KEY);
    }
}
