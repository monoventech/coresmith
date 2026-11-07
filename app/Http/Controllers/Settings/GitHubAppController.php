<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Http\Controllers\Controller;
use App\Models\GitHubApp;
use App\Models\Team;
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubAppRegistrar;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\GitHubSettingsPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

final class GitHubAppController extends Controller
{
    public function __construct(
        private readonly GitHubAppRegistrar $registrar,
        private readonly GitHubSettingsPresenter $settings,
        private readonly GitHubApps $apps,
        private readonly GitHubAppHealth $health,
        private readonly GitHubAppApi $api,
    ) {}

    public function registered(Request $request): RedirectResponse
    {
        $user = $request->user();
        $current = $this->currentTeam();

        abort_if($current === null, 404);

        $query = Validator::make($request->query(), [
            'code' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:64'],
        ]);

        if ($query->fails()) {
            return $this->refused($current, $request, GitHubAppRegistrar::UNVERIFIED);
        }

        $state = (string) $query->validated()['state'];
        $team = $this->registrar->teamFor($state, $user) ?? $current;

        try {
            $app = $this->registrar->complete($state, (string) $query->validated()['code'], $user);
        } catch (GitProviderException $e) {
            return $this->refused($team, $request, $e->getMessage());
        }

        return redirect()->route('source-providers.connect', ['type' => SourceProviderType::GitHubApp->value, 'app' => $app->getKey()]);
    }

    public function webhook(Request $request, GitHubApp $githubApp): RedirectResponse
    {
        $this->manage($githubApp);

        $app = $this->apps->fromModel($githubApp);
        $url = $this->health->expectedHookUrl($app);
        $secret = bin2hex(random_bytes(32));

        try {
            $this->api->updateHook($app, $url, $secret);
        } catch (GitProviderException $e) {
            return back()->with('error', 'GitHub did not change the webhook: ' . rtrim($e->getMessage(), '.') . '. Try again in a minute.');
        }

        $githubApp->forceFill(['hook_url' => $url, 'webhook_secret' => $secret])->save();

        $this->health->repointed($app);

        return back()->with('success', 'Saved. On GitHub, check the app\'s webhook is active and sends push events.');
    }

    public function destroy(Request $request, GitHubApp $githubApp): RedirectResponse
    {
        $this->manage($githubApp);

        if ($githubApp->connections()->exists()) {
            return back()->with('error', 'Disconnect its installations first.');
        }

        Cache::forget($this->health->key($this->apps->fromModel($githubApp)));

        $githubApp->delete();

        activity()
            ->performedOn($githubApp)
            ->causedBy($request->user())
            ->withProperties(['team_id' => $githubApp->team_id, 'name' => $githubApp->name, 'app_id' => $githubApp->app_id, 'slug' => $githubApp->slug, 'owner' => $githubApp->owner_login])
            ->log('github_app_removed');

        return back()->with('success', 'Removed. Delete the app on GitHub to finish.');
    }

    private function manage(GitHubApp $githubApp): void
    {
        $team = $this->currentTeam();

        abort_unless($team !== null && $githubApp->team_id === $team->getKey(), 404);
        $this->authorize('update', $team);
    }

    private function refused(Team $team, Request $request, string $message): RedirectResponse
    {
        return redirect()->to($this->settings->gitSettingsUrl($team, $request))->with('error', $message);
    }
}
