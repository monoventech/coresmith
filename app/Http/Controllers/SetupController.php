<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\GitHubSettingsPresenter;
use App\Services\Git\Repository;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Panel\CloudPresenter;
use App\Services\Panel\ServerPresenter;
use App\Services\Panel\SetupProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SetupController extends Controller
{
    public function show(Request $request, ServerPresenter $servers, GitHubSettingsPresenter $github, CloudPresenter $cloud): RedirectResponse|Response
    {
        $user = $request->user();
        $team = $this->currentTeam();

        if ($team === null) {
            return to_route('dashboard');
        }

        $progress = new SetupProgress($team);
        $done = $progress->done();
        $current = $progress->current();
        $requested = (int) $request->query('step', (string) $current);
        $step = max(0, min($requested, $current));
        $account = $request->query('account');
        $account = is_string($account) ? $account : null;

        $server = $team->servers()->whereNot('status', ServerStatus::Active->value)->latest()->first()
            ?? $team->servers()->latest()->first();
        $application = $team->applications()->with('server')->latest()->first();

        return Inertia::render('setup/Show', [
            'steps' => array_map(fn(string $label, int $index): array => ['label' => $label, 'done' => $done[$index]], SetupProgress::STEPS, array_keys(SetupProgress::STEPS)),
            'current' => $current,
            'step' => $step,
            'complete' => $progress->complete(),
            'github' => fn(): ?array => $step === 0 ? $this->github($github, $team, $user) : null,
            'team' => ['id' => $team->id],
            'cloud' => fn(): ?array => $step === 1 ? $cloud->choices($team, $user, $account) : null,
            ...($step === 1 ? ['catalog' => Inertia::defer(function () use ($cloud, $team, $account): ?array {
                $selected = $cloud->selectedAccount($team, $account);

                return $selected === null ? null : $cloud->catalog($selected);
            })] : []),
            'environments' => ServerController::ENVIRONMENTS,
            'server' => $server instanceof Server ? [
                ...$servers->header($server, $user),
                'href' => route('servers.show', $server),
                'test_href' => route('servers.test-connection', $server),
                'provision_href' => route('servers.provision', $server),
            ] : null,
            'application' => $application instanceof Application ? [
                'id' => $application->id,
                'name' => $application->name,
                'repository' => Repository::slug((string) $application->repository_url) ?? $application->repository_url,
                'kind' => ApplicationPresenter::kind($application),
                'runtime' => ApplicationPresenter::runtime($application),
                'server' => $application->server?->name,
                'deploy_href' => route('servers.applications.deploy', [$application->server_id, $application]),
            ] : null,
            'create_application_href' => $server instanceof Server && $server->status === ServerStatus::Active
                ? route('servers.applications.create', $server)
                : null,
        ]);
    }

    /**
     * @return array{connected: bool, name: ?string, label: ?string, connect_href: ?string, registration: ?array<string, mixed>, elsewhere: ?string, can_manage: bool, settings_href: string}
     */
    private function github(GitHubSettingsPresenter $presenter, Team $team, User $user): array
    {
        $presenter->recheckSuspended($team);

        $github = $presenter->present($team, $user);
        $connection = $github['connections'][0] ?? null;

        return [
            'connected' => $connection !== null,
            'name' => $connection['account'] ?? null,
            'label' => $connection === null ? null : SourceProviderType::GitHubApp->label(),
            'connect_href' => $github['connect']['install_href'] ?? null,
            'registration' => $github['registration'],
            'elsewhere' => $github['elsewhere'],
            'can_manage' => $github['can']['manage'],
            'settings_href' => route('source-providers.index'),
        ];
    }
}
