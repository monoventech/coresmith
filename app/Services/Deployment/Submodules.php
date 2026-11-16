<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Exceptions\SubmoduleRefused;

final class Submodules
{
    public const SSH_HOSTS = ['github.com', 'gitlab.com', 'bitbucket.org'];

    public const DEPTH = 3;

    /**
     * @return list<array{name: string, path: string, url: string}>
     */
    public static function entries(string $listed): array
    {
        $found = [];

        foreach (preg_split('/\r\n|\n|\r/', $listed) ?: [] as $line) {
            if (preg_match('/^submodule\.(.+)\.(path|url)=(.*)$/', $line, $matches) === 1) {
                $found[$matches[1]][$matches[2]] = $matches[3];
            }
        }

        $entries = [];

        foreach ($found as $name => $settings) {
            if (isset($settings['path'], $settings['url']) && $settings['path'] !== '' && $settings['url'] !== '') {
                $entries[] = ['name' => (string) $name, 'path' => $settings['path'], 'url' => $settings['url']];
            }
        }

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    public static function pins(string $tree): array
    {
        $pins = [];

        foreach (preg_split('/\r\n|\n|\r/', $tree) ?: [] as $line) {
            if (preg_match('/^160000 commit ([0-9a-f]{40})\t(.+)$/', $line, $matches) === 1) {
                $pins[$matches[2]] = $matches[1];
            }
        }

        return $pins;
    }

    public static function resolve(string $url, string $parent, string $path = ''): string
    {
        $resolved = str_starts_with($url, './') || str_starts_with($url, '../') ? self::relative($url, (string) self::https($parent)) : self::https($url);

        if ($resolved === null || preg_match('~^https://[a-z0-9.-]+(?::\d+)?/[^\s]+$~i', $resolved) !== 1) {
            throw new SubmoduleRefused("The submodule at {$path} points at {$url}, which Coresmith can't fetch. Change its url in .gitmodules to an https address.");
        }

        return $resolved;
    }

    public static function safePath(string $path): string
    {
        $segments = explode('/', $path);

        if ($path === '' || str_starts_with($path, '/') || in_array('..', $segments, true) || array_diff($segments, ['.', '']) === [] || preg_match('/[\x00-\x1f]/', $path) === 1) {
            throw new SubmoduleRefused("The submodule path {$path} is outside the repository, so Coresmith won't fetch it.");
        }

        return $path;
    }

    public static function githubName(string $url): ?string
    {
        return preg_match('~^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~', $url, $matches) === 1 ? strtolower($matches[1] . '/' . $matches[2]) : null;
    }

    public static function cache(string $repository, string $url): string
    {
        return $repository . '.modules/' . substr(hash('sha256', $url), 0, 16) . '.git';
    }

    private static function https(string $url): ?string
    {
        return match (true) {
            preg_match('~^ssh://(?:[^@/]+@)?([^/:]+)(?::\d+)?/(.+)$~', $url, $ssh) === 1 => in_array(strtolower($ssh[1]), self::SSH_HOSTS, true) ? 'https://' . strtolower($ssh[1]) . '/' . $ssh[2] : null,
            preg_match('~^[^@/:]+@([^/:]+):(?!/)(.+)$~', $url, $scp) === 1 => in_array(strtolower($scp[1]), self::SSH_HOSTS, true) ? 'https://' . strtolower($scp[1]) . '/' . $scp[2] : null,
            default => $url,
        };
    }

    private static function relative(string $url, string $parent): ?string
    {
        if (preg_match('~^(https://[^/]+)(/.*)?$~', $parent, $matches) !== 1) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $matches[2] ?? ''), fn(string $segment): bool => $segment !== ''));

        foreach (explode('/', $url) as $part) {
            if ($part === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);
            } elseif ($part !== '.' && $part !== '') {
                $segments[] = $part;
            }
        }

        return $segments === [] ? null : $matches[1] . '/' . implode('/', $segments);
    }
}
