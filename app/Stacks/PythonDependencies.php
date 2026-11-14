<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final readonly class PythonDependencies
{
    public const LISTS = ['requirements.txt', 'pyproject.toml', 'Pipfile'];

    private const REQUIREMENT = '/^\s*([A-Za-z0-9][A-Za-z0-9._-]*)\s*(?:\[[^\]]*\])?\s*(.*)$/';

    /**
     * @param  array<string, array{file: string, spec: string}>  $found
     */
    private function __construct(private array $found) {}

    public static function from(RepositoryFacts $facts): self
    {
        $found = [];

        self::requirements($facts, 'requirements.txt', $found);

        $project = $facts->toml('pyproject.toml', 'project')['dependencies'] ?? [];

        foreach (is_array($project) ? $project : [] as $requirement) {
            if (preg_match(self::REQUIREMENT, $requirement, $matches) === 1) {
                $found[self::normalize($matches[1])] ??= ['file' => 'pyproject.toml', 'spec' => trim($matches[2])];
            }
        }

        foreach (['pyproject.toml' => 'tool.poetry.dependencies', 'Pipfile' => 'packages'] as $file => $table) {
            foreach ($facts->toml($file, $table) as $name => $spec) {
                if (strtolower($name) !== 'python') {
                    $found[self::normalize($name)] ??= ['file' => $file, 'spec' => self::tableSpec($spec)];
                }
            }
        }

        return new self($found);
    }

    /**
     * @param  array<string, array{file: string, spec: string}>  $found
     * @param  list<string>  $seen
     */
    private static function requirements(RepositoryFacts $facts, string $file, array &$found, array $seen = []): void
    {
        $content = $facts->file($file);

        if ($content === null || in_array($file, $seen, true) || count($seen) > 8) {
            return;
        }

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $line = (string) preg_replace('/(^|\s)#.*$/', '', $line);

            if (preg_match('/^\s*(?:-r|--requirement)[\s=]+(\S+)/', $line, $include) === 1) {
                self::requirements($facts, self::relative($file, $include[1]), $found, [...$seen, $file]);
            } elseif (preg_match(self::REQUIREMENT, $line, $matches) === 1) {
                $found[self::normalize($matches[1])] ??= ['file' => $file, 'spec' => trim($matches[2])];
            }
        }
    }

    private static function relative(string $from, string $path): string
    {
        $parts = [];

        foreach (explode('/', (dirname($from) === '.' ? '' : dirname($from) . '/') . $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    public static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[-_.]+/', '-', $name));
    }

    public static function list(RepositoryFacts $facts): ?string
    {
        foreach (self::LISTS as $file) {
            if ($facts->has($file)) {
                return $file;
            }
        }

        return null;
    }

    public function any(): bool
    {
        return $this->found !== [];
    }

    public function has(string $name): bool
    {
        return isset($this->found[self::normalize($name)]);
    }

    public function file(string $name): ?string
    {
        return $this->found[self::normalize($name)]['file'] ?? null;
    }

    public function pinned(string $name): ?string
    {
        $spec = $this->found[self::normalize($name)]['spec'] ?? '';

        return preg_match('/^(?:===?)?\s*v?(\d+(?:\.\d+)*)$/', $spec, $matches) === 1 ? $matches[1] : null;
    }

    public function minor(string $name): ?string
    {
        $spec = $this->found[self::normalize($name)]['spec'] ?? '';

        return preg_match('/^(?:===?|~=|\^|~)?\s*v?(\d+\.\d+)(?:\.(?:\d+|\*))?$/', $spec, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param  string|list<string>  $spec
     */
    private static function tableSpec(string|array $spec): string
    {
        if (!is_string($spec)) {
            return '';
        }

        if (str_starts_with($spec, '{')) {
            return preg_match('/\bversion\s*=\s*["\']([^"\']*)["\']/', $spec, $matches) === 1 ? trim($matches[1]) : '';
        }

        return trim($spec) === '*' ? '' : trim($spec);
    }
}
