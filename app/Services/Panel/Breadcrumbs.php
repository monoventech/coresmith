<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class Breadcrumbs
{
    private const SETTINGS = [
        'settings.general' => 'General',
        'teams.index' => 'Team',
        'source-providers.index' => 'Git',
        'settings.notifications' => 'Notifications',
        'api-tokens.index' => 'API tokens',
        'settings.billing' => 'Billing',
        'settings.audit-log' => 'Audit log',
    ];

    private const ACCOUNT = ['profile.edit' => 'Profile', 'security.edit' => 'Security'];

    private const SERVER_PAGES = [
        'servers.hardening' => 'Hardening',
        'servers.activity' => 'Activity',
        'servers.databases.index' => 'Databases',
        'servers.applications.create' => 'Add an app',
    ];

    private const APPLICATION_PAGES = ['servers.applications.releases.index' => 'Releases'];

    public function __construct(private readonly TeamAccess $access) {}

    /**
     * @return list<array{label: string, href: ?string, mono: bool}>
     */
    public function for(Request $request, ?Team $team, ?User $user): array
    {
        if ($team === null || $user === null) {
            return [];
        }

        $route = $request->route();
        $name = $route instanceof Route ? (string) $route->getName() : '';
        $fleet = self::crumb('Fleet', route('dashboard'));
        $settings = self::crumb('Settings', route('settings.general'));
        $project = $this->bound($route, Project::class);
        $server = $this->bound($route, Server::class);
        $application = $this->bound($route, Application::class);
        $database = $this->bound($route, ManagedDatabase::class);

        return [self::crumb($team->name, route('dashboard')), ...match (true) {
            $name === 'dashboard' => [self::crumb('Fleet')],
            $name === 'projects.index' => [$fleet, self::crumb('Projects')],
            $name === 'servers.create' => [$fleet, self::crumb('Provision a server')],
            $name === 'deployments.index' => [self::crumb('Deployments')],
            $name === 'releases.index' => [self::crumb('Releases')],
            $name === 'setup.show' => [self::crumb('Finish setup')],
            isset(self::SETTINGS[$name]) => [$settings, self::crumb(self::SETTINGS[$name])],
            $name === 'settings.removal.show' => [$settings, self::crumb('General', route('settings.general')), self::crumb('Remove Coresmith')],
            isset(self::ACCOUNT[$name]) => [self::crumb('Your account', route('profile.edit')), self::crumb(self::ACCOUNT[$name])],
            $name === 'projects.show' && $project instanceof Project => [self::crumb('Projects', route('projects.index')), self::crumb($project->name)],
            $application instanceof Application => $this->application($user, $application, $name, $this->bound($route, Deployment::class), $fleet),
            $database instanceof ManagedDatabase => [...$this->place($user, $database, $fleet), self::crumb($database->name, null, true)],
            $server instanceof Server => $this->server($server, $name, $fleet),
            default => [],
        }];
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function bound(?Route $route, string $class): ?object
    {
        foreach ($route?->parameters() ?? [] as $value) {
            if ($value instanceof $class) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array{label: string, href: ?string, mono: bool}  $fleet
     * @return list<array{label: string, href: ?string, mono: bool}>
     */
    private function server(Server $server, string $name, array $fleet): array
    {
        if ($name === 'servers.show') {
            return [$fleet, self::crumb($server->name, null, true)];
        }

        $trail = [$fleet, self::crumb($server->name, route('servers.show', $server), true)];

        return match (true) {
            $name === 'servers.databases.create' => [...$trail, self::crumb('Databases', route('servers.databases.index', $server)), self::crumb('Create a database')],
            isset(self::SERVER_PAGES[$name]) => [...$trail, self::crumb(self::SERVER_PAGES[$name])],
            default => $trail,
        };
    }

    /**
     * @param  array{label: string, href: ?string, mono: bool}  $fleet
     * @return list<array{label: string, href: ?string, mono: bool}>
     */
    private function application(User $user, Application $application, string $name, ?Deployment $deployment, array $fleet): array
    {
        $place = $this->place($user, $application, $fleet);

        if ($name === 'servers.applications.show') {
            return [...$place, self::crumb($application->name, null, true)];
        }

        $trail = [...$place, self::crumb($application->name, route('servers.applications.show', [$application->server_id, $application->getKey()]), true)];
        $release = $deployment instanceof Deployment ? Formats::release($deployment->release) : null;

        return match (true) {
            $name === 'servers.applications.deployments.show' => [...$trail, self::crumb('Deploy'), ...($release === null ? [] : [self::crumb($release, null, true)])],
            isset(self::APPLICATION_PAGES[$name]) => [...$trail, self::crumb(self::APPLICATION_PAGES[$name])],
            default => $trail,
        };
    }

    /**
     * @param  array{label: string, href: ?string, mono: bool}  $fleet
     * @return list<array{label: string, href: ?string, mono: bool}>
     */
    private function place(User $user, Application|ManagedDatabase $subject, array $fleet): array
    {
        $environment = $subject->environment;
        $project = $environment?->project;

        if ($project instanceof Project && $this->access->canSeeEnvironment($user, $environment)) {
            return [
                self::crumb($project->name, $this->access->canSeeProject($user, $project) ? route('projects.show', $project) : null),
                self::crumb($environment->name),
            ];
        }

        $server = $subject->server;

        return [$fleet, self::crumb((string) $server?->name, $server instanceof Server && $this->access->canSeeServer($user, $server) ? route('servers.show', $server) : null, true)];
    }

    /**
     * @return array{label: string, href: ?string, mono: bool}
     */
    private static function crumb(string $label, ?string $href = null, bool $mono = false): array
    {
        return ['label' => $label, 'href' => $href, 'mono' => $mono];
    }
}
