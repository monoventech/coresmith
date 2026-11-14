<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final class PythonSource
{
    /**
     * @param  list<string>  $root
     * @param  list<string>  $nested
     * @return array<string, string>
     */
    public static function candidates(RepositoryFacts $facts, array $root, array $nested): array
    {
        $files = [];

        foreach ($root as $file) {
            if ($facts->file($file) !== null) {
                $files[$file] = (string) $facts->file($file);
            }
        }

        $deeper = array_filter($facts->matching('*/*.py'), fn(string $path): bool => in_array(basename($path), $nested, true), ARRAY_FILTER_USE_KEY);

        return [...$files, ...$deeper];
    }

    public static function module(string $path): ?string
    {
        $module = str_replace('/', '.', (string) preg_replace('~(?:/__init__)?\.py$~', '', $path));

        return preg_match('/^[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*$/', $module) === 1 ? $module : null;
    }

    /**
     * @param  list<string>  $callables
     */
    public static function assigned(string $source, array $callables): ?string
    {
        $pattern = '/^([A-Za-z_]\w*)\s*(?::[^=\n]+)?=\s*(?:[A-Za-z_][\w.]*\.)?(?:' . implode('|', array_map(fn(string $callable): string => preg_quote($callable, '/'), $callables)) . ')\s*\(/m';

        return preg_match($pattern, $source, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param  list<string>  $names
     */
    public static function factory(string $source, array $names): ?string
    {
        $pattern = '/^(?:async\s+)?def\s+(' . implode('|', array_map(fn(string $name): string => preg_quote($name, '/'), $names)) . ')\s*\(([^)]*)\)/m';

        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        foreach ($matches as [, $name, $parameters]) {
            $required = array_filter(array_map(trim(...), explode(',', $parameters)), fn(string $parameter): bool => $parameter !== '' && $parameter !== '/' && !str_contains($parameter, '=') && !str_starts_with($parameter, '*'));

            if ($required === []) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $callables
     * @param  list<string>  $factories
     */
    public static function find(array $files, array $callables, array $factories): ?string
    {
        foreach ($files as $path => $source) {
            $module = self::module($path);
            $variable = $module === null ? null : self::assigned($source, $callables);

            if ($variable !== null) {
                return $module . ':' . $variable;
            }

            $factory = $module === null ? null : self::factory($source, $factories);

            if ($factory !== null) {
                return $module . ':' . $factory . '()';
            }
        }

        return null;
    }
}
