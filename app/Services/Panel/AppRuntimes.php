<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Jobs\UpdateServerRuntime;
use App\Models\Application;
use App\Models\User;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\RuntimeLifecycle;
use App\Services\Server\ServerRuntimes;
use App\Services\Teams\RouteKeys;

final readonly class AppRuntimes
{
    public function __construct(private Catalog $catalog, private RuntimeLifecycle $lifecycle, private ServerRuntimes $runtimes) {}

    /**
     * @return list<array{runtime: string, name: string, version: string, status: array{label: string, tone: string}, source: string, newest: ?string, pinned: ?string, update: array{label: string, state: string, reason: ?string}|null, update_href: ?string}>
     */
    public function rows(Application $application, User $user): array
    {
        $versions = $application->runtimeVersions();
        $own = $application->type?->runtime($application->js_runtime);
        $state = $application->server === null ? null : UpdateServerRuntime::state($application->server);
        $updatable = $user->can('update', $application) && $user->can('deploy', $application);
        $rows = [];

        uksort($versions, fn(string $a, string $b): int => ($b === $own) <=> ($a === $own));

        foreach ($versions as $runtime => $version) {
            $status = $this->lifecycle->status($runtime, $version);
            $resolved = ((array) $application->stack)['runtimes'][$runtime] ?? [];
            $newest = $this->runtimes->newestPatch($runtime, $version);
            $pinned = $this->runtimes->exactPin($application, $runtime);
            $mine = is_array($state) && $state['runtime'] === $runtime && in_array($application->getKey(), $state['applications'] ?? [], true);

            $rows[] = [
                'runtime' => $runtime,
                'name' => $this->catalog->label($runtime),
                'version' => $version,
                'status' => ['label' => $status->label(), 'tone' => $status->tone()],
                'source' => $this->source(is_array($resolved) ? $resolved : []),
                'newest' => $newest,
                'pinned' => $pinned,
                'update' => $mine ? ['label' => $state['label'], 'state' => $state['state'], 'reason' => $state['reason'] ?? null] : null,
                'update_href' => $updatable && $newest !== null && $pinned === null
                    ? route('servers.applications.runtime.patch', [app(RouteKeys::class)->server($application->server_id), $application])
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function source(array $resolved): string
    {
        $source = $resolved['source'] ?? null;
        $detail = $resolved['detail'] ?? null;

        return match (true) {
            $source === ResolvedRuntime::FROM_PIN => 'Chosen on this page',
            !is_string($source) || $source === '' || $source === ResolvedRuntime::FROM_DEFAULT => 'The version Coresmith recommends',
            is_string($detail) && $detail !== '' => "From {$source}, which asks for {$detail}",
            default => "From {$source}",
        };
    }
}
