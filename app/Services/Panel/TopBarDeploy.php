<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\User;
use App\Services\Teams\RouteKeys;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class TopBarDeploy
{
    private const PAGES = ['servers.applications.show' => 'app', 'servers.applications.releases.index' => 'releases'];

    /**
     * @return array{name: string, href: string, from: string, running_href: ?string, blocked: bool}|null
     */
    public function for(Request $request, ?User $user): ?array
    {
        $route = $request->route();
        $name = $route instanceof Route ? (string) $route->getName() : '';
        $application = $route instanceof Route ? $route->parameter('application') : null;

        if (!isset(self::PAGES[$name]) || !$application instanceof Application || !$user instanceof User || !$user->can('deploy', $application)) {
            return null;
        }

        $from = self::PAGES[$name];
        $latest = $application->deployments()->latest('created_at')->first();

        return [
            'name' => $application->name,
            'href' => route('servers.applications.deploy', [app(RouteKeys::class)->server($application->server_id), $application]),
            'from' => $from,
            'running_href' => $latest?->status === DeploymentStatus::Running ? route('servers.applications.deployments.show', [app(RouteKeys::class)->server($application->server_id), $application, $latest, 'from' => $from]) : null,
            'blocked' => $from === 'app' && $application->needsRepositoryCheck(),
        ];
    }
}
