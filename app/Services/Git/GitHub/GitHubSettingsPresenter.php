<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\Request;

final class GitHubSettingsPresenter
{
    public function __construct(
        private readonly GitHubApps $apps,
        private readonly GitHubAppRegistrar $registrar,
        private readonly ManifestBuilder $manifest,
        private readonly PanelReachability $reachability,
        private readonly TeamContext $context,
        private readonly TeamUrls $urls,
    ) {}

    /**
     * @return array{can_register: bool, registration: ?array<string, mixed>, elsewhere: ?string}
     */
    public function present(Team $team, User $user): array
    {
        $canRegister = $user->can('update', $team) && $this->apps->canRegister() && !$team->githubApps()->exists();
        $viaHost = $this->context->viaHost();

        return [
            'can_register' => $canRegister,
            'registration' => $canRegister && !$viaHost ? $this->registration($team, $user) : null,
            'elsewhere' => $canRegister && $viaHost ? $this->gitSettingsUrl($team, request()) : null,
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
}
