<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeLifecycle;
use App\Services\Teams\RouteKeys;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Collection;

final readonly class ServerRuntimes
{
    private const EXACT = '/^(?:==?\s*)?v?\d+\.\d+\.\d+$/';

    public function __construct(private Catalog $catalog, private RuntimeLifecycle $lifecycle, private TeamAccess $access) {}

    /**
     * @return list<array{runtime: string, name: string, version: string, label: string, status: array{label: string, tone: string}, newest: ?string, eol: bool, applications: list<array{id: string, name: string, href: string, pinned: ?string, deployable: bool}>}>
     */
    public function rows(Server $server, User $user): array
    {
        $groups = [];

        foreach ($this->access->applicationsOn($user, $server)->orderBy('name')->get() as $application) {
            foreach ($application->runtimeVersions() as $runtime => $version) {
                $groups["{$runtime}@{$version}"] ??= ['runtime' => $runtime, 'version' => $version, 'applications' => []];
                $groups["{$runtime}@{$version}"]['applications'][] = $application;
            }
        }

        usort($groups, fn(array $a, array $b): int => strcmp($this->catalog->label($a['runtime']), $this->catalog->label($b['runtime'])) ?: version_compare($b['version'], $a['version']));

        return array_map(function (array $group) use ($user): array {
            $status = $this->lifecycle->status($group['runtime'], $group['version']);

            return [
                'runtime' => $group['runtime'],
                'name' => $this->catalog->label($group['runtime']),
                'version' => $group['version'],
                'label' => $this->catalog->label($group['runtime']) . ' ' . $group['version'],
                'status' => ['label' => $status->label(), 'tone' => $status->tone()],
                'newest' => $this->newestPatch($group['runtime'], $group['version']),
                'eol' => $status->isEol(),
                'applications' => array_map(fn(Application $application): array => [
                    'id' => $application->id,
                    'name' => $application->name,
                    'href' => route('servers.applications.show', [app(RouteKeys::class)->server($application->server_id), $application]),
                    'pinned' => $this->exactPin($application, $group['runtime']),
                    'deployable' => $user->can('deploy', $application),
                ], $group['applications']),
            ];
        }, array_values($groups));
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

    /**
     * @return Collection<int, Application>
     */
    public function movable(Server $server, User $user, string $runtime, string $version): Collection
    {
        return $this->access->applicationsOn($user, $server)->get()
            ->filter(fn(Application $application): bool => ($application->runtimeVersions()[$runtime] ?? null) === $version
                && $this->exactPin($application, $runtime) === null
                && $user->can('deploy', $application))
            ->values();
    }
}
