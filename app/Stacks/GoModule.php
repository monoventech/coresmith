<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final readonly class GoModule
{
    /**
     * @param  array<string, string>  $requires
     * @param  list<string>  $tools
     */
    public function __construct(public string $path, public array $requires = [], public array $tools = []) {}

    public static function from(RepositoryFacts $facts): ?self
    {
        $mod = $facts->file('go.mod');

        if ($mod === null) {
            return null;
        }

        $path = preg_match('/^\s*module\s+"?([^\s"]+)"?/m', $mod, $matches) === 1 ? $matches[1] : '';
        $requires = [];
        $tools = [];
        $block = null;

        foreach (preg_split('/\r\n|\n|\r/', $mod) ?: [] as $line) {
            $line = trim((string) preg_replace('~//.*$~', '', $line));

            if ($line === '') {
                continue;
            }

            if ($block !== null) {
                $block = $line === ')' ? null : self::entry($block, $line, $requires, $tools);

                continue;
            }

            if (preg_match('/^(require|tool)\s*\($/', $line, $opened) === 1) {
                $block = $opened[1];
            } elseif (preg_match('/^(require|tool)\s+(.+)$/', $line, $single) === 1) {
                self::entry($single[1], $single[2], $requires, $tools);
            }
        }

        return new self($path, $requires, $tools);
    }

    /**
     * @param  array<string, string>  $requires
     * @param  list<string>  $tools
     */
    private static function entry(string $kind, string $line, array &$requires, array &$tools): string
    {
        $parts = preg_split('/\s+/', $line) ?: [];

        if ($kind === 'tool' && ($parts[0] ?? '') !== '') {
            $tools[] = $parts[0];
        } elseif ($kind === 'require' && isset($parts[0], $parts[1])) {
            $requires[$parts[0]] = $parts[1];
        }

        return $kind;
    }

    public function requires(string $module): ?string
    {
        foreach ($this->requires as $name => $version) {
            if ($name === $module || preg_match('~^' . preg_quote($module, '~') . '/v\d+$~', $name) === 1) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $modules
     */
    public function requiresAny(array $modules): ?string
    {
        foreach ($modules as $module) {
            if ($this->requires($module) !== null) {
                return $module;
            }
        }

        return null;
    }

    public function hasTool(string $tool): bool
    {
        return in_array($tool, $this->tools, true);
    }

    public function name(): string
    {
        $parts = explode('/', $this->path);
        $last = (string) end($parts);

        if (preg_match('/^v\d+$/', $last) === 1 && count($parts) > 1) {
            $last = $parts[count($parts) - 2];
        }

        $name = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $last);

        return trim($name, '-') === '' ? 'app' : trim($name, '-');
    }
}
