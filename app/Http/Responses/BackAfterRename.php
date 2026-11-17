<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class BackAfterRename
{
    public function __construct(private Router $router) {}

    public function to(Request $request, string $parameter, UrlRoutable $model): RedirectResponse
    {
        $previous = url()->previous();
        $path = (string) parse_url($previous, PHP_URL_PATH);
        $base = $request->getBaseUrl();

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }

        try {
            $route = $this->router->getRoutes()->match(Request::create($path));
        } catch (HttpException) {
            return back();
        }

        if ($route->getName() === null || !in_array($parameter, $route->parameterNames(), true)) {
            return back();
        }

        $query = (string) parse_url($previous, PHP_URL_QUERY);

        return redirect()->to(route($route->getName(), [...$route->parameters(), $parameter => $model]) . ($query === '' ? '' : '?' . $query));
    }
}
