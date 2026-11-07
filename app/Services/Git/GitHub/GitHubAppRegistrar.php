<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Exceptions\GitProviderException;
use App\Models\GitHubApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class GitHubAppRegistrar
{
    public const SESSION_KEY = 'github_app_registrations';

    public const KEEP = 5;

    public const TTL_MINUTES = 60;

    public const UNVERIFIED = 'That GitHub request could not be verified. Start again from Settings, Git.';

    public const NOT_CREATED = 'GitHub did not finish creating the app, so nothing was saved. Try again from Settings, Git.';

    public function __construct(
        private readonly GitHubAppApi $api,
        private readonly GitHubApps $apps,
    ) {}

    /**
     * @return array{state: string, key: string}
     */
    public function intent(Team $team, User $user): array
    {
        $state = Str::random(40);
        $key = strtolower((string) Str::ulid());
        $waiting = $this->waiting();

        $waiting[$state] = [
            'team' => (string) $team->getKey(),
            'user' => (string) $user->getKey(),
            'key' => $key,
            'at' => now()->getTimestamp(),
        ];

        session()->put(self::SESSION_KEY, array_slice($waiting, -self::KEEP, null, true));

        return ['state' => $state, 'key' => $key];
    }

    public function teamFor(#[\SensitiveParameter] string $state, User $user): ?Team
    {
        $waiting = $this->waiting();
        $stored = $this->find($waiting, $state, $user);

        return $stored === null ? null : Team::query()->find($waiting[$stored]['team']);
    }

    public function complete(#[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code, User $user): GitHubApp
    {
        $waiting = $this->waiting();
        $stored = $this->find($waiting, $state, $user);

        if ($stored === null) {
            throw new GitProviderException(self::UNVERIFIED);
        }

        $intent = $waiting[$stored];

        unset($waiting[$stored]);
        session()->put(self::SESSION_KEY, $waiting);

        $team = Team::query()->find($intent['team']);

        if (!$team instanceof Team) {
            throw new GitProviderException(self::UNVERIFIED);
        }

        Gate::forUser($user)->authorize('update', $team);

        try {
            $converted = $this->api->convertManifest($code);
        } catch (GitProviderException $e) {
            throw new GitProviderException(self::NOT_CREATED, previous: $e);
        }

        $app = $this->store($intent['key'], $team, $user, $converted);

        try {
            $hookUrl = $this->api->hookUrl($this->apps->fromModel($app));
        } catch (GitProviderException) {
            $hookUrl = null;
        }

        if ($hookUrl !== null) {
            $app->forceFill(['hook_url' => $hookUrl])->save();
        }

        activity()
            ->performedOn($app)
            ->causedBy($user)
            ->withProperties(['team_id' => $app->team_id, 'name' => $app->name, 'app_id' => $app->app_id, 'slug' => $app->slug, 'owner' => $app->owner_login])
            ->log('github_app_registered');

        return $app;
    }

    /**
     * @param  array<string, mixed>  $converted
     */
    private function store(string $key, Team $team, User $user, #[\SensitiveParameter] array $converted): GitHubApp
    {
        $app = new GitHubApp;

        $app->forceFill([
            'id' => $key,
            'team_id' => $team->getKey(),
            'app_id' => $converted['app_id'],
            'slug' => $converted['slug'],
            'name' => $converted['name'],
            'client_id' => $converted['client_id'],
            'client_secret' => $converted['client_secret'],
            'webhook_secret' => $converted['webhook_secret'],
            'private_key' => $converted['pem'],
            'owner_login' => $converted['owner_login'],
            'owner_type' => $converted['owner_type'],
            'html_url' => $converted['html_url'],
            'permissions' => $converted['permissions'],
            'events' => $converted['events'],
            'hook_url' => null,
            'created_by' => $user->getKey(),
        ])->save();

        return $app;
    }

    /**
     * @return array<string, array{team: string, user: string, key: string, at: int}>
     */
    private function waiting(): array
    {
        $cutoff = now()->subMinutes(self::TTL_MINUTES)->getTimestamp();
        $waiting = [];

        foreach ((array) session()->get(self::SESSION_KEY, []) as $state => $entry) {
            if (!is_array($entry) || (int) ($entry['at'] ?? 0) <= $cutoff) {
                continue;
            }

            foreach (['team', 'user', 'key'] as $field) {
                if (!is_string($entry[$field] ?? null) || $entry[$field] === '') {
                    continue 2;
                }
            }

            $waiting[(string) $state] = [
                'team' => $entry['team'],
                'user' => $entry['user'],
                'key' => $entry['key'],
                'at' => (int) $entry['at'],
            ];
        }

        return $waiting;
    }

    /**
     * @param  array<string, array{team: string, user: string, key: string, at: int}>  $waiting
     */
    private function find(array $waiting, string $state, User $user): ?string
    {
        $found = null;

        foreach ($waiting as $stored => $entry) {
            if (hash_equals((string) $stored, $state) && $entry['user'] === (string) $user->getKey()) {
                $found = (string) $stored;
            }
        }

        return $found;
    }
}
