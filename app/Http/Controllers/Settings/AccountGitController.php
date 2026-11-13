<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\GitHubAppRegistrar;
use App\Services\Git\GitHub\ManifestBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountGitController extends Controller
{
    public function show(Request $request, GitHubAppRegistrar $registrar, ManifestBuilder $manifest): Response
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $user = $request->user();
        $app = $this->ownApp($team, $user);
        $connection = SourceProvider::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->first();
        $canConnect = $user->hasTeamAbility($team, Ability::CreateApplication);

        return Inertia::render('account/Git', [
            'team' => ['name' => $team->name],
            'can' => ['connect' => $canConnect],
            'connection' => $connection === null ? null : [
                'account' => $connection->name,
                'connected' => $connection->connected(),
                'manage_url' => $connection->manageUrl(),
            ],
            'app' => $app === null ? null : [
                'name' => $app->name,
                'url' => $app->html_url,
                'install_href' => route('source-providers.connect', ['type' => 'github_app', 'app' => $app->getKey()]),
            ],
            'registration' => $app !== null || !$canConnect ? null : $this->registration($team, $user, $registrar, $manifest),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $user = $request->user();
        $app = $this->ownApp($team, $user);

        SourceProvider::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->get()->each->delete();
        $app?->delete();

        activity()->performedOn($team)->causedBy($user)->withProperties(['app' => $app?->name])->log('own_github_removed');

        return redirect()->route('account.git')->with('success', $app === null ? 'Removed.' : "Removed. Delete {$app->name} from your GitHub account too, at {$app->html_url}.");
    }

    public function destroyMember(Request $request, SourceProvider $sourceProvider): RedirectResponse
    {
        $team = $this->currentTeam();

        abort_unless($team !== null && $sourceProvider->team_id === $team->getKey() && $sourceProvider->user_id !== null, 404);
        $this->authorize('update', $team);

        $member = $sourceProvider->user;
        self::removeOwn($team, (string) $sourceProvider->user_id);

        activity()->performedOn($team)->causedBy($request->user())->withProperties(['member' => $member?->email, 'account' => $sourceProvider->name])->log('own_github_removed');

        return back()->with('success', 'Removed.');
    }

    public static function removeOwn(Team $team, string $userId): void
    {
        SourceProvider::query()->where('team_id', $team->getKey())->where('user_id', $userId)->get()->each->delete();
        GitHubApp::query()->where('team_id', $team->getKey())->where('user_id', $userId)->delete();
    }

    private function ownApp(Team $team, User $user): ?GitHubApp
    {
        return GitHubApp::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->first();
    }

    /**
     * @return array{state: string, key: string, action: string, manifest: array<string, mixed>, webhook: array<string, mixed>, name: string}
     */
    private function registration(Team $team, User $user, GitHubAppRegistrar $registrar, ManifestBuilder $manifest): array
    {
        ['state' => $state, 'key' => $key] = $registrar->intent($team, $user, personal: true);

        return [
            'state' => $state,
            'key' => $key,
            'action' => $manifest->personalAction($state),
            'manifest' => $manifest->base(),
            'webhook' => $manifest->webhook($key),
            'name' => $manifest->personalName($user),
        ];
    }
}
