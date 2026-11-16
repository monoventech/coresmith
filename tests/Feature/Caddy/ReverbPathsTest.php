<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

test('no page lives under the paths Caddy hands to Reverb on the panel host', function () {
    preg_match_all('/@reverb path ([^\n]+)/', (string) file_get_contents(resource_path('caddy/panel.caddy')), $matches);
    $prefixes = collect($matches[1])->flatMap(fn(string $line): array => preg_split('/\s+/', trim($line)) ?: [])->map(fn(string $pattern): string => rtrim($pattern, '*'))->unique()->values();
    $clashes = collect(Route::getRoutes()->getRoutes())
        ->map(fn(RouteDefinition $route): string => '/' . ltrim($route->uri(), '/'))
        ->filter(fn(string $uri): bool => $prefixes->contains(fn(string $prefix): bool => str_starts_with($uri, $prefix)))
        ->values()
        ->all();

    expect($prefixes->all())->toContain('/app/', '/apps/')
        ->and($clashes)->toBe([]);
});
