<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;

final readonly class TeamLinks
{
    public function __construct(private TeamUrls $urls, private RouteKeys $keys) {}

    public function server(Server $server): string
    {
        return $this->under($server->team, route('servers.show', $server, false));
    }

    public function application(Application $application): string
    {
        return $this->under($application->team, route('servers.applications.show', [$this->keys->server($application->server_id), $application], false));
    }

    public function database(ManagedDatabase $database): string
    {
        return $this->under($database->team, route('servers.databases.show', [$this->keys->server($database->server_id), $database], false));
    }

    public function deployment(Application $application, Deployment $deployment): string
    {
        return $this->under($application->team, route('servers.applications.deployments.show', [$this->keys->server($application->server_id), $application, $deployment], false));
    }

    private function under(?Team $team, string $path): string
    {
        return $team === null || blank($team->slug) ? $this->urls->canonical($path) : $this->urls->pathUrl($team) . $path;
    }
}
