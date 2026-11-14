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

        foreach (preg_split('/\r\n|\n|\r/', (string) $facts->file('requirements.txt')) ?: [] as $line) {
            $line = (string) preg_replace('/(^|\s)#.*$/', '', $line);

            if (preg_match(self::REQUIREMENT, $line, $matches) === 1) {
                $found[self::normalize($matches[1])] ??= ['file' => 'requirements.txt', 'spec' => trim($matches[2])];
            }
        }

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
