<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Git\GitHub\GitHubAppRegistrar;
use App\Services\Git\GitHub\GitHubSettingsPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class GitHubAppController extends Controller
{
    public function __construct(
        private readonly GitHubAppRegistrar $registrar,
        private readonly GitHubSettingsPresenter $settings,
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

    private function refused(Team $team, Request $request, string $message): RedirectResponse
    {
        return redirect()->to($this->settings->gitSettingsUrl($team, $request))->with('error', $message);
    }
}
