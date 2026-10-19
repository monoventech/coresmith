<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Enums\SourceProviderType;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class GitHubSettingsPresenter
{
    private const PERMISSIONS = 'contents read-only, commit statuses';

    public function __construct(
        private readonly GitHubApps $apps,
        private readonly GitHubAppRegistrar $registrar,
        private readonly ManifestBuilder $manifest,
        private readonly PanelReachability $reachability,
        private readonly TeamContext $context,
        private readonly TeamUrls $urls,
        private readonly RepositoryCatalog $catalog,
        private readonly GitHubAppHealth $health,
        private readonly InstallationChoices $choices,
    ) {}

    /**
     * @return array{can_register: bool, registration: ?array<string, mixed>, elsewhere: ?string, connections: list<array<string, mixed>>, apps: list<array<string, mixed>>, connect: ?array{install_href: string, link_href: string}, choose: ?array{app: string, installations: list<array{id: string, account: string, account_type: string, repository_selection: string}>, post_href: string}, can: array{manage: bool}}
     */
    public function present(Team $team, User $user): array
    {
        $canManage = $user->can('update', $team);
        $available = $this->apps->available($team);
        $canRegister = $canManage && $this->apps->canRegister() && $available === [];
        $viaHost = $this->context->viaHost();
        $connections = $team->sourceProviders()
            ->where('type', SourceProviderType::GitHubApp->value)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return [
            'can_register' => $canRegister,
            'registration' => $canRegister && !$viaHost ? $this->registration($team, $user) : null,
            'elsewhere' => $canRegister && $viaHost ? $this->gitSettingsUrl($team, request()) : null,
            'connections' => $connections->map(fn(SourceProvider $provider): array => $this->connection($provider))->values()->all(),
            'apps' => $this->registered($available, $connections),
            'connect' => $canManage && $available !== [] ? $this->connectLinks($available[0]->key) : null,
            'choose' => $canManage ? $this->choice($team, $user, $available) : null,
            'can' => ['manage' => $canManage],
        ];
    }

    public function gitSettingsUrl(Team $team, Request $request): string
    {
        return $this->urls->pathUrl($team, $request) . route('source-providers.index', [], false);
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(Team $team, User $user): array
    {
        ['state' => $state, 'key' => $key] = $this->registrar->intent($team, $user);
        $verdict = $this->reachability->verdict();

        return [
            'state' => $state,
            'key' => $key,
            'personal_action' => $this->manifest->personalAction($state),
            'organization_action' => $this->manifest->organizationAction($state),
            'manifest' => $this->manifest->base(),
            'webhook' => $this->manifest->webhook($key),
            'name' => $this->manifest->defaultName($team),
            'reachability' => [
                'reachable' => $verdict->reachable,
                'host' => $verdict->host,
                'sentence' => $verdict->sentence(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(SourceProvider $provider): array
    {
        $app = $this->apps->for($provider);
        $expires = $provider->token_expires_at;
        $holdsToken = $provider->getRawOriginal('access_token') !== null && $expires !== null && $expires->isFuture();

        return [
            'id' => $provider->id,
            'account' => $provider->name,
            'account_type' => $provider->account_type,
            'repository_selection' => $provider->repository_selection,
            'repositories' => $this->catalog->cached($provider)['total'] ?? null,
            'repositories_href' => route('source-providers.repositories', $provider),
            'permissions' => self::PERMISSIONS . ($app !== null && $this->health->hasWebhook($app) ? ', webhooks' : ''),
            'token_expires_at' => $holdsToken ? $expires->toIso8601String() : null,
            'suspended' => $provider->suspended_at !== null,
            'manage_url' => $provider->manageUrl(),
            'app_name' => $app?->name,
            'disconnect_href' => route('source-providers.destroy', $provider),
        ];
    }

    /**
     * @param  list<GitHubAppCredentials>  $available
     * @param  Collection<int, SourceProvider>  $connections
     * @return list<array<string, mixed>>
     */
    private function registered(#[\SensitiveParameter] array $available, Collection $connections): array
    {
        $installed = $connections->pluck('github_app_id')->filter()->all();
        $registered = [];

        foreach ($available as $app) {
            if ($app->isEnv()) {
                continue;
            }

            $registered[] = [
                'key' => $app->key,
                'name' => $app->name,
                'slug' => $app->slug,
                'owner' => $app->ownerLogin,
                'owner_type' => $app->ownerType,
                'settings_url' => $app->settingsUrl(),
                'installed' => in_array($app->key, $installed, true),
                'install_href' => route('source-providers.connect', ['type' => SourceProviderType::GitHubApp->value, 'app' => $app->key]),
            ];
        }

        return $registered;
    }

    /**
     * @return array{install_href: string, link_href: string}
     */
    private function connectLinks(string $key): array
    {
        return [
            'install_href' => route('source-providers.connect', ['type' => SourceProviderType::GitHubApp->value, 'app' => $key]),
            'link_href' => route('source-providers.connect', ['type' => SourceProviderType::GitHubApp->value, 'app' => $key, 'mode' => 'link']),
        ];
    }

    /**
     * @param  list<GitHubAppCredentials>  $available
     * @return array{app: string, installations: list<array{id: string, account: string, account_type: string, repository_selection: string}>, post_href: string}|null
     */
    private function choice(Team $team, User $user, #[\SensitiveParameter] array $available): ?array
    {
        $pending = $this->choices->pending($user);
        $app = $pending === null ? null : collect($available)->firstWhere('key', '===', $pending['app']);

        if ($pending === null || $pending['team'] !== (string) $team->getKey() || !$app instanceof GitHubAppCredentials) {
            return null;
        }

        return [
            'app' => $app->name,
            'installations' => array_map(fn(array $installation): array => [
                'id' => $installation['id'],
                'account' => $installation['account'],
                'account_type' => $installation['account_type'],
                'repository_selection' => $installation['repository_selection'],
            ], $pending['installations']),
            'post_href' => route('github.installations.store'),
        ];
    }
}
