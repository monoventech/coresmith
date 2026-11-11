<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Ability;
use App\Enums\DeploymentStatus;
use App\Enums\Edition;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\BillingPresenter;
use App\Services\Teams\TeamAccess;

final class CommandPalette
{
    public function __construct(private readonly User $user, private readonly ?Team $team) {}

    /**
     * @return list<array{label: string, icon: string, hint: string, href?: string, action?: string}>
     */
    public function commands(): array
    {
        $commands = [];
        $fleet = $this->team === null ? null : new FleetSnapshot($this->team, $this->user);

        $running = $fleet?->running()->first();

        if ($running !== null && $running->application !== null) {
            $commands[] = [
                'label' => 'Watch the running deploy',
                'icon' => 'rocket',
                'hint' => implode(' · ', array_filter([$running->application->name, $running->branch, $running->commit_hash === null ? null : substr($running->commit_hash, 0, 7)])),
                'href' => route('servers.applications.deployments.show', [$running->application->server_id, $running->application_id, $running]),
            ];
        }

        foreach ($fleet?->servers() ?? [] as $server) {
            $commands[] = $this->openServer($server, $fleet);
        }

        if ($this->team !== null && $this->user->hasTeamAbility($this->team, Ability::CreateServer)) {
            $commands[] = ['label' => 'Provision a server', 'icon' => 'plus', 'hint' => 'about six minutes', 'href' => route('servers.create')];
        }

        $rollback = $this->rollbackCommand();

        if ($rollback !== null) {
            $commands[] = $rollback;
        }

        $commands[] = ['label' => 'Switch theme', 'icon' => 'sun-moon', 'hint' => 'light / dark', 'action' => 'theme'];
        $commands[] = ['label' => 'Deployments', 'icon' => 'rocket', 'hint' => 'fleet-wide history', 'href' => route('deployments.index')];
        $commands[] = ['label' => 'API tokens', 'icon' => 'key-round', 'hint' => 'settings', 'href' => route('api-tokens.index')];
        if (Edition::current()->isCloud()) {
            $commands[] = ['label' => 'Billing', 'icon' => 'credit-card', 'hint' => $this->team === null ? Edition::Cloud->label() : app(BillingPresenter::class)->paletteHint($this->team), 'href' => route('settings.billing')];
        }

        foreach ($this->team === null ? [] : app(TeamAccess::class)->applications($this->user, $this->team)->with('server')->orderBy('name')->get() as $application) {
            $commands[] = [
                'label' => 'Open ' . $application->name,
                'icon' => 'layers',
                'hint' => 'on ' . $application->server?->name,
                'href' => route('servers.applications.show', [$application->server_id, $application]),
            ];
        }

        return $commands;
    }

    /**
     * @return array{label: string, icon: string, hint: string, href: string}
     */
    private function openServer(Server $server, FleetSnapshot $fleet): array
    {
        $state = $fleet->state($server);
        $count = $server->applications->count();

        return [
            'label' => 'Open ' . $server->name,
            'icon' => in_array($state['tone'], ['warning', 'destructive'], true) ? 'triangle-alert' : 'server',
            'hint' => $state['key'] === 'healthy'
                ? implode(' · ', array_filter([$server->environment === null ? null : strtolower($server->environment), $count . ' ' . ($count === 1 ? 'app' : 'apps')]))
                : strtolower($state['label']),
            'href' => route('servers.show', $server),
        ];
    }

    /**
     * @return array{label: string, icon: string, hint: string, href: string}|null
     */
    private function rollbackCommand(): ?array
    {
        if ($this->team === null) {
            return null;
        }

        $latest = Deployment::query()
            ->whereIn('application_id', app(TeamAccess::class)->applications($this->user, $this->team)->select('id'))
            ->where('status', DeploymentStatus::Succeeded->value)
            ->whereNotNull('release')
            ->with('application')
            ->latest('finished_at')
            ->first();

        $application = $latest?->application;

        if (!$application instanceof Application || !$this->user->can('deploy', $application)) {
            return null;
        }

        $previous = $application->deployments()
            ->where('status', DeploymentStatus::Succeeded->value)
            ->whereNotNull('release')
            ->where('release', '!=', $application->current_release)
            ->latest('finished_at')
            ->first();

        if ($previous === null) {
            return null;
        }

        return [
            'label' => 'Roll back ' . $application->name,
            'icon' => 'undo-2',
            'hint' => 'to ' . Formats::release($previous->release),
            'href' => route('servers.applications.releases.index', [$application->server_id, $application, 'rollback' => $previous->release]),
        ];
    }
}
