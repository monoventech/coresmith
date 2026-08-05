<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Http\Controllers\Controller;
use App\Models\SourceProvider;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SourceProviderConnectController extends Controller
{
    public const STATE_KEY = 'source_provider_state';

    public function redirect(Request $request, string $type): RedirectResponse
    {
        $provider = $this->type($type);
        $team = $this->team($request);

        abort_unless($this->isConfigured($provider), 404);

        $state = Str::random(40);

        $request->session()->put(self::STATE_KEY, ['state' => $state, 'type' => $provider->value, 'team' => $team->getKey()]);

        return redirect()->away($this->authorizeUrl($provider, $state));
    }

    public function callback(Request $request, string $type): RedirectResponse
    {
        $provider = $this->type($type);
        $expected = (array) $request->session()->pull(self::STATE_KEY, []);

        if (($expected['state'] ?? null) === null || !hash_equals((string) $expected['state'], (string) $request->query('state'))) {
            return redirect()->route('source-providers.index')
                ->with('error', 'That connection attempt could not be verified. Start again from this page.');
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
            $this->store($provider, $team, (string) $request->query('code'), $request->query('installation_id'));
        } catch (GitProviderException $e) {
            return redirect()->route('source-providers.index')->with('error', $e->getMessage());
        }

        return redirect()->route('source-providers.index')
            ->with('success', $provider->label().' connected. Cloning still uses each application\'s own deploy key.');
    }

    private function store(SourceProviderType $provider, Team $team, string $code, ?string $installationId): void
    {
        $tokens = $this->exchange($provider, $code);

        SourceProvider::updateOrCreate(
            ['team_id' => $team->getKey(), 'type' => $provider->value],
            [
                'name' => $this->accountName($provider, (string) $tokens['access_token']),
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'installation_id' => $installationId,
                'token_expires_at' => $tokens['expires_in'] === null ? null : now()->addSeconds($tokens['expires_in']),
                'scopes' => $provider->scopes(),
            ],
        );
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
            'redirect_uri' => route('source-providers.callback', $provider->value),
        ]);

        if ($response->failed() || $response->json('access_token') === null) {
            throw new GitProviderException($provider->label().' would not complete the connection. Nothing was saved.');
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
            SourceProviderType::GitHubApp => Http::withToken($token)->get('https://api.github.com/user'),
            SourceProviderType::GitLab => Http::withToken($token)->get('https://gitlab.com/api/v4/user'),
            SourceProviderType::Bitbucket => Http::withToken($token)->get('https://api.bitbucket.org/2.0/user'),
            SourceProviderType::Custom => null,
        };

        if ($response === null || $response->failed()) {
            return $provider->label().' account';
        }

        foreach (['login', 'username', 'nickname', 'display_name'] as $key) {
            $value = $response->json($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $provider->label().' account';
    }

    private function authorizeUrl(SourceProviderType $provider, string $state): string
    {
        $redirect = route('source-providers.callback', $provider->value);

        return match ($provider) {
            SourceProviderType::GitHubApp => 'https://github.com/apps/'.rawurlencode((string) config('services.github.app_slug', 'coresmith')).'/installations/new?'
                .http_build_query(['state' => $state]),
            SourceProviderType::GitLab => 'https://gitlab.com/oauth/authorize?'.http_build_query([
                'client_id' => (string) config('services.gitlab.client_id'),
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'state' => $state,
                'scope' => implode(' ', $provider->scopes()),
            ]),
            SourceProviderType::Bitbucket => 'https://bitbucket.org/site/oauth2/authorize?'.http_build_query([
                'client_id' => (string) config('services.bitbucket.client_id'),
                'response_type' => 'code',
                'state' => $state,
            ]),
            SourceProviderType::Custom => throw new GitProviderException('A custom provider is connected with an SSH deploy key, not OAuth.'),
        };
    }

    private function tokenUrl(SourceProviderType $provider): string
    {
        return match ($provider) {
            SourceProviderType::GitHubApp => 'https://github.com/login/oauth/access_token',
            SourceProviderType::GitLab => 'https://gitlab.com/oauth/token',
            SourceProviderType::Bitbucket => 'https://bitbucket.org/site/oauth2/access_token',
            SourceProviderType::Custom => throw new GitProviderException('A custom provider has no token endpoint.'),
        };
    }

    private function configKey(SourceProviderType $provider): string
    {
        return match ($provider) {
            SourceProviderType::GitHubApp => 'github',
            SourceProviderType::GitLab => 'gitlab',
            SourceProviderType::Bitbucket => 'bitbucket',
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
        $team = $request->user()->teams()->first();

        abort_if($team === null, 404);
        $this->authorize('update', $team);

        return $team;
    }
}
