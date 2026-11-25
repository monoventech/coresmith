<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeLifecycle;
use App\Services\Teams\RouteKeys;
use App\Services\Teams\TeamAccess;

final readonly class ServerRuntimes
{
    private const EXACT = '/^(?:==?\s*)?v?\d+\.\d+\.\d+$/';

    public function __construct(private Catalog $catalog, private RuntimeLifecycle $lifecycle, private TeamAccess $access) {}

    /**
     * @return list<array{runtime: string, version: string, label: string, status: array{label: string, tone: string}, apps: list<array{name: string, href: string}>, hidden: int, kept: list<string>, remove_href: ?string}>
     */
    public function inventory(Server $server, User $user): array
    {
        $usage = $this->usage($server);
        $installed = (array) $server->installed_runtimes;
        $visible = $this->access->applicationsOn($user, $server)->pluck('id')->map(fn(mixed $id): string => (string) $id)->all();
        $removable = $user->can('update', $server);
        $keys = array_keys($usage);

        foreach ($installed as $runtime => $versions) {
            foreach ((array) $versions as $version) {
                $keys[] = "{$runtime}@{$version}";
            }
        }

        $keys = array_values(array_unique($keys));
        usort($keys, function (string $a, string $b): int {
            [$runtimeA, $versionA] = explode('@', $a, 2);
            [$runtimeB, $versionB] = explode('@', $b, 2);

            return strcmp($this->catalog->label($runtimeA), $this->catalog->label($runtimeB)) ?: version_compare($versionB, $versionA);
        });

        return array_map(function (string $key) use ($server, $usage, $installed, $visible, $removable): array {
            [$runtime, $version] = explode('@', $key, 2);
            $runs = collect($usage[$key]['runs'] ?? []);
            $kept = collect($usage[$key]['kept'] ?? []);
            $seen = fn(Application $application): bool => in_array((string) $application->getKey(), $visible, true);
            $status = $this->lifecycle->status($runtime, $version);
            $onDisk = in_array($version, array_map('strval', (array) ($installed[$runtime] ?? [])), true);

            return [
                'runtime' => $runtime,
                'version' => $version,
                'label' => $this->catalog->label($runtime) . ' ' . $version,
                'status' => ['label' => $status->label(), 'tone' => $status->tone()],
                'apps' => $runs->filter($seen)->map(fn(Application $application): array => [
                    'name' => $application->name,
                    'href' => route('servers.applications.show', [app(RouteKeys::class)->server($application->server_id), $application]),
                ])->values()->all(),
                'hidden' => $runs->reject($seen)->count(),
                'kept' => $kept->filter($seen)->map(fn(Application $application): string => $application->name)->values()->all(),
                'remove_href' => $removable && $onDisk && $runs->isEmpty() && $kept->isEmpty() ? route('servers.runtimes.destroy', [$server, $runtime, $version]) : null,
            ];
        }, $keys);
    }

    /**
     * @return array<string, array{runs: list<Application>, kept: list<Application>}>
     */
    public function usage(Server $server): array
    {
        $usage = [];

        foreach ($server->applications()->orderBy('name')->get() as $application) {
            $current = $application->runtimeVersions();

            foreach ($current as $runtime => $version) {
                $usage["{$runtime}@{$version}"]['runs'][] = $application;
            }

            $releases = $application->deployments()
                ->where('status', DeploymentStatus::Succeeded)
                ->whereNotNull('release')
                ->latest('finished_at')
                ->latest('id')
                ->limit(ReleasePaths::KEEP_RELEASES)
                ->get(['id', 'stack']);

            foreach ($releases as $release) {
                foreach ((array) (((array) $release->stack)['runtimes'] ?? []) as $runtime => $resolved) {
                    $version = is_array($resolved) ? ($resolved['version'] ?? null) : null;

                    if (!is_string($version) || ($current[$runtime] ?? null) === $version || in_array($application, $usage["{$runtime}@{$version}"]['kept'] ?? [], true)) {
                        continue;
                    }

                    $usage["{$runtime}@{$version}"]['kept'][] = $application;
                }
            }
        }

        return array_map(fn(array $use): array => ['runs' => $use['runs'] ?? [], 'kept' => $use['kept'] ?? []], $usage);
    }

    public function newestPatch(string $runtime, string $version): ?string
    {
        $line = $this->catalog->lineOf($runtime, $version);
        $latest = $line === null ? null : ($this->catalog->line($runtime, $line)['latest'] ?? null);

        return $latest !== null && version_compare($latest, $version, '>') ? $latest : null;
    }

    public function exactPin(Application $application, string $runtime): ?string
    {
        $resolved = ((array) $application->stack)['runtimes'][$runtime] ?? null;

        if (!is_array($resolved) || !is_string($resolved['detail'] ?? null) || in_array($resolved['source'] ?? null, ['pin', 'default'], true)) {
            return null;
        }

        return preg_match(self::EXACT, trim($resolved['detail'])) === 1 ? (string) ($resolved['source'] ?? '') : null;
    }
}
