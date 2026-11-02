<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Http\Controllers\Settings\SourceProviderConnectController;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Teams\TeamContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class GitHubConnector
{
    public const INTENT_MINUTES = 15;

    public const CHOICES_KEY = 'github_installation_choices';

    public const CHOICES_MINUTES = 10;

    public const DECLINED = 'GitHub did not grant access. Nothing was connected.';

    public const REFUSED = 'GitHub would not complete the connection. Nothing was saved.';

    public const UNREACHABLE = 'Your GitHub account cannot reach that installation, so nothing was connected.';

    public const EXPIRED = 'That choice expired. Connect GitHub again.';

    public const PROOF_PAGES = 30;

    public const TOO_MANY = 'This installation covers more repositories than Coresmith can check. Choose only the repositories Coresmith needs for the app on GitHub, then connect again.';

    public const LINK_LOCK_SECONDS = 10;

    public const LINK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly GitHubAppApi $api,
        private readonly GitHubApps $apps,
        private readonly RepositoryCatalog $catalog,
        private readonly InstallationChoices $choices,
        private readonly ManifestBuilder $manifest,
        private readonly GitHubSettingsPresenter $settings,
        private readonly TeamContext $context,
        private readonly ApplicationRelinker $relinker,
    ) {}

    public static function linkLock(Team $team, #[\SensitiveParameter] GitHubAppCredentials $app, string $installationId): string
    {
        return 'github:link:' . $team->getKey() . ':' . $app->key . ':' . $installationId;
    }

    public function appFor(Team $team, ?string $key = null): ?GitHubAppCredentials
    {
        $available = $this->apps->available($team);

        return $key === null ? ($available[0] ?? null) : collect($available)->firstWhere('key', '===', $key);
    }

    public function start(Team $team, #[\SensitiveParameter] GitHubAppCredentials $app, bool $link): string
    {
        $state = Str::random(40);

        session()->put(SourceProviderConnectController::STATE_KEY, [
            'state' => $state,
            'type' => SourceProviderType::GitHubApp->value,
            'team' => $team->getKey(),
            'app' => $app->key,
            'at' => now()->getTimestamp(),
            'bounced' => false,
        ]);

        return $link ? $this->authorizeUrl($app, $state) : $app->installUrl($state);
    }

    public function finish(Request $request): RedirectResponse
    {
        $intent = $this->intent($request);
        $state = $request->query('state');

        if ($state === null) {
            return $this->bounce($request, $intent);
        }

        if ($intent === null || !is_string($state) || !hash_equals($intent['state'], $state)) {
            return $this->refuse($request, null, SourceProviderConnectController::UNVERIFIED);
        }

        $request->session()->forget(SourceProviderConnectController::STATE_KEY);

        $team = Team::query()->find($intent['team']);

        abort_if($team === null, 404);
        Gate::forUser($request->user())->authorize('update', $team);

        $app = $this->appFor($team, $intent['app']);

        if ($app === null) {
            return $this->refuse($request, $team, SourceProviderConnectController::UNVERIFIED);
        }

        $code = $request->query('code');

        if ($request->query('error') !== null || !is_string($code) || $code === '') {
            return $this->refuse($request, $team, self::DECLINED);
        }

        try {
            $token = $this->api->exchangeUserCode($app, $code, $this->manifest->callbackUrl());
        } catch (GitProviderException) {
            return $this->refuse($request, $team, self::REFUSED);
        }

        try {
            return $this->settle($request, $team, $app, $token);
        } catch (GitProviderException) {
            return $this->refuse($request, $team, self::REFUSED);
        } finally {
            $this->quietly(fn() => $this->api->revokeUserToken($app, $token));
        }
    }

    public function choose(Request $request, string $installationId): RedirectResponse
    {
        $pending = $this->choices->pending($request->user());

        if ($pending === null) {
            return $this->refuse($request, null, self::EXPIRED);
        }

        $team = Team::query()->find($pending['team']);

        abort_if($team === null, 404);
        Gate::forUser($request->user())->authorize('update', $team);

        $app = $this->appFor($team, $pending['app']);

        if ($app === null) {
            return $this->refuse($request, $team, SourceProviderConnectController::UNVERIFIED);
        }

        $installation = $this->find($pending['installations'], $installationId);

        if ($installation === null) {
            return $this->refuse($request, $team, self::UNREACHABLE);
        }

        if ($installation['proven'] !== true) {
            return $this->refuse($request, $team, $installation['refusal'] ?? self::UNREACHABLE);
        }

        $this->choices->forget();

        return $this->connected($request, $team, $app, $installation);
    }

    /**
     * @param  array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string}  $installation
     */
    public function link(Team $team, #[\SensitiveParameter] GitHubAppCredentials $app, array $installation): SourceProvider
    {
        $provider = Cache::lock(self::linkLock($team, $app, $installation['id']), self::LINK_LOCK_SECONDS)
            ->block(self::LINK_WAIT_SECONDS, fn(): SourceProvider => $this->record($team, $app, $installation));

        $this->catalog->forget($provider);

        activity()
            ->performedOn($provider)
            ->withProperties(['installation_id' => $provider->installation_id, 'account' => $provider->name, 'app' => $app->key])
            ->log('github_installation_linked');

        $this->relinker->adopt($provider);

        return $provider;
    }

    /**
     * @param  array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string}  $installation
     */
    private function record(Team $team, #[\SensitiveParameter] GitHubAppCredentials $app, array $installation): SourceProvider
    {
        $provider = SourceProvider::withTrashed()->firstOrNew([
            'team_id' => $team->getKey(),
            'type' => SourceProviderType::GitHubApp->value,
            'github_app_id' => $app->isEnv() ? null : $app->key,
            'installation_id' => $installation['id'],
        ]);

        $provider->forceFill([
            'name' => $installation['account'],
            'account_type' => $installation['account_type'] === '' ? null : $installation['account_type'],
            'repository_selection' => $installation['repository_selection'] === '' ? null : $installation['repository_selection'],
            'suspended_at' => $installation['suspended_at'] === null ? null : CarbonImmutable::parse($installation['suspended_at']),
            'scopes' => SourceProviderType::GitHubApp->scopes(),
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ])->save();

        if ($provider->trashed()) {
            $provider->restore();
        }

        return $provider;
    }

    /**
     * @return array{state: string, team: string, app: string, at: int, bounced: bool}|null
     */
    private function intent(Request $request): ?array
    {
        $intent = $request->session()->get(SourceProviderConnectController::STATE_KEY);

        if (!is_array($intent) || ($intent['type'] ?? null) !== SourceProviderType::GitHubApp->value) {
            return null;
        }

        foreach (['state', 'team', 'app'] as $field) {
            if (!is_string($intent[$field] ?? null) || $intent[$field] === '') {
                return null;
            }
        }

        return [
            'state' => $intent['state'],
            'team' => $intent['team'],
            'app' => $intent['app'],
            'at' => (int) ($intent['at'] ?? 0),
            'bounced' => ($intent['bounced'] ?? false) === true,
        ];
    }

    /**
     * @param  array{state: string, team: string, app: string, at: int, bounced: bool}|null  $intent
     */
    private function bounce(Request $request, ?array $intent): RedirectResponse
    {
        if ($intent === null || $intent['bounced'] || $intent['at'] <= now()->subMinutes(self::INTENT_MINUTES)->getTimestamp()) {
            return $this->refuse($request, null, SourceProviderConnectController::UNVERIFIED);
        }

        $app = $this->apps->forKey($intent['app']);

        if ($app === null) {
            return $this->refuse($request, null, SourceProviderConnectController::UNVERIFIED);
        }

        $request->session()->put(SourceProviderConnectController::STATE_KEY . '.bounced', true);

        return redirect()->away($this->authorizeUrl($app, $intent['state']));
    }

    private function settle(Request $request, Team $team, #[\SensitiveParameter] GitHubAppCredentials $app, #[\SensitiveParameter] string $token): RedirectResponse
    {
        $installations = $this->api->userInstallations($token);

        if ($request->query('installation_id') !== null) {
            $installation = $this->find($installations, $request->query('installation_id'));

            return $installation === null
                ? $this->refuse($request, $team, self::UNREACHABLE)
                : $this->connectProven($request, $team, $app, $this->proven($app, [$installation], $token)[0]);
        }

        return match (count($installations)) {
            0 => $this->refuse($request, $team, "Your GitHub account cannot reach an installation of {$app->name}. Install it first, then connect."),
            1 => $this->connectProven($request, $team, $app, $this->proven($app, $installations, $token)[0]),
            default => $this->offer($request, $team, $app, $this->proven($app, $installations, $token)),
        };
    }

    /**
     * @param  list<array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string}>  $installations
     * @return list<array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string, proven: bool, refusal: ?string}>
     */
    private function proven(#[\SensitiveParameter] GitHubAppCredentials $app, array $installations, #[\SensitiveParameter] string $token): array
    {
        $signedIn = null;
        $proven = [];

        foreach ($installations as $installation) {
            $account = $installation['account'];

            try {
                if ($installation['account_type'] === 'User') {
                    $signedIn ??= $this->api->userLogin($token);
                    $refusal = $signedIn === $account ? null : "This installation belongs to {$account}. Sign in to GitHub as {$account} to connect it.";
                } else {
                    $refusal = $this->organizationRefusal($app, $installation, $token);
                }
            } catch (GitProviderException $e) {
                $refusal = "Coresmith could not check the installation on {$account}. {$e->getMessage()}";
            }

            $proven[] = [...$installation, 'proven' => $refusal === null, 'refusal' => $refusal];
        }

        return $proven;
    }

    /**
     * @param  array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string}  $installation
     */
    private function organizationRefusal(#[\SensitiveParameter] GitHubAppCredentials $app, array $installation, #[\SensitiveParameter] string $token): ?string
    {
        $checking = $this->api->mintToken($app, $installation['id'], ['permissions' => ['metadata' => 'read']])['token'];

        try {
            $covered = $this->api->installationRepositoryIds($checking, self::PROOF_PAGES);
        } finally {
            $this->quietly(fn() => $this->api->revokeToken($checking));
        }

        $visible = $covered === null ? null : $this->api->userRepositoryIds($token, $installation['id'], self::PROOF_PAGES);

        if ($covered === null || $visible === null) {
            return self::TOO_MANY;
        }

        return array_diff($covered, $visible) === []
            ? null
            : "Your GitHub account can't see every repository this installation covers, so Coresmith won't link it. Ask an owner of {$installation['account']} to connect GitHub.";
    }

    /**
     * @param  array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string, proven: bool, refusal: ?string}  $installation
     */
    private function connectProven(Request $request, Team $team, #[\SensitiveParameter] GitHubAppCredentials $app, array $installation): RedirectResponse
    {
        return $installation['proven'] === true
            ? $this->connected($request, $team, $app, $installation)
            : $this->refuse($request, $team, $installation['refusal'] ?? self::UNREACHABLE);
    }

    private function quietly(#[\SensitiveParameter] Closure $revoke): void
    {
        try {
            $revoke();
        } catch (GitProviderException $e) {
            report_if(app()->hasDebugModeEnabled(), $e);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $installations
     * @return array<string, mixed>|null
     */
    private function find(array $installations, mixed $id): ?array
    {
        if (!is_string($id) || preg_match('/^[0-9]{1,20}\z/', $id) !== 1) {
            return null;
        }

        foreach ($installations as $installation) {
            if ($installation['id'] === $id) {
                return $installation;
            }
        }

        return null;
    }

    /**
     * @param  array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string}  $installation
     */
    private function connected(Request $request, Team $team, #[\SensitiveParameter] GitHubAppCredentials $app, array $installation): RedirectResponse
    {
        $provider = $this->link($team, $app, $installation);

        return redirect()->to($this->settings->gitSettingsUrl($team, $request))
            ->with('success', "GitHub connected. Repositories on {$provider->name} can be picked when you add an app.");
    }

    /**
     * @param  list<array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string, proven: bool, refusal: ?string}>  $installations
     */
    private function offer(Request $request, Team $team, #[\SensitiveParameter] GitHubAppCredentials $app, array $installations): RedirectResponse
    {
        $this->choices->offer($team, $request->user(), $app->key, $installations);

        return redirect()->to($this->settings->gitSettingsUrl($team, $request));
    }

    private function refuse(Request $request, ?Team $team, string $message): RedirectResponse
    {
        $team ??= $this->context->team();

        return redirect()->to($team === null ? route('source-providers.index') : $this->settings->gitSettingsUrl($team, $request))
            ->with('error', $message);
    }

    private function authorizeUrl(#[\SensitiveParameter] GitHubAppCredentials $app, #[\SensitiveParameter] string $state): string
    {
        return GitHubAppApi::WEB . '/login/oauth/authorize?' . http_build_query([
            'client_id' => $app->clientId,
            'redirect_uri' => $this->manifest->callbackUrl(),
            'state' => $state,
        ]);
    }
}
