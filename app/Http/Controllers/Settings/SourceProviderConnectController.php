<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Http\Controllers\Controller;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\GitHubConnector;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SourceProviderConnectController extends Controller
{
    public const STATE_KEY = 'source_provider_state';

    public const UNVERIFIED = 'That connection attempt could not be verified. Start again from this page.';

    private const GITHUB_ELSEWHERE = 'GitHub connects through its app installation, not this flow.';

    public function __construct(private readonly GitHubConnector $github) {}

    public function redirect(Request $request, string $type, TeamContext $context, TeamUrls $urls): RedirectResponse
    {
        $provider = $this->type($type);

        if ($context->viaHost()) {
            $query = $request->getQueryString();

            return redirect()->away($urls->canonical(route('source-providers.connect', $provider->value, false)) . ($query === null ? '' : '?' . $query));
        }

        if ($provider === SourceProviderType::GitHubApp) {
            return $this->startGitHub($request, $context);
        }

        $team = $this->team($request);

        abort_unless($this->isConfigured($provider), 404);

        $state = Str::random(40);

        $request->session()->put(self::STATE_KEY, ['state' => $state, 'type' => $provider->value, 'team' => $team->getKey()]);

        return redirect()->away($this->authorizeUrl($provider, $state));
    }

    public function callback(Request $request, string $type): RedirectResponse
    {
        $provider = $this->type($type);

        if ($provider === SourceProviderType::GitHubApp) {
            return $this->github->finish($request);
        }

        $expected = (array) $request->session()->pull(self::STATE_KEY, []);

        if (($expected['state'] ?? null) === null || !hash_equals((string) $expected['state'], (string) $request->query('state'))) {
            return redirect()->route('source-providers.index')
                ->with('error', self::UNVERIFIED);
        }

        if (($expected['type'] ?? null) !== $provider->value) {
            return redirect()->route('source-providers.index')
                ->with('error', 'That connection attempt was for a different provider. Start again from this page.');
        }

        $team = Team::find($expected['team'] ?? null);

        abort_if($team === null, 404);
        $this->authorize('update', $team);

        if ($request->query('error') !== null || $request->query('code') === null) {
            return redirect()->route('source-providers.index')
                ->with('error', 'The provider did not grant access. Nothing was connected.');
        }

        try {
            $this->store($provider, $team, (string) $request->query('code'), $request->user());
        } catch (GitProviderException $e) {
            return redirect()->route('source-providers.index')->with('error', $e->getMessage());
        }

        return redirect()->route('source-providers.index')
            ->with('success', $provider->label() . ' connected. Cloning still uses each application\'s own deploy key.');
    }

    public function chooseInstallation(Request $request): RedirectResponse
    {
        $installation = $request->input('installation_id');

        return $this->github->choose($request, is_int($installation) || is_string($installation) ? (string) $installation : '');
    }

    public function cancelChoice(): RedirectResponse
    {
        $this->github->cancelChoice();

        return back();
    }

    private function startGitHub(Request $request, TeamContext $context): RedirectResponse
    {
        $key = $request->query('app');

        abort_unless($key === null || is_string($key), 404);

        $own = is_string($key) ? GitHubApp::query()->whereKey($key)->whereNotNull('user_id')->first() : null;

        if ($own !== null) {
            abort_unless($own->user_id === $request->user()->getKey(), 404);

            $team = $this->teamOfApp($request, $context, (string) $key);

            abort_if($team === null, 404);
            abort_unless($request->user()->hasTeamAbility($team, Ability::CreateApplication), 403);

            return redirect()->away($this->github->start($team, app(GitHubApps::class)->fromModel($own), $request->query('mode') === 'link'));
        }

        $team = $key === null || $key === GitHubApps::ENV_KEY ? $this->currentTeam() : $this->teamOfApp($request, $context, $key);

        abort_if($team === null, 404);
        $this->authorize('update', $team);

        $app = $this->github->appFor($team, $key);

        abort_if($app === null, 404);

        return redirect()->away($this->github->start($team, $app, $request->query('mode') === 'link'));
    }

    private function teamOfApp(Request $request, TeamContext $context, string $key): ?Team
    {
        $team = GitHubApp::query()->find($key)?->team;

        if (!$team instanceof Team || !$request->user()->belongsToTeam($team)) {
            return null;
        }

        return $context->explicit() && $context->team()?->isNot($team) ? null : $team;
    }

    private function store(SourceProviderType $provider, Team $team, string $code, User $user): void
    {
        $tokens = $this->exchange($provider, $code);

        $source = SourceProvider::updateOrCreate(
            ['team_id' => $team->getKey(), 'type' => $provider->value],
            [
                'name' => $this->accountName($provider, (string) $tokens['access_token']),
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'installation_id' => null,
                'token_expires_at' => $tokens['expires_in'] === null ? null : now()->addSeconds($tokens['expires_in']),
                'scopes' => $provider->scopes(),
            ],
        );

        activity()
            ->performedOn($source)
            ->causedBy($user)
            ->withProperties(['type' => $provider->label(), 'name' => $source->name])
            ->log('source_provider_connected');
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    private function exchange(SourceProviderType $provider, string $code): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post($this->tokenUrl($provider), [
            'client_id' => (string) config("services.{$this->configKey($provider)}.client_id"),
            'client_secret' => (string) config("services.{$this->configKey($provider)}.client_secret"),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->callbackUrl($provider),
        ]);

        if ($response->failed() || $response->json('access_token') === null) {
            throw new GitProviderException($provider->label() . ' would not complete the connection. Nothing was saved.');
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') === null ? null : (string) $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in') === null ? null : (int) $response->json('expires_in'),
        ];
    }

    private function accountName(SourceProviderType $provider, string $token): string
    {
        $response = match ($provider) {
            SourceProviderType::GitLab => Http::withToken($token)->get('https://gitlab.com/api/v4/user'),
            SourceProviderType::Bitbucket => Http::withToken($token)->get('https://api.bitbucket.org/2.0/user'),
            SourceProviderType::GitHubApp, SourceProviderType::Custom => null,
        };

        if ($response === null || $response->failed()) {
            return $provider->label() . ' account';
        }

        foreach (['login', 'username', 'nickname', 'display_name'] as $key) {
            $value = $response->json($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $provider->label() . ' account';
    }

    private function authorizeUrl(SourceProviderType $provider, string $state): string
    {
        $redirect = $this->callbackUrl($provider);

        return match ($provider) {
            SourceProviderType::GitLab => 'https://gitlab.com/oauth/authorize?' . http_build_query([
                'client_id' => (string) config('services.gitlab.client_id'),
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'state' => $state,
                'scope' => implode(' ', $provider->scopes()),
            ]),
            SourceProviderType::Bitbucket => 'https://bitbucket.org/site/oauth2/authorize?' . http_build_query([
                'client_id' => (string) config('services.bitbucket.client_id'),
                'response_type' => 'code',
                'state' => $state,
            ]),
            SourceProviderType::GitHubApp => throw new GitProviderException(self::GITHUB_ELSEWHERE),
            SourceProviderType::Custom => throw new GitProviderException('A custom provider is connected with an SSH deploy key, not OAuth.'),
        };
    }

    private function callbackUrl(SourceProviderType $provider): string
    {
        return app(TeamUrls::class)->canonical(route('source-providers.callback', $provider->value, false));
    }

    private function tokenUrl(SourceProviderType $provider): string
    {
        return match ($provider) {
            SourceProviderType::GitLab => 'https://gitlab.com/oauth/token',
            SourceProviderType::Bitbucket => 'https://bitbucket.org/site/oauth2/access_token',
            SourceProviderType::GitHubApp => throw new GitProviderException(self::GITHUB_ELSEWHERE),
            SourceProviderType::Custom => throw new GitProviderException('A custom provider has no token endpoint.'),
        };
    }

    private function configKey(SourceProviderType $provider): string
    {
        return match ($provider) {
            SourceProviderType::GitLab => 'gitlab',
            SourceProviderType::Bitbucket => 'bitbucket',
            SourceProviderType::GitHubApp => throw new GitProviderException(self::GITHUB_ELSEWHERE),
            SourceProviderType::Custom => throw new GitProviderException('A custom provider has no credentials.'),
        };
    }

    private function isConfigured(SourceProviderType $provider): bool
    {
        return (string) config("services.{$this->configKey($provider)}.client_id") !== '';
    }

    private function type(string $type): SourceProviderType
    {
        $provider = SourceProviderType::tryFrom($type);

        abort_if($provider === null || !$provider->usesTokens(), 404);

        return $provider;
    }

    private function team(Request $request): Team
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);
        $this->authorize('update', $team);

        return $team;
    }
}
