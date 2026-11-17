<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final class CanonicalAddress
{
    private const READABLE = [Server::class, Project::class, Application::class, ManagedDatabase::class, Deployment::class];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();

        if (!$response->isSuccessful() || !$route instanceof Route || $route->getName() === null || !$request->isMethodSafe() || $request->expectsJson()) {
            return $response;
        }

        foreach ($route->parameters() as $name => $value) {
            if ($value instanceof UrlRoutable && in_array($value::class, self::READABLE, true) && (string) $route->originalParameter($name) !== (string) $value->getRouteKey()) {
                $query = $request->getQueryString();

                return redirect()->to(route($route->getName(), $route->parameters()) . ($query === null ? '' : '?' . $query));
            }
        }

        return $response;
    }
}
