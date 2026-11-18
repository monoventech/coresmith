<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\Runtime\Stack;

final class DeployScript
{
    public const LIMIT = 10000;

    public const PREAMBLE = "set -e\n";

    public function __construct(private readonly DeploymentCommands $commands) {}

    public static function prepared(string $script): string
    {
        return self::PREAMBLE . $script;
    }

    public static function custom(Application $application): bool
    {
        return trim((string) $application->custom_blueprint) !== '';
    }

    public static function normalize(string $script): ?string
    {
        $script = rtrim(str_replace(["\r\n", "\r"], "\n", $script));

        return trim($script) === '' ? null : $script . "\n";
    }

    public function default(Application $application): string
    {
        $stack = $this->stack($application);

        return implode("\n", [
            ...$this->commands->dependencies($application, $stack),
            ...$this->commands->build($application, $stack),
            ...$this->commands->migrations($application, $stack),
        ]);
    }

    /**
     * @return array{script: string, custom: bool, from_deploy: bool, migrations: bool, migration_commands: list<string>}
     */
    public function present(Application $application): array
    {
        $custom = self::custom($application);

        return [
            'script' => $custom ? (string) $application->custom_blueprint : $this->default($application),
            'custom' => $custom,
            'from_deploy' => $this->lastStack($application) !== null,
            'migrations' => (bool) $application->run_migrations,
            'migration_commands' => array_values($application->type->language()->migrations($application, $this->stack($application))),
        ];
    }

    private function stack(Application $application): Stack
    {
        return Stack::fromArray($this->lastStack($application) ?? (array) $application->stack);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastStack(Application $application): ?array
    {
        $stack = $application->deployments()->whereNotNull('stack')->latest('created_at')->first()?->stack;

        return is_array($stack) && $stack !== [] ? $stack : null;
    }
}
