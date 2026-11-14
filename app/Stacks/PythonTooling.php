<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final readonly class PythonTooling
{
    public const MANAGERS = ['uv', 'poetry', 'pipenv', 'pip', 'pyproject', 'none'];

    public const SERVERS = ['gunicorn', 'uvicorn'];

    public const MIGRATIONS = ['django', 'alembic'];

    public const MODULE = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*(?::[A-Za-z_][A-Za-z0-9_]*(?:\(\))?)?\z/';

    public static function managerFor(RepositoryFacts $facts): string
    {
        return match (true) {
            $facts->exists('uv.lock') => 'uv',
            $facts->exists('poetry.lock') => 'poetry',
            $facts->exists('Pipfile.lock') => 'pipenv',
            $facts->has('requirements.txt') => 'pip',
            $facts->toml('pyproject.toml', 'tool.poetry.dependencies') !== [] || $facts->toml('pyproject.toml', 'tool.poetry') !== [] => 'poetry',
            $facts->has('Pipfile') => 'pipenv',
            $facts->toml('pyproject.toml', 'project') !== [] => 'pyproject',
            default => 'none',
        };
    }

    public function __construct(
        public string $manager,
        public string $server,
        public ?string $module,
        public bool $collectstatic = false,
        public ?string $migrate = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $manager = $data['manager'] ?? null;
        $server = $data['server'] ?? null;
        $module = $data['module'] ?? null;
        $migrate = $data['migrate'] ?? null;

        if (!in_array($manager, self::MANAGERS, true) || !in_array($server, self::SERVERS, true)) {
            return null;
        }

        if ($module !== null && (!is_string($module) || preg_match(self::MODULE, $module) !== 1)) {
            return null;
        }

        return new self($manager, $server, $module, ($data['collectstatic'] ?? false) === true, in_array($migrate, self::MIGRATIONS, true) ? $migrate : null);
    }

    /**
     * @return array{manager: string, server: string, module: ?string, collectstatic: bool, migrate: ?string}
     */
    public function toArray(): array
    {
        return [
            'manager' => $this->manager,
            'server' => $this->server,
            'module' => $this->module,
            'collectstatic' => $this->collectstatic,
            'migrate' => $this->migrate,
        ];
    }
}
