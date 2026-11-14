<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Services\SafeName;

final class AppFolders
{
    public const LISTING = "git ls-tree -r --name-only HEAD 2>/dev/null | grep -E '(^|/)(composer\\.json|Gemfile|package\\.json|deno\\.jsonc?|index\\.html|requirements\\.txt|pyproject\\.toml|Pipfile|go\\.mod)$' | head -n 2000 | sed 's/^/@@manifest /'";

    private const MANIFESTS = ['composer.json', 'Gemfile', 'package.json', 'deno.json', 'deno.jsonc', 'index.html', 'requirements.txt', 'pyproject.toml', 'Pipfile', 'go.mod'];

    private const DEPTH = 3;

    private const DEPENDENCIES = ['node_modules', 'vendor', 'bower_components', 'venv', 'site-packages', '__pycache__'];

    private const NOT_SITES = ['public', 'dist', 'build', 'out', 'static', 'src', 'assets', 'templates'];

    /**
     * @return list<string>
     */
    public static function in(string $output): array
    {
        preg_match_all('/^@@manifest (.+)$/m', $output, $matches);
        $folders = [];

        foreach ($matches[1] as $path) {
            $segments = explode('/', trim($path));
            $file = array_pop($segments);
            $folder = implode('/', $segments);

            if ($segments === []
                || count($segments) > self::DEPTH
                || !in_array($file, self::MANIFESTS, true)
                || array_filter($segments, fn(string $segment): bool => str_starts_with($segment, '.') || in_array($segment, self::DEPENDENCIES, true)) !== []
                || ($file === 'index.html' && in_array($segments[array_key_last($segments)], self::NOT_SITES, true))
                || preg_match(SafeName::RELATIVE_PATH_PATTERN, $folder) !== 1) {
                continue;
            }

            $folders[$folder] = true;
        }

        $folders = array_map(strval(...), array_keys($folders));
        sort($folders);

        return $folders;
    }
}
