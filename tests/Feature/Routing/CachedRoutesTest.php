<?php

declare(strict_types=1);

use Illuminate\Http\Request;

test('cached routes send every request to the same action as uncached routes', function () {
    $router = app('router');
    $routes = $router->getRoutes();
    $compiled = $routes->toCompiledRouteCollection($router, app());
    $mismatches = [];

    foreach ($routes->getRoutes() as $route) {
        $uri = '/' . ltrim((string) preg_replace('/\{[^}]+\}/', 'x', $route->uri()), '/');

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $request = Request::create($uri, $method);

            try {
                $uncached = $routes->match($request);
            } catch (Throwable) {
                continue;
            }

            try {
                $cached = $compiled->match($request);
            } catch (Throwable $e) {
                $mismatches[] = "{$method} {$uri}: cached routes failed with " . class_basename($e);

                continue;
            }

            if ($cached->getName() !== $uncached->getName() || $cached->getActionName() !== $uncached->getActionName()) {
                $mismatches[] = "{$method} {$uri}: uncached {$uncached->getName()} ({$uncached->getActionName()}), cached {$cached->getName()} ({$cached->getActionName()})";
            }
        }
    }

    expect(array_values(array_unique($mismatches)))->toBe([]);
});

test('adding a server posts to the server store, not the servers redirect', function () {
    $router = app('router');
    $compiled = $router->getRoutes()->toCompiledRouteCollection($router, app());

    expect($compiled->match(Request::create('/servers', 'POST'))->getName())->toBe('servers.store')
        ->and($compiled->match(Request::create('/servers', 'GET'))->getName())->toBe('servers.index');
});
